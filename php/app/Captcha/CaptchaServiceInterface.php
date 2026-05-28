<?php

declare(strict_types=1);

namespace App\Captcha;

interface CaptchaServiceInterface
{
    /** @return string the solved captcha code */
    public function solve(string $purpose = 'login'): string;
}
