<?php

declare(strict_types=1);

namespace App\Notifier;

interface NotifierInterface
{
    public function isEnabled(): bool;

    /** @return bool true if the message was delivered successfully */
    public function send(string $text): bool;
}
