<?php

namespace App\Console\Commands;

use App\Captcha\CaptchaService;
use App\Captcha\CaptchaServiceInterface;
use App\Exceptions\PermanentException;
use App\Http\Endpoints;
use App\Http\MidpassApiClient;
use App\Http\MidpassApiClientInterface;
use App\Notifier\NearFrontAlerter;
use App\Notifier\NotifierInterface;
use App\Notifier\TelegramNotifier;
use App\Queue\AppointmentProcessor;
use App\Queue\PreCheck;
use App\ResourceManager;
use App\State\ProcessLock;
use App\State\StateStore;
use gugglegum\RetryHelper\RetryHelper;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\PsrHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orchestrates one confirm-queue run: pre-check, captcha solving, and (via the
 * API client) login/fetch/confirm/logout, plus state persistence and Telegram
 * notifications. All HTTP lives in {@see MidpassApiClient}; the pure decision
 * logic lives in App\Queue\* and App\State\*.
 */
class ConfirmQueueCommand extends AbstractCommand
{
    private const DEFAULT_BASE_URL = 'https://q.midpass.ru';

    private \Luracast\Config\Config $config;
    private ConsoleOutput $output;
    private LoggerInterface $logger;
    private MidpassApiClientInterface $api;
    private CaptchaServiceInterface $captcha;
    private AppointmentProcessor $processor;
    private NearFrontAlerter $alerter;
    private StateStore $stateStore;
    private ProcessLock $lock;
    private bool $ownsLock = false;
    private array $state;
    private NotifierInterface $notifier;

    public function __construct(ResourceManager $resourceManager)
    {
        parent::__construct($resourceManager);

        $this->config = $this->resourceManager->getConfig();
        $this->logger = $this->buildLogger();

        // API endpoints + HTTP client (host configurable via QUEUE_BASE_URL)
        $baseUrl = (string) $this->config->get('queue.baseUrl');
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }
        $this->api = new MidpassApiClient(new Endpoints($baseUrl), $this->logger);

        // Captcha solving (rucaptcha when a key is set, else built-in)
        $this->captcha = new CaptchaService(
            api: $this->api,
            rucaptchaApiKey: (string) $this->config->get('queue.rucaptchaApiKey'),
            rucaptchaEndpoint: (string) $this->config->get('queue.rucaptchaEndpoint'),
            logger: $this->logger,
        );

        // Telegram notifier (no-op if token/chat id not configured)
        $this->notifier = new TelegramNotifier(
            botToken: (string) $this->config->get('queue.telegramBotToken'),
            chatId: (string) $this->config->get('queue.telegramChatId'),
            logger: $this->logger,
        );

        // Per-appointment decision/action (confirm/hold/probe)
        $this->processor = new AppointmentProcessor(
            api: $this->api,
            captcha: $this->captcha,
            notifier: $this->notifier,
            logger: $this->logger,
            guardPlace: (int) $this->config->get('queue.autoConfirmGuardPlace'),
            includeName: (bool) $this->config->get('queue.telegramIncludeName'),
        );

        // Near-front heads-up alert (with de-dup via state)
        $this->alerter = new NearFrontAlerter(
            notifier: $this->notifier,
            logger: $this->logger,
            threshold: (int) $this->config->get('queue.telegramAlertPlaceThreshold'),
            dumpRaw: (bool) $this->config->get('queue.telegramDumpRaw'),
            includeName: (bool) $this->config->get('queue.telegramIncludeName'),
        );

