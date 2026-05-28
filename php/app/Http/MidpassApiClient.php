<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\PermanentException;
use App\Queue\AppointmentMapper;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use gugglegum\RetryHelper\RetryHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * All q.midpass.ru HTTP I/O: session cookies, default headers, transient-error
 * retries, login/logout, paginated appointment fetch and confirmation. Kept out
 * of the command so it can be exercised with a Guzzle MockHandler.
 */
final class MidpassApiClient implements MidpassApiClientInterface
{
    private Client $guzzle;
    private CookieJar $cookieJar;
    private RetryHelper $retry;
    private LoggerInterface $logger;
    private bool $authorized = false;

    private array $httpHeaders = [
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Encoding' => 'gzip, deflate',
        'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
        'Cache-Control' => 'no-cache',
        'Dnt' => '1',
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

    public function __construct(
        private Endpoints $endpoints,
        ?LoggerInterface $logger = null,
        ?HandlerStack $handler = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->httpHeaders['Origin'] = $this->endpoints->origin();
        $this->cookieJar = new CookieJar();
        $config = [
            'allow_redirects' => false,
            'cookies' => $this->cookieJar,
            'connect_timeout' => 10,
            'timeout' => 30,
        ];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->guzzle = new Client($config);
        $this->retry = (new RetryHelper())
            ->setIsTemporaryException(static fn(\Throwable $e) => $e instanceof ConnectException || $e instanceof ServerException)
            ->setLogger($this->logger);
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    public function fetchCaptchaImage(): string
    {
        return $this->retry->execute(function () {
            $tick = (string) floor(microtime(true) * 1000);
            $response = $this->httpGet($this->endpoints->captchaImage($tick), [
                RequestOptions::HEADERS => [
                    'Referer' => $this->endpoints->loginReferer(),
                    'Accept' => 'image/png,*/*;q=0.8',
                ],
            ]);
            $bytes = $response->getBody()->getContents();
            // Validate PNG magic bytes — the downstream GD solver only handles PNG,
            // and a non-image body here means something is wrong with the request.
            if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new \Exception('Captcha endpoint did not return a PNG image');
            }
            return $bytes;
        }, 5);
    }

    public function login(string $email, string $password, string $captchaCode, string $serviceProviderId): void
    {
        $payload = json_encode([
            'serviceProviderId' => $serviceProviderId,
            'email' => $email,
            'password' => $password,
            'captcha' => $captchaCode,
        ], JSON_UNESCAPED_UNICODE);

        // Transient HTTP errors are retried here; captcha/permanent classification
        // happens after a successful response and is intentionally not retried.
        $data = $this->retry->execute(function () use ($payload) {
            $response = $this->httpPost($this->endpoints->login(), $payload, [
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Referer' => $this->endpoints->loginReferer(),
                    'Content-Length' => strlen($payload),
                ],
            ]);
            $content = $response->getBody()->getContents();
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                throw new \Exception('Login: unexpected non-JSON response: ' . substr($content, 0, 200));
            }
            return $decoded;
        }, 5);

        if (($data['result'] ?? false) !== true) {
            $errorKey = (string) ($data['text'] ?? 'Unknown error');
            $captchaErrorKeys = [
                'Controller.Account.Validation.CaptchaError',
                'Controller.Account.Validation.CaptchaIsRequired',
                'Controller.Account.Validation.WrongCaptcha',
            ];
            if (!empty($data['refreshCaptcha']) || in_array($errorKey, $captchaErrorKeys, true) || stripos($errorKey, 'captcha') !== false) {
                throw new \Exception('CAPTCHA error: ' . $errorKey);
            }
            throw new PermanentException('Login error: ' . $errorKey);
        }

        $sessionCookie = $this->cookieJar->getCookieByName('.AspNetCore.Session');
        if (!$sessionCookie || $sessionCookie->getValue() === '') {
            throw new \Exception('Failed authorization: no ".AspNetCore.Session" cookie came from the server');
        }

