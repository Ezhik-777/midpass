<?php

declare(strict_types=1);

namespace App\Captcha;

use App\CaptchaSolver\CaptchaSolverInterface;
use App\CaptchaSolver\CaptchaSolverQMidPass;
use App\CaptchaSolver\CaptchaSolverRuCaptcha;
use App\Http\MidpassApiClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fetches a captcha image via the API client and turns it into a code using the
 * configured solver (rucaptcha when an API key is set, otherwise the built-in
 * one). The solved code is never logged — only its length and accuracy.
 */
final class CaptchaService implements CaptchaServiceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private MidpassApiClientInterface $api,
        private string $rucaptchaApiKey = '',
        private string $rucaptchaEndpoint = 'https://api.rucaptcha.com',
        ?LoggerInterface $logger = null,
        private float $minAccuracy = 70.0,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function solve(string $purpose = 'login'): string
    {
        $this->logger->info("Load CAPTCHA for {$purpose}");
        $pngBytes = $this->api->fetchCaptchaImage();
        $this->logger->debug('Solve CAPTCHA');

        $solver = $this->makeSolver();
        $image = @imagecreatefromstring($pngBytes);
        if ($image === false) {
            throw new \Exception('Captcha bytes are not a decodable image');
        }
        $code = $solver->solveCaptcha($image);
        imagedestroy($image);
        if ($code === null) {
            throw new \Exception('Unable to solve CAPTCHA');
        }
        $this->logger->debug(sprintf('CAPTCHA solved (len %d, accuracy %.1f%%)', strlen($code), round($solver->accuracy, 1)));
        if ($solver->accuracy < $this->minAccuracy) {
            throw new \Exception('CAPTCHA solution accuracy is too low - reload');
        }
        return $code;
    }

    private function makeSolver(): CaptchaSolverInterface
    {
        if ($this->rucaptchaApiKey !== '') {
            $this->logger->debug('Using rucaptcha solver');
            return new CaptchaSolverRuCaptcha(
                apiKey: $this->rucaptchaApiKey,
                endpoint: $this->rucaptchaEndpoint,
                logger: $this->logger,
            );
        }
        $this->logger->debug('Using built-in CaptchaSolverQMidPass');
        return new CaptchaSolverQMidPass();
    }
}
