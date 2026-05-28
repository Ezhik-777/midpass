<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Builds q.midpass.ru API URLs, referers and origin from a configurable base,
 * so the host is defined in one place and the URL building is unit-testable.
 */
final class Endpoints
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function origin(): string
    {
        return $this->baseUrl;
    }

    public function captchaImage(int|string $tick): string
    {
        return $this->baseUrl . '/api/Account/CaptchaImage?' . $tick;
    }

    public function login(): string
    {
        return $this->baseUrl . '/api/Account/DoPrivatePersonLogOn';
    }

    public function logoff(): string
    {
        return $this->baseUrl . '/api/Account/Logoff';
    }

    public function findWaiting(string $query): string
    {
        return $this->baseUrl . '/api/Appointments/FindWaitingAppointments?' . $query;
    }

    public function confirm(): string
    {
        return $this->baseUrl . '/api/Appointments/ConfirmWaitingAppointments';
    }

    public function loginReferer(): string
    {
        return $this->baseUrl . '/ru/account/PrivatePersonLogOn';
    }

    public function waitingListReferer(): string
    {
        return $this->baseUrl . '/ru/Appointments/WaitingList';
    }
}
