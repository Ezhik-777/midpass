<?php

declare(strict_types=1);

namespace App\Queue;

use App\Captcha\CaptchaServiceInterface;
use App\Http\MidpassApiClientInterface;
use App\Notifier\NotifierInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides and applies the per-appointment action — confirm, hold (manual), or
 * record a negative probe — mutating the run state. All collaborators are
 * injected so this (the riskiest behaviour) is unit-testable without HTTP.
 */
final class AppointmentProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private MidpassApiClientInterface $api,
        private CaptchaServiceInterface $captcha,
        private NotifierInterface $notifier,
        ?LoggerInterface $logger = null,
        private int $guardPlace = 3,
        private bool $includeName = true,
        private bool $dumpRaw = true,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(array $appointment, array &$state): void
    {
        $id = $appointment['WaitingAppointmentId'] ?? null;
        if ($id === null || $id === '') {
            $this->logger->warning('Appointment with missing id — skipping');
            return;
        }
        $id = (string) $id;
        $nowIso = (new \DateTime('now'))->format('c');

        // Order: null-id (above) -> if confirmable, hold-guard then confirm ->
        // otherwise record a negative probe. The hold guard only gates the
        // auto-confirm path; when the server can't confirm there is nothing to
        // suppress, so a negative probe is the correct outcome.
        if ($appointment['CanConfirm']) {
            if ($this->shouldHold($appointment)) {
                // Near the front (or unknown place): do NOT auto-confirm, to avoid
                // silently accepting an offered slot.
                $this->logger->warning(sprintf(
                    'HOLD: "%s" (id %s) place "%s" within guard — НЕ подтверждаю автоматически, требуется ручное подтверждение',
                    (string) $appointment['ServiceName'],
                    $id,
                    (string) $appointment['PlaceInQueue']
                ));
                // Real "you can act now" moment — server allows confirm at the
                // front. Send a definite alert so the user confirms manually.
                $this->sendHoldAlert($appointment, $state, $id);
                return;
            }

            $this->logger->notice("Confirm appointment \"{$appointment['ServiceName']}\" (id {$id})");
            $confirmCaptcha = $this->captcha->solve('confirm'); // fresh captcha per confirm
            $this->api->confirmWaitingAppointment($id, $confirmCaptcha);
            $state['WaitingAppointments']['LastConfirmation'][$id] = $nowIso;
            unset($state['WaitingAppointments']['LastNegativeProbe'][$id]);
            $this->notifier->send($this->confirmationMessage($appointment));
            return;
        }

        $this->logger->notice("Skip appointment \"{$appointment['ServiceName']}\" (id {$id}) - not available yet");
        // LastConfirmation stays anchored on the last real confirmation.
        $state['WaitingAppointments']['LastNegativeProbe'][$id] = $nowIso;
    }

    /**
     * Deny-by-default guard against auto-accepting an offered slot: hold when the
     * place is at/under the guard threshold, or cannot be parsed (fail-safe).
     * guardPlace 0 disables the guard.
     */
    public function shouldHold(array $appointment): bool
    {
        if ($this->guardPlace <= 0) {
            return false;
        }
        $place = PlaceParser::parse($appointment['PlaceInQueue'] ?? null);
        return $place === null || $place <= $this->guardPlace;
    }

    /**
     * Definite alert for the real moment the server opens confirmation at the
     * front (canConfirm:true + hold). De-duplicated to once per 6h so it does
     * not spam while the window stays open. Recorded only on successful delivery.
     */
    private function sendHoldAlert(array $appointment, array &$state, string $id): void
    {
        if (!$this->notifier->isEnabled()) {
            return;
        }
        $now = new \DateTime('now');
        $last = $state['Notifications']['LastHoldAlert'][$id] ?? null;
        if ($last !== null && isset($last['ts'])) {
            try {
                $ageHours = ($now->getTimestamp() - (new \DateTime($last['ts']))->getTimestamp()) / 3600;
            } catch (\Throwable) {
                $ageHours = PHP_INT_MAX;
            }
            if ($ageHours < 6) {
                return;
            }
        }

        $name = $this->includeName ? trim((string) ($appointment['FullName'] ?? '')) . "\n" : '';
        $dump = '';
        if ($this->dumpRaw && is_array($appointment['_raw'] ?? null)) {
            $json = json_encode(AppointmentRedactor::maskValuesExcept($appointment['_raw']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $dump = $json === false ? '' : "\n\nДанные заявки:\n" . $json;
        }
        $message = sprintf(
            "🟢 СЕРВЕР ОТКРЫЛ ПОДТВЕРЖДЕНИЕ — твоя очередь!\n%s%s\nМесто: %s\n\n"
            . "Зайди на q.midpass.ru, проверь предложенную дату и подтверди ИЛИ отклони ВРУЧНУЮ "
            . "(обычно на решение даётся около 24 часов).%s",
            $name,
            (string) ($appointment['ServiceName'] ?? ''),
            (string) ($appointment['PlaceInQueue'] ?? ''),
            $dump
        );

        if ($this->notifier->send($message)) {
            $state['Notifications']['LastHoldAlert'][$id] = ['ts' => $now->format('c')];
        }
    }

    private function confirmationMessage(array $appointment): string
    {
        $lines = ['✅ Очередь подтверждена'];
        if ($this->includeName) {
            $lines[] = trim((string) $appointment['FullName']);
        }
        $lines[] = (string) $appointment['ServiceName'];
        $lines[] = 'Место в очереди: ' . (string) $appointment['PlaceInQueue'];
        return implode("\n", $lines);
    }
}
