<?php

namespace App\Notifier;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Sends plain-text notifications to a Telegram chat via the Bot API.
 *
 * No-op when token or chat id are not configured, and never throws —
 * a notification failure must not break the confirmation flow.
 */
class TelegramNotifier implements NotifierInterface
{
    private ?Client $http = null;

    public function __construct(
        private string $botToken,
        private string $chatId,
        private ?LoggerInterface $logger = null,
        private ?\GuzzleHttp\HandlerStack $handler = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->botToken !== '' && $this->chatId !== '';
    }

    public function send(string $text): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            $options = ['timeout' => 10];
            if ($this->handler !== null) {
                $options['handler'] = $this->handler;
            }
            $http = $this->http ??= new Client($options);
            $response = $http->post(
                'https://api.telegram.org/bot' . $this->botToken . '/sendMessage',
                [
                    'json' => [
                        'chat_id' => $this->chatId,
                        'text' => $text,
                        'disable_web_page_preview' => true,
                    ],
                    'http_errors' => false,
                ]
            );
            $code = $response->getStatusCode();
            if ($code !== 200) {
                $this->logger?->warning('Telegram sendMessage HTTP ' . $code . ': ' . $this->redactToken(substr((string) $response->getBody(), 0, 300)));
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Telegram notify failed: ' . $this->redactToken($e->getMessage()));
            return false;
        }
    }

    /**
     * Remove the bot token from any string before it reaches the logs
     * (Guzzle exception messages embed the request URL, which contains it).
     */
    private function redactToken(string $message): string
    {
        return $this->botToken !== '' ? str_replace($this->botToken, '***', $message) : $message;
    }
}