        $this->authorized = true;
    }

    public function logout(): void
    {
        try {
            $this->httpPost($this->endpoints->logoff(), '', [
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Referer' => $this->endpoints->waitingListReferer(),
                    'Content-Length' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Logout failed: ' . $e->getMessage());
        }
        $this->authorized = false;
    }

    public function fetchWaitingAppointments(int $pageSize = 10, int $maxPages = 100): array
    {
        $all = [];
        $lastPageFull = false;
        for ($pageIndex = 0; $pageIndex < $maxPages; $pageIndex++) {
            $page = $this->fetchWaitingAppointmentsPage($pageIndex, $pageSize);
            $all = array_merge($all, $page);
            $lastPageFull = count($page) === $pageSize;
            if (!$lastPageFull) {
                break;
            }
        }
        if ($lastPageFull) {
            $this->logger->warning(sprintf(
                'FindWaitingAppointments: hit %d-page safety bound with a full last page — more appointments may exist and were not fetched',
                $maxPages
            ));
        }
        return $all;
    }

    public function fetchWaitingAppointmentsPage(int $pageIndex, int $pageSize): array
    {
        $query = http_build_query(['pageIndex' => $pageIndex, 'pageSize' => $pageSize]);
        return $this->retry->execute(function () use ($query) {
            $response = $this->httpGet($this->endpoints->findWaiting($query), [
                RequestOptions::HEADERS => ['Referer' => $this->endpoints->waitingListReferer()],
            ]);
            $content = $response->getBody()->getContents();
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \Exception('FindWaitingAppointments: unexpected response');
            }
            $mapped = AppointmentMapper::fromResponse($data);
            $ids = array_filter(AppointmentMapper::ids($mapped), static fn($v) => $v !== null);
            $this->logger->debug(sprintf('FindWaitingAppointments: %d item(s), ids=[%s]', count($mapped), implode(',', $ids)));
            return $mapped;
        }, 5);
    }

    public function confirmWaitingAppointment(string $appointmentId, string $captchaCode): void
    {
        $payload = json_encode([
            'ids' => [$appointmentId],
            'captcha' => $captchaCode,
        ], JSON_UNESCAPED_UNICODE);

        $this->retry->execute(function () use ($payload) {
            $response = $this->httpPost($this->endpoints->confirm(), $payload, [
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Referer' => $this->endpoints->waitingListReferer(),
                    'Content-Length' => strlen($payload),
                ],
            ]);
            $content = $response->getBody()->getContents();
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $okBool = $data['IsSuccessful'] ?? $data['isSuccessful'] ?? $data['result'] ?? null;
            if ($okBool === true) {
                $this->logger->notice('Successful confirmation');
                return;
            }
            if ($okBool === false) {
                $msg = $data['ErrorMessage'] ?? $data['errorMessage'] ?? $data['text'] ?? 'Unknown error';
                throw new \Exception((string) $msg);
            }
            $this->logger->debug('ConfirmWaitingAppointments unexpected response: ' . substr($content, 0, 500));
            throw new \Exception('ConfirmWaitingAppointments: unexpected response shape');
        }, 5);
    }

    private function httpGet(string $url, array $options = []): ResponseInterface
    {
        return $this->httpRequest('GET', $url, $options);
    }

    private function httpPost(string $url, mixed $payload, array $options = []): ResponseInterface
    {
        $options[RequestOptions::BODY] = $payload;
        return $this->httpRequest('POST', $url, $options);
    }

    private function httpRequest(string $method, string $url, array $options = []): ResponseInterface
    {
        $options[RequestOptions::HEADERS] = array_merge($this->httpHeaders, $options[RequestOptions::HEADERS] ?? []);
        ksort($options[RequestOptions::HEADERS]);
        return $this->guzzle->request($method, $url, $options);
    }
}
