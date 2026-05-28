<?php

declare(strict_types=1);

namespace App\Http;

interface MidpassApiClientInterface
{
    /** @return string raw captcha PNG bytes */
    public function fetchCaptchaImage(): string;

    public function login(string $email, string $password, string $captchaCode, string $serviceProviderId): void;

    public function logout(): void;

    public function isAuthorized(): bool;

    /** @return list<array<string,mixed>> */
    public function fetchWaitingAppointmentsPage(int $pageIndex, int $pageSize): array;

    /** @return list<array<string,mixed>> */
    public function fetchWaitingAppointments(int $pageSize = 10, int $maxPages = 100): array;

    public function confirmWaitingAppointment(string $appointmentId, string $captchaCode): void;
}