        // Ensure the temp directory exists so the lock/state files can be created.
        $tempDir = PROJECT_ROOT_DIR . '/../temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        // Single-run lock so an overlapping run can't clobber state, then load.
        $this->lock = new ProcessLock($tempDir . '/confirm-queue.lock');
        $this->ownsLock = $this->lock->tryAcquire();
        $this->stateStore = new StateStore(PROJECT_ROOT_DIR . '/../temp/confirm-queue.json');
        $this->state = $this->stateStore->load();
    }

    public function __destruct()
    {
        try {
            if ($this->ownsLock) {
                $this->saveState();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save state on shutdown: ' . $e->getMessage());
        }
        $this->lock->release();
    }

    public function __invoke(): ?int
    {
        if (!$this->ownsLock) {
            $this->logger->warning('Another confirm-queue run holds the lock — skipping this tick');
            return 0;
        }

        try {
            $retryHelper = (new RetryHelper())
                ->setLogger($this->logger)
                ->setIsTemporaryException(function(\Throwable $e) {
                    return !$e instanceof PermanentException;
                })
                ->setOnFailure(function () {
                    if ($this->api->isAuthorized()) {
                        $this->api->logout();
                    }
                });

            // Step 0: Check if we need to do anything
            if (!$this->doNeedToConfirmAnything()) {
                $this->logger->notice("There's no need to do anything - everything already confirmed in the current 24-hour interval");
                return 0;
            }

            // Step 1: Solve CAPTCHA + login. Wrapped in the workflow retry so a
            // wrong captcha (temporary) is retried with a freshly solved one.
            $retryHelper->execute(function () {
                $this->logger->notice("Start login");
                $captchaCode = $this->captcha->solve('login');
                $this->logger->info("Performing login");
                $this->api->login(
                    (string) $this->config->get('queue.email'),
                    (string) $this->config->get('queue.password'),
                    $captchaCode,
                    (string) $this->config->get('queue.serviceProviderId'),
                );
                $this->logger->notice("Successful login");
            }, 5);

            // Step 2: Get a list of waiting appointments. No captcha here, so the
            // API client's own transient-error retry is sufficient.
            $this->logger->info("Load list of waiting appointments");
            $appointments = $this->api->fetchWaitingAppointments();

            // Step 3: Confirm all waiting appointments that can be confirmed
            if (count($appointments)) {
                $this->logAppointmentsTable($appointments);

                // Heads-up notification when we are near the front of the queue
                // (an appointment slot offer is likely imminent). Done before the
                // confirm loop so the alert fires even if confirm is unavailable.
                foreach ($appointments as $appointment) {
                    $this->alerter->maybeAlert($appointment, $this->state);
                }

                // Preserve existing state and only update entries for the
                // appointments we actually saw this round.
                $state = $this->state;
                foreach ($appointments as $appointment) {
                    // The HTTP confirm + captcha is the only retried side effect.
                    $retryHelper->execute(function () use ($appointment, &$state) {
                        $this->processor->process($appointment, $state);
                    }, 5);
                    // Persist after the appointment is fully processed — OUTSIDE the
                    // retry, so a save failure can never trigger a duplicate confirm.
                    $this->state = $state;
                    try {
                        $this->saveState();
                    } catch (\Throwable $e) {
                        $this->logger->error(sprintf(
                            'Failed to persist state after appointment id %s: %s',
                            (string) ($appointment['WaitingAppointmentId'] ?? '?'),
                            $e->getMessage()
                        ));
                        throw $e;
                    }
                }
                $this->state = $state;
            } else {
                $this->logger->info("No waiting appointments");
            }

            // Step 4: Logout (best-effort; client never throws here)
            $this->logger->info("Logout");
            $this->api->logout();

            return 0;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->debug("Exception of class " . get_class($e) . " was thrown in file {$e->getFile()} at line {$e->getLine()}\nDebug backtrace:\n{$e->getTraceAsString()}");
            $this->notifier->send("⚠️ Ошибка midpass-бота:\n" . $e->getMessage());
            return 255;
        }
    }

    private function buildLogger(): LoggerInterface
    {
        $logger = new Logger('app');

        $this->output = new ConsoleOutput();
        $styles = ['warning' => 'red', 'notice' => 'yellow', 'info' => 'default', 'debug' => 'gray'];
        foreach ($styles as $level => $style) {
            $this->output->getFormatter()->setStyle($level, new OutputFormatterStyle($style));
        }
        $this->output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $consoleLogger = new ConsoleLogger(output: $this->output, formatLevelMap: [
            LogLevel::WARNING => 'warning',
            LogLevel::NOTICE => 'notice',
            LogLevel::DEBUG => 'debug',
        ]);
        $logger->pushHandler(new PsrHandler($consoleLogger));

        $logFile = PROJECT_ROOT_DIR . '/../logs/confirm-queue.log';
        $streamHandler = new StreamHandler($logFile, Logger::DEBUG);
        $streamHandler->setFormatter(new LineFormatter("%datetime% %message%\n", "Y-m-d H:i:s", true, true));
        $logger->pushHandler($streamHandler);

        return $logger;
    }

    /** @param list<array<string,mixed>> $appointments */
    private function logAppointmentsTable(array $appointments): void
    {
        $buffer = new BufferedOutput();
        $table = new Table($buffer);
        $table->setHeaders(['Id', 'In queue since', 'Service name', 'Can confirm', 'Place in queue']);
        foreach ($appointments as $appointment) {
            $table->addRow([
                $appointment['WaitingAppointmentId'],
                strtok((string) $appointment['ScheduledDateTimeString'], ' '),
                $appointment['ServiceName'],
                $appointment['CanConfirm'] ? 'Yes' : 'No',
                $appointment['PlaceInQueue'],
            ]);
        }
        $table->render();
        $this->logger->info("Waiting appointments: " . count($appointments) . "\n" . rtrim($buffer->fetch()));
    }

    private function saveState(): void
    {
        $this->stateStore->save($this->state);
    }

    /**
     * Local pre-check: decide whether to bother logging in this cron tick. Pure
     * decision in {@see PreCheck}; here we only translate it into log lines.
     */
    private function doNeedToConfirmAnything(): bool
    {
        $intervalRaw = $this->config->get('queue.renewalIntervalHours');
        $intervalHours = $intervalRaw === null ? null : (int) $intervalRaw;
        $maxSkipHours = (int) $this->config->get('queue.maxSkipAgeHours');
        $cooldownMinutes = (int) $this->config->get('queue.negativeProbeCooldownMinutes');

        $result = PreCheck::evaluate(
            array_values($this->state['WaitingAppointments']['LastConfirmation'] ?? []),
            array_values($this->state['WaitingAppointments']['LastNegativeProbe'] ?? []),
            new \DateTimeImmutable('now'),
            $intervalHours,
            $maxSkipHours,
            $cooldownMinutes,
        );

        switch ($result['code']) {
            case PreCheck::CEILING:
                $this->logger->warning(sprintf(
                    'Pre-check ceiling hit (%.1fh since last confirm ≥ %dh) — forcing attempt',
                    $result['hoursSinceConfirm'],
                    $maxSkipHours
                ));
                break;
            case PreCheck::FUTURE_TIMESTAMP:
                $this->logger->warning('Pre-check: last confirmation timestamp is in the future (clock skew or edited state) — forcing attempt');
                break;
            case PreCheck::WITHIN_INTERVAL:
                $this->logger->notice(sprintf(
                    "There's no need to do anything yet: %.1fh since last confirm, renewal interval is %dh",
                    $result['hoursSinceConfirm'],
                    $intervalHours
                ));
                break;
            case PreCheck::COOLDOWN:
                $this->logger->notice(sprintf(
                    "There's no need to do anything yet: server returned canConfirm:false %dmin ago, cooldown is %dmin",
                    $result['minutesSinceNegative'],
                    $cooldownMinutes
                ));
                break;
        }

        return $result['proceed'];
    }
}
