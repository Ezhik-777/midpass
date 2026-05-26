<?php

namespace App\CaptchaSolver;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class CaptchaSolverRuCaptcha implements CaptchaSolverInterface
{
    public float $accuracy = 100.0;
    public ?int $lastTaskId = null;

    private Client $http;

    public function __construct(
        private string $apiKey,
        private string $endpoint = 'https://api.rucaptcha.com',
        private ?LoggerInterface $logger = null,
        private int $pollTimeoutSec = 120,
        private int $pollIntervalSec = 3,
    ) {
        $this->http = new Client(['base_uri' => rtrim($this->endpoint, '/') . '/', 'timeout' => 30]);
    }

    public function solveCaptcha(\GdImage $image): ?string
    {
        ob_start();
        imagepng($image);
        $pngBytes = ob_get_clean();
        $base64 = base64_encode($pngBytes);

        $createResp = $this->postJson('createTask', [
            'clientKey' => $this->apiKey,
            'task' => [
                'type' => 'ImageToTextTask',
                'body' => $base64,
                'case' => true,
                'minLength' => 6,
                'maxLength' => 6,
                'numeric' => 4,
            ],
        ]);

        if (($createResp['errorId'] ?? -1) !== 0) {
            $this->logger?->warning('rucaptcha createTask error: ' . json_encode($createResp, JSON_UNESCAPED_UNICODE));
            return null;
        }

        $taskId = $createResp['taskId'] ?? null;
        if ($taskId === null) {
            return null;
        }
        $this->lastTaskId = $taskId;

        $deadline = time() + $this->pollTimeoutSec;
        sleep(5);
        while (time() < $deadline) {
            $resultResp = $this->postJson('getTaskResult', [
                'clientKey' => $this->apiKey,
                'taskId' => $taskId,
            ]);
            $errorId = $resultResp['errorId'] ?? -1;
            if ($errorId !== 0) {
                $this->logger?->warning('rucaptcha getTaskResult error: ' . json_encode($resultResp, JSON_UNESCAPED_UNICODE));
                return null;
            }
            $status = $resultResp['status'] ?? null;
            if ($status === 'ready') {
                return $resultResp['solution']['text'] ?? null;
            }
            sleep($this->pollIntervalSec);
        }
        $this->logger?->warning('rucaptcha solve timed out');
        return null;
    }

    public function reportCorrect(int $taskId): void
    {
        $this->postJson('reportCorrect', ['clientKey' => $this->apiKey, 'taskId' => $taskId]);
    }

    public function reportIncorrect(int $taskId): void
    {
        $this->postJson('reportIncorrect', ['clientKey' => $this->apiKey, 'taskId' => $taskId]);
    }

    private function postJson(string $endpoint, array $body): array
    {
        try {
            $response = $this->http->post($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
                'http_errors' => false,
            ]);
            $raw = (string) $response->getBody();
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : ['errorId' => -1, 'errorDescription' => $raw];
        } catch (\Throwable $e) {
            return ['errorId' => -1, 'errorDescription' => $e->getMessage()];
        }
    }
}
