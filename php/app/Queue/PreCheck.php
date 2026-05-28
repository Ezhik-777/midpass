<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Pure decision logic for the local pre-check: given the recorded confirmation
 * and negative-probe timestamps, decide whether the bot should attempt a login
 * + confirm this tick. No I/O, logging or framework dependencies — so it can be
 * unit tested in isolation. The server's canConfirm remains the source of truth;
 * this only avoids spending captcha calls when an attempt is obviously pointless.
 */
final class PreCheck
{
    public const DISABLED = 'precheck_disabled';
    public const NEVER_CONFIRMED = 'never_confirmed';
    public const CEILING = 'ceiling';
    public const WITHIN_INTERVAL = 'within_interval';
    public const COOLDOWN = 'cooldown';
    public const FUTURE_TIMESTAMP = 'future_timestamp';
    public const OK = 'ok';

    /**
     * @param string[] $lastConfirmations ISO-8601 ('c') timestamps
     * @param string[] $negativeProbes    ISO-8601 ('c') timestamps
     * @return array{proceed:bool,code:string,hoursSinceConfirm:?float,minutesSinceNegative:?int}
     */
    public static function evaluate(
        array $lastConfirmations,
        array $negativeProbes,
        \DateTimeInterface $now,
        ?int $intervalHours,
        int $maxSkipHours,
        int $cooldownMinutes,
    ): array {
        if ($intervalHours === null) {
            return self::result(true, self::DISABLED);
        }

        $earliestConfirm = self::minTs($lastConfirmations);
        if ($earliestConfirm === null) {
            return self::result(true, self::NEVER_CONFIRMED);
        }

        $nowTs = $now->getTimestamp();

        // Clock skew / hand-edited state: a confirmation timestamp in the future
        // would otherwise clamp the elapsed time to 0 and block attempts forever
        // (the ceiling never fires). Treat it as an anomaly and force an attempt.
        if ($earliestConfirm > $nowTs) {
            return self::result(true, self::FUTURE_TIMESTAMP);
        }

        $hoursSinceConfirm = self::minutesBetween($earliestConfirm, $nowTs) / 60.0;

        if ($hoursSinceConfirm >= $maxSkipHours) {
            return self::result(true, self::CEILING, $hoursSinceConfirm);
        }
        if ($hoursSinceConfirm < $intervalHours) {
            return self::result(false, self::WITHIN_INTERVAL, $hoursSinceConfirm);
        }

        // Latest negative probe among PAST timestamps only. Future-dated probes
        // (clock skew / edited state) must be ignored BEFORE taking the max, so
        // a stray future entry cannot shadow a valid recent probe.
        $latestNegative = self::maxPastTs($negativeProbes, $nowTs);
        if ($latestNegative !== null) {
            $minutesSinceNegative = self::minutesBetween($latestNegative, $nowTs);
            if ($minutesSinceNegative < $cooldownMinutes) {
                return self::result(false, self::COOLDOWN, $hoursSinceConfirm, $minutesSinceNegative);
            }
        }

        return self::result(true, self::OK, $hoursSinceConfirm);
    }

    private static function result(bool $proceed, string $code, ?float $hours = null, ?int $minutes = null): array
    {
        return [
            'proceed' => $proceed,
            'code' => $code,
            'hoursSinceConfirm' => $hours,
            'minutesSinceNegative' => $minutes,
        ];
    }

    /** @param string[] $isoList */
    private static function minTs(array $isoList): ?int
    {
        $min = null;
        foreach ($isoList as $iso) {
            $ts = self::toTs($iso);
            if ($ts !== null && ($min === null || $ts < $min)) {
                $min = $ts;
            }
        }
        return $min;
    }

    /** @param string[] $isoList */
    private static function maxPastTs(array $isoList, int $nowTs): ?int
    {
        $max = null;
        foreach ($isoList as $iso) {
            $ts = self::toTs($iso);
            if ($ts !== null && $ts <= $nowTs && ($max === null || $ts > $max)) {
                $max = $ts;
            }
        }
        return $max;
    }

    private static function toTs(string $iso): ?int
    {
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $iso);
        return $dt === false ? null : $dt->getTimestamp();
    }

    private static function minutesBetween(int $fromTs, int $toTs): int
    {
        return intdiv(max(0, $toTs - $fromTs), 60);
    }
}
