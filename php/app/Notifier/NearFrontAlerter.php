<?php

declare(strict_types=1);

namespace App\Notifier;

use App\Queue\AppointmentRedactor;
use App\Queue\PlaceParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides and sends the "near the front of the queue" Telegram heads-up, with
 * de-duplication via the run state. Collaborators/config are injected so the
 * gating, redaction and de-dup are unit-testable without the command.
 */
final class NearFrontAlerter
{
    private LoggerInterface $logger;

    public function __construct(
        private NotifierInterface $notifier,
        ?LoggerInterface $logger = null,
        private int $threshold = 5,
        private bool $dumpRaw = true,
        private bool $includeName = true,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function maybeAlert(array $appointment, array &$state): void
    {
        if (!$this->notifier->isEnabled()) {
            return;
        }
        $rawId = $appointment['WaitingAppointmentId'] ?? null;
        if ($rawId === null || $rawId === '') {
            $this->logger->debug('Near-front alert skipped: appointment has no id');
            return;
        }
        $placeRaw = $appointment['PlaceInQueue'] ?? null;
        $place = PlaceParser::parse($placeRaw);
        // Alert at/under threshold, or when the place can't be parsed (anomaly).
        if ($place !== null && $place > $this->threshold) {
            return;
        }

        $id = (string) $rawId;
        $placeKey = $place ?? -1;
        $now = new \DateTime('now');
        $last = $state['Notifications']['LastPlaceAlert'][$id] ?? null;
        if ($last !== null && ($last['place'] ?? null) === $placeKey) {
            $ageHours = $this->ageHours($last['ts'] ?? null, $now);
            if ($ageHours < 12) {
                return;
            }
        }

        $name = $this->includeName ? trim((string) ($appointment['FullName'] ?? '')) . "\n" : '';
        $delivered = $this->notifier->send(sprintf(
            "🔔 ВНИМАНИЕ: ты почти у начала очереди!\n%s%s\nМесто в очереди: %s\n\n"
            . "Возможно, скоро предложат дату записи — зайди на q.midpass.ru и проверь вручную.\n\n"
            . "Данные заявки:\n%s",
            $name,
            (string) ($appointment['ServiceName'] ?? ''),
            (string) $placeRaw,
            $this->buildRawDump($appointment['_raw'] ?? null)
        ));

        // Only de-dup once the alert was actually delivered — a failed send is
        // retried on the next tick instead of being silently swallowed.
        if ($delivered) {
            $state['Notifications']['LastPlaceAlert'][$id] = ['place' => $placeKey, 'ts' => $now->format('c')];
        }
    }

    /** Age in hours of a stored ISO timestamp; treats a missing/invalid one as stale. */
    private function ageHours(?string $iso, \DateTimeInterface $now): float
    {
        if ($iso === null) {
            return PHP_INT_MAX;
        }
        try {
            $then = new \DateTime($iso);
        } catch (\Throwable) {
            return PHP_INT_MAX; // corrupted state value — treat as stale
        }
        return ($now->getTimestamp() - $then->getTimestamp()) / 3600;
    }

    private function buildRawDump(?array $raw): string
    {
        if (!$this->dumpRaw || $raw === null) {
            return '(скрыто; TELEGRAM_DUMP_RAW=0)';
        }
        $json = json_encode(
            AppointmentRedactor::maskValuesExcept($raw),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return $json === false ? '(не удалось сериализовать данные)' : $json;
    }
}
