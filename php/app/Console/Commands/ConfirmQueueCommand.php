<?php

namespace App\Console\Commands;

use App\CaptchaSolver\CaptchaSolverInterface;
use App\CaptchaSolver\CaptchaSolverQMidPass;
use App\CaptchaSolver\CaptchaSolverRuCaptcha;
use App\Exceptions\PermanentException;
use App\ResourceManager;
use Carbon\Carbon;
use gugglegum\RetryHelper\RetryHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\PsrHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConfirmQueueCommand extends AbstractCommand
{
    private \Luracast\Config\Config $config;
    private \GuzzleHttp\Cookie\CookieJar $cookieJar;
    private \GuzzleHttp\Client $guzzle;
    private ConsoleOutput $output;
    private LoggerInterface $logger;
    private RetryHelper $guzzleRetryHelper;
    private bool $authorized = false;
    private array $state;

    private array $httpHeaders = [
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Encoding' => 'gzip, deflate',
        'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
        'Cache-Control' => 'no-cache',
        'Dnt' => '1',
        'Origin' => 'https://q.midpass.ru',
        'Pragma' => 'no-cache',
        'Sec-Ch-Ua' => '"Not A(Brand";v="99", "Google Chrome";v="121", "Chromium";v="121"',
        'Sec-Ch-Ua-Mobile' => '?0',
        'Sec-Ch-Ua-Platform' => '"Windows"',
        'Sec-Fetch-Dest' => 'empty',
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Site' => 'same-origin',
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'X-Requested-With' => 'XMLHttpRequest',
    ];

    public function __construct(ResourceManager $resourceManager)
    {
        parent::__construct($resourceManager);

        // Config
        $this->config = $this->resourceManager->getConfig();

        // Logger
        $this->logger = new Logger('app');

        // Logger: Console
        $this->output = new ConsoleOutput();
        $styles = [
            'warning' => 'red',
            'notice' => 'yellow',
            'info' => 'default',
            'debug' => 'gray',
        ];
        foreach ($styles as $level => $style) {
            $this->output->getFormatter()->setStyle($level, new OutputFormatterStyle($style));
        }
        $this->output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        $consoleLogger = new ConsoleLogger(output: $this->output, formatLevelMap: [
            LogLevel::WARNING => 'warning',
            LogLevel::NOTICE => 'notice',
            LogLevel::DEBUG => 'debug',
        ]);
        $this->logger->pushHandler(new PsrHandler($consoleLogger));

        // Logger: File
        $logFile = PROJECT_ROOT_DIR . '/../logs/confirm-queue.log';
        $streamHandler = new StreamHandler($logFile, Logger::DEBUG);
        $outputFormat = "%datetime% %message%\n";
        $streamHandler->setFormatter(new LineFormatter($outputFormat, "Y-m-d H:i:s", true, true));
        $this->logger->pushHandler($streamHandler);

        // GuzzleHttp client
        $this->cookieJar = new \GuzzleHttp\Cookie\CookieJar;
        $this->guzzle = new Client([
            'allow_redirects' => false,
            'cookies' => $this->cookieJar,
        ]);
        $this->guzzleRetryHelper = (new RetryHelper())
            ->setIsTemporaryException(function(\Throwable $e) {
                return ($e instanceof \GuzzleHttp\Exception\ConnectException || $e instanceof \GuzzleHttp\Exception\ServerException);
            })
            ->setLogger($this->logger);

        // State file
        $this->loadState();
    }

    public function __destruct()
    {
        $this->saveState();
    }

    public function __invoke(): ?int
    {
        try {
            $retryHelper = (new RetryHelper())
                ->setLogger($this->logger)
                ->setIsTemporaryException(function(\Throwable $e) {
                    return !$e instanceof \App\Exceptions\PermanentException;
                })
                ->setOnFailure(function () {
                    if ($this->authorized) {
                        $this->logout();
                    }
                });

            // Step 0: Check if we need to do anything
            if (!$this->doNeedToConfirmAnything()) {
                $this->logger->notice("There's no need to do anything - everything already confirmed in the current 24-hour interval");
                return 0;
            }

            // Step 1: Solve CAPTCHA, Login
            $retryHelper->execute(function () {
                $this->logger->notice("Start login");
                $captchaCode = $this->loadAndSolveCaptcha('login');
                $this->logger->info("Login as {$this->config->get('queue.email')}");
                $this->login($this->config->get('queue.email'), $this->config->get('queue.password'), $captchaCode);
                $this->logger->notice("Successful login");
            }, 5);

            // Step 2: Get a list of waiting appointments
            $appointments = $retryHelper->execute(function () {
                // Loading list of appointments
                $this->logger->info("Load list of waiting appointments");
                return $this->fetchWaitingAppointments();
            }, 5);

            // Step 3: Confirm all waiting appointments that can be confirmed
            if (count($appointments)) {
                // Draw table with appointments
                $buffer = new BufferedOutput();
                $table = new Table($buffer);
                $table->setHeaders(['Full name', 'In queue since', 'Service name', 'Can confirm', 'Place in queue']);
                foreach ($appointments as $appointment) {
                    $table->addRow([
                        $appointment['FullName'],
                        strtok($appointment['ScheduledDateTimeString'], ' '),
                        $appointment['ServiceName'],
                        $appointment['CanConfirm'] ? 'Yes' : 'No',
                        $appointment['PlaceInQueue'],
                    ]);
                }
                $table->render();
                $this->logger->info("Waiting appointments: " . count($appointments) . "\n" . rtrim($buffer->fetch()));

                // Preserve existing state and only update entries for the
                // appointments we actually saw this round. This way state
                // stays meaningful across runs and across schema additions.
                $state = $this->state;
                foreach ($appointments as $appointment) {
                    $retryHelper->execute(function () use ($appointment, &$state) {
                        $id = $appointment['WaitingAppointmentId'];
                        $nowIso = (new \DateTime('now'))->format('c');
                        if ($appointment['CanConfirm']) {
                            $this->logger->notice("Confirm appointment \"{$appointment['ServiceName']}\" for \"" . trim($appointment['FullName']) . "\"");
                            // New API requires a fresh captcha per confirm action
                            $confirmCaptcha = $this->loadAndSolveCaptcha('confirm');
                            $this->confirmAppointment($id, $confirmCaptcha);
                            $state['WaitingAppointments']['LastConfirmation'][$id] = $nowIso;
                            // On success the previous negative probe (if any)
                            // becomes irrelevant.
                            unset($state['WaitingAppointments']['LastNegativeProbe'][$id]);
                        } else {
                            $this->logger->notice("Skip appointment \"{$appointment['ServiceName']}\" for \"" . trim($appointment['FullName']) . "\" - not available yet");
                            // Remember the negative probe so the pre-check
                            // can back off until the cooldown elapses.
                            // LastConfirmation is intentionally NOT touched
                            // here — it must stay anchored on the last real
                            // confirmation, not on observation events.
                            $state['WaitingAppointments']['LastNegativeProbe'][$id] = $nowIso;
                        }
                    }, 5);
                }
                $this->state = $state;
            } else {
                $this->logger->info("No waiting appointments");
            }

            // Step 4: Logout
            $retryHelper->execute(function () {
                $this->logger->info("Logout");
                $this->logout();
            }, 5);

            return 0;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->debug("Exception of class " . get_class($e) . " was thrown in file {$e->getFile()} at line {$e->getLine()}\nDebug backtrace:\n{$e->getTraceAsString()}");
            return 255;
        }
    }

    /**
     * @return string
     * @throws \Throwable
     */
    private function loadCaptcha(): string
    {
        return $this->guzzleRetryHelper->execute(function() {
            $tick = floor(microtime(true) * 1000);
            $response = $this->httpGet('https://q.midpass.ru/api/Account/CaptchaImage?' . $tick, [
                \GuzzleHttp\RequestOptions::HEADERS => [
                    'Referer' => 'https://q.midpass.ru/ru/account/PrivatePersonLogOn',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                ],
            ]);
            $captchaFilePath = PROJECT_ROOT_DIR . '/../temp/captcha-' . $tick . '.png';
            file_put_contents($captchaFilePath, $response->getBody()->getContents());
            return $captchaFilePath;
        }, 5);
    }

    private function loadAndSolveCaptcha(string $purpose = 'login'): string
    {
        $this->logger->info("Load CAPTCHA for {$purpose}");
        $captchaFilePath = $this->loadCaptcha();
        $this->logger->debug("Solve CAPTCHA");
        $captchaSolver = $this->makeCaptchaSolver();
        $captchaImage = imagecreatefrompng($captchaFilePath);
        if ($captchaImage === false) {
            @unlink($captchaFilePath);
            throw new \Exception("Captcha endpoint returned non-PNG data");
        }
        $captchaCode = $captchaSolver->solveCaptcha($captchaImage);
        @unlink($captchaFilePath);
        if ($captchaCode === null) {
            throw new \Exception("Unable to solve CAPTCHA");
        }
        $this->logger->debug("CAPTCHA: " . $captchaCode . " (accuracy: " . number_format(round($captchaSolver->accuracy, 1), 1) . '%)');
        if ($captchaSolver->accuracy < 70) {
            throw new \Exception("CAPTCHA solution accuracy is too low - reload");
        }
        return $captchaCode;
    }

    private function makeCaptchaSolver(): CaptchaSolverInterface
    {
        $apiKey = (string) $this->config->get('queue.rucaptchaApiKey');
        if ($apiKey !== '') {
            $this->logger->debug('Using rucaptcha solver');
            return new CaptchaSolverRuCaptcha(
                apiKey: $apiKey,
                endpoint: (string) $this->config->get('queue.rucaptchaEndpoint'),
                logger: $this->logger,
            );
        }
        $this->logger->debug('Using built-in CaptchaSolverQMidPass');
        return new CaptchaSolverQMidPass();
    }

    /**
     * @param string $email
     * @param string $password
     * @param string $captchaCode
     * @return void
     * @throws GuzzleException
     */
    private function login(string $email, string $password, string $captchaCode): void
    {
        $payload = json_encode([
            'serviceProviderId' => $this->config->get('queue.serviceProviderId'),
            'email' => $email,
            'password' => $password,
            'captcha' => $captchaCode,
        ], JSON_UNESCAPED_UNICODE);

        $response = $this->httpPost('https://q.midpass.ru/api/Account/DoPrivatePersonLogOn', $payload, [
            \GuzzleHttp\RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Referer' => 'https://q.midpass.ru/ru/account/PrivatePersonLogOn',
                'Content-Length' => strlen($payload),
            ],
        ]);

        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \Exception("Login: unexpected non-JSON response: " . substr($content, 0, 200));
        }

        if (($data['result'] ?? false) !== true) {
            $errorKey = (string) ($data['text'] ?? 'Unknown error');
            // CAPTCHA-related errors -> temporary (retry with new captcha)
            $captchaErrorKeys = [
                'Controller.Account.Validation.CaptchaError',
                'Controller.Account.Validation.CaptchaIsRequired',
                'Controller.Account.Validation.WrongCaptcha',
            ];
            if (!empty($data['refreshCaptcha']) || in_array($errorKey, $captchaErrorKeys, true) || stripos($errorKey, 'captcha') !== false) {
                throw new \Exception("CAPTCHA error: " . $errorKey);
            }
            // Anything else (bad credentials, bad service provider, banned) -> permanent
            throw new PermanentException("Login error: " . $errorKey);
        }

        $sessionCookie = $this->cookieJar->getCookieByName('.AspNetCore.Session');
        if (!$sessionCookie || $sessionCookie->getValue() === '') {
            throw new \Exception("Failed authorization: no \".AspNetCore.Session\" cookie came from the server");
        }

        $this->authorized = true;
    }

    private function logout(): void
    {
        try {
            $this->httpPost('https://q.midpass.ru/api/Account/Logoff', '', [
                \GuzzleHttp\RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Referer' => 'https://q.midpass.ru/ru/Appointments/WaitingList',
                    'Content-Length' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Logout failed: ' . $e->getMessage());
        }
        $this->authorized = false;
    }

    private function fetchWaitingAppointments(): array
    {
        $query = http_build_query([
            'pageIndex' => 0,
            'pageSize' => 10,
        ]);
        return $this->guzzleRetryHelper->execute(function() use ($query) {
            $response = $this->httpGet('https://q.midpass.ru/api/Appointments/FindWaitingAppointments?' . $query, [
                \GuzzleHttp\RequestOptions::HEADERS => [
                    'Referer' => 'https://q.midpass.ru/ru/Appointments/WaitingList',
                ],
            ]);
            $content = $response->getBody()->getContents();
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \Exception("FindWaitingAppointments: unexpected response");
            }
            $itemsKey = array_key_exists('Items', $data) ? 'Items' : (array_key_exists('items', $data) ? 'items' : null);
            if ($itemsKey === null) {
                // PII: не дампим тело целиком — там могут быть ФИО/email/телефоны.
                throw new \Exception("Missing \"Items\" section in the FindWaitingAppointments response (keys: " . implode(',', array_keys($data)) . ")");
            }
            if (!is_array($data[$itemsKey])) {
                throw new \Exception("\"Items\" property is not array in the FindWaitingAppointments response");
            }
            // Безопасный summary без PII.
            $summaryIds = array_map(
                static fn($it) => $it['WaitingAppointmentId'] ?? $it['waitingAppointmentId'] ?? $it['id'] ?? null,
                $data[$itemsKey]
            );
            $this->logger->debug(sprintf(
                'FindWaitingAppointments: %d item(s), ids=[%s]',
                count($data[$itemsKey]),
                implode(',', array_filter($summaryIds, static fn($v) => $v !== null))
            ));
            $appointments = [];
            foreach ($data[$itemsKey] as $item) {
                $appointments[] = [
                    "WaitingAppointmentId" => $item["WaitingAppointmentId"] ?? $item["waitingAppointmentId"] ?? $item["id"] ?? null,
                    "PlaceInQueue" => $item["PlaceInQueue"] ?? $item["placeInQueue"] ?? $item["placeInQueueString"] ?? null,
                    "CanConfirm" => $item["CanConfirm"] ?? $item["canConfirm"] ?? null,
                    "CanCancel" => $item["CanCancel"] ?? $item["canCancel"] ?? null,
                    "Email" => $item["Email"] ?? $item["email"] ?? null,
                    "FullName" => $item["FullName"] ?? $item["fullName"] ?? null,
                    "PhoneNumber" => $item["PhoneNumber"] ?? $item["phoneNumber"] ?? null,
                    "ScheduledDateTimeString" => $item["ScheduledDateTimeString"] ?? $item["scheduledDateTimeString"] ?? $item["scheduledDateTime"] ?? null,
                    "ServiceProviderCode" => $item["ServiceProviderCode"] ?? $item["serviceProviderCode"] ?? null,
                    "ServiceId" => $item["ServiceId"] ?? $item["serviceId"] ?? null,
                    "ServiceName" => $item["ServiceName"] ?? $item["serviceName"] ?? $item["applicantInfo"] ?? '(unknown service)',
                ];
            }
            return $appointments;
        }, 5);
    }

    private function confirmAppointment(string $appointmentId, string $captchaCode): void
    {
        $payload = json_encode([
            'ids' => [$appointmentId],
            'captcha' => $captchaCode,
        ], JSON_UNESCAPED_UNICODE);

        $this->guzzleRetryHelper->execute(function() use ($payload) {
            $response = $this->httpPost('https://q.midpass.ru/api/Appointments/ConfirmWaitingAppointments', $payload, [
                \GuzzleHttp\RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Referer' => 'https://q.midpass.ru/ru/Appointments/WaitingList',
                    'Content-Length' => strlen($payload),
                ],
            ]);
            $content = $response->getBody()->getContents();
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $okBool = $data['IsSuccessful'] ?? $data['isSuccessful'] ?? $data['result'] ?? null;
            if ($okBool === true) {
                $this->logger->notice("Successful confirmation");
                return;
            }
            if ($okBool === false) {
                $msg = $data['ErrorMessage'] ?? $data['errorMessage'] ?? $data['text'] ?? 'Unknown error';
                throw new \Exception((string) $msg);
            }
            // Неизвестная форма ответа — НЕ считаем подтверждением. Бросаем,
            // чтобы вызывающий код не записал LastConfirmation и повторил
            // попытку. Тело логируем для диагностики.
            $this->logger->debug('ConfirmWaitingAppointments unexpected response: ' . substr($content, 0, 500));
            throw new \Exception('ConfirmWaitingAppointments: unexpected response shape');
        }, 5);
    }

    /**
     * @throws \JsonException
     */
    private function loadState(): void
    {
        $file = PROJECT_ROOT_DIR . '/../temp/confirm-queue.json';
        if (file_exists($file)) {
            $this->state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } else {
            $this->state = [];
        }
        // Ensure both sub-arrays exist so callers don't need to handle missing keys.
        // Backwards compatible with state files written by older versions which had
        // only LastConfirmation.
        $this->state['WaitingAppointments'] ??= [];
        $this->state['WaitingAppointments']['LastConfirmation'] ??= [];
        $this->state['WaitingAppointments']['LastNegativeProbe'] ??= [];
    }

    /**
     * @return void
     * @throws \JsonException
     */
    private function saveState(): void
    {
        $file = PROJECT_ROOT_DIR . '/../temp/confirm-queue.json';
        file_put_contents($file, json_encode($this->state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * Local pre-check: decide whether to bother logging in this cron tick.
     *
     * This is purely an optimization to avoid spending captcha solver calls
     * when the server obviously won't accept a confirmation yet. The server's
     * "canConfirm" field is always the source of truth — this method only
     * decides whether to ask the server in the first place.
     *
     * If RENEWAL_INTERVAL_HOURS is not configured, this returns true and the
     * caller proceeds every tick (the original behaviour). Once configured,
     * the method blocks attempts until either:
     *   - at least RENEWAL_INTERVAL_HOURS have passed since the earliest
     *     successful LastConfirmation, AND
     *   - at least NEGATIVE_PROBE_COOLDOWN_MINUTES have passed since the
     *     most recent canConfirm:false response from the server.
     *
     * A sanity ceiling MAX_SKIP_AGE_HOURS forces an attempt anyway if the
     * skip window grows too large (protects against clock skew or stale
     * state).
     */
    private function doNeedToConfirmAnything(): bool
    {
        $intervalHours = $this->config->get('queue.renewalIntervalHours');
        if ($intervalHours === null) {
            // Pre-check disabled: every tick proceeds, server decides.
            return true;
        }

        $lastConfirmations = $this->state['WaitingAppointments']['LastConfirmation'] ?? [];
        if (count($lastConfirmations) === 0) {
            // Never confirmed anything — let the cycle run.
            return true;
        }

        $now = Carbon::now();

        $earliest = null;
        foreach ($lastConfirmations as $isoTs) {
            $dt = \Carbon\Carbon::createFromFormat('c', $isoTs);
            if ($earliest === null || $earliest > $dt) {
                $earliest = $dt;
            }
        }

        $maxSkipHours = (int) $this->config->get('queue.maxSkipAgeHours');
        $hoursSinceConfirm = $earliest->diffInMinutes($now) / 60.0;

        if ($hoursSinceConfirm >= $maxSkipHours) {
            $this->logger->warning(sprintf(
                'Pre-check ceiling hit (%.1fh since last confirm ≥ %dh) — forcing attempt',
                $hoursSinceConfirm,
                $maxSkipHours
            ));
            return true;
        }

        if ($hoursSinceConfirm < $intervalHours) {
            $this->logger->notice(sprintf(
                "There's no need to do anything yet: %.1fh since last confirm, renewal interval is %dh",
                $hoursSinceConfirm,
                $intervalHours
            ));
            return false;
        }

        $negativeProbes = $this->state['WaitingAppointments']['LastNegativeProbe'] ?? [];
        if (count($negativeProbes) > 0) {
            $latestNegative = null;
            foreach ($negativeProbes as $isoTs) {
                $dt = \Carbon\Carbon::createFromFormat('c', $isoTs);
                if ($latestNegative === null || $latestNegative < $dt) {
                    $latestNegative = $dt;
                }
            }
            $cooldownMinutes = (int) $this->config->get('queue.negativeProbeCooldownMinutes');
            $minutesSinceNegative = $latestNegative->diffInMinutes($now);
            if ($minutesSinceNegative < $cooldownMinutes) {
                $this->logger->notice(sprintf(
                    "There's no need to do anything yet: server returned canConfirm:false %dmin ago, cooldown is %dmin",
                    $minutesSinceNegative,
                    $cooldownMinutes
                ));
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $url
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function httpGet(string $url, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->httpRequest('GET', $url, $options);
    }

    /**
     * @param string $url
     * @param callable|float|\Iterator|int|string|StreamInterface|null $payload
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function httpPost(string $url, callable|float|StreamInterface|\Iterator|int|string|null $payload, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $options[\GuzzleHttp\RequestOptions::BODY] = $payload;
        return $this->httpRequest('POST', $url, $options);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function httpRequest(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        if (!array_key_exists(\GuzzleHttp\RequestOptions::HEADERS, $options)) {
            $options[\GuzzleHttp\RequestOptions::HEADERS] = [];
        }
        $options[\GuzzleHttp\RequestOptions::HEADERS] = array_merge($this->httpHeaders, $options[\GuzzleHttp\RequestOptions::HEADERS]);
        ksort($options[\GuzzleHttp\RequestOptions::HEADERS]);

//        echo "{$method} {$url}\n";
//        echo "Request headers:\n" . json_encode($options[\GuzzleHttp\RequestOptions::HEADERS], JSON_PRETTY_PRINT) . "\n";
//        if (!empty($options[\GuzzleHttp\RequestOptions::BODY])) {
//            echo "Request body:\n" . json_encode($options[\GuzzleHttp\RequestOptions::BODY], JSON_PRETTY_PRINT) . "\n";
//        }

        $response = $this->guzzle->request($method, $url, $options);

//        echo "Status: {$response->getStatusCode()}\n";
//        echo "Response headers:\n" . json_encode($response->getHeaders(), JSON_PRETTY_PRINT) . "\n";

        //sleep(1); // Little delay between HTTP requests to prevent hammering

        return $response;
    }

}
