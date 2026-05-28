<?php

declare(strict_types=1);

use App\Queue\PreCheck;

$now = new \DateTimeImmutable('2026-01-01T12:00:00+00:00');

// Build an ISO-8601 ('c'/ATOM) timestamp $hours before $now.
$hoursAgo = static function (float $hours) use ($now): string {
    return $now->sub(new \DateInterval('PT' . (int) round($hours * 60) . 'M'))->format(\DateTimeInterface::ATOM);
};

test('PreCheck: disabled when interval is null', function () use ($now) {
    $r = PreCheck::evaluate([], [], $now, null, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::DISABLED, $r['code']);
});

test('PreCheck: proceed when never confirmed', function () use ($now) {
    $r = PreCheck::evaluate([], [], $now, 25, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::NEVER_CONFIRMED, $r['code']);
});

test('PreCheck: skip within interval', function () use ($now, $hoursAgo) {
    $r = PreCheck::evaluate([$hoursAgo(10)], [], $now, 25, 28, 60);
    assert_true($r['proceed'] === false);
    assert_eq(PreCheck::WITHIN_INTERVAL, $r['code']);
});

test('PreCheck: ceiling forces attempt', function () use ($now, $hoursAgo) {
    $r = PreCheck::evaluate([$hoursAgo(30)], [], $now, 25, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::CEILING, $r['code']);
});

test('PreCheck: ok past interval, no negative probe', function () use ($now, $hoursAgo) {
    $r = PreCheck::evaluate([$hoursAgo(26)], [], $now, 25, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::OK, $r['code']);
});

test('PreCheck: cooldown blocks shortly after negative probe', function () use ($now, $hoursAgo) {
    $negative = $now->sub(new \DateInterval('PT30M'))->format(\DateTimeInterface::ATOM);
    $r = PreCheck::evaluate([$hoursAgo(26)], [$negative], $now, 25, 28, 60);
    assert_true($r['proceed'] === false);
    assert_eq(PreCheck::COOLDOWN, $r['code']);
    assert_eq(30, $r['minutesSinceNegative']);
});

test('PreCheck: proceed after cooldown elapses', function () use ($now, $hoursAgo) {
    $negative = $now->sub(new \DateInterval('PT90M'))->format(\DateTimeInterface::ATOM);
    $r = PreCheck::evaluate([$hoursAgo(26)], [$negative], $now, 25, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::OK, $r['code']);
});

test('PreCheck: earliest confirmation drives the window', function () use ($now, $hoursAgo) {
    // One stale (30h) and one recent (2h): earliest=30h triggers ceiling.
    $r = PreCheck::evaluate([$hoursAgo(2), $hoursAgo(30)], [], $now, 25, 28, 60);
    assert_eq(PreCheck::CEILING, $r['code']);
});

test('PreCheck: future confirmation timestamp forces an attempt', function () use ($now) {
    $future = $now->add(new \DateInterval('PT5H'))->format(\DateTimeInterface::ATOM);
    $r = PreCheck::evaluate([$future], [], $now, 25, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::FUTURE_TIMESTAMP, $r['code']);
});

test('PreCheck: future negative probe does not block', function () use ($now, $hoursAgo) {
    $future = $now->add(new \DateInterval('PT5H'))->format(\DateTimeInterface::ATOM);
    // interval has elapsed (26h), and the only negative probe is in the future
    $r = PreCheck::evaluate([$hoursAgo(26)], [$future], $now, 25, 28, 60);
    assert_true($r['proceed']);
    assert_eq(PreCheck::OK, $r['code']);
});

test('PreCheck: future probe must not shadow a recent past probe', function () use ($now, $hoursAgo) {
    $future = $now->add(new \DateInterval('PT5H'))->format(\DateTimeInterface::ATOM);
    $recent = $now->sub(new \DateInterval('PT20M'))->format(\DateTimeInterface::ATOM);
    // interval elapsed (26h); a future probe and a recent (20min) probe present.
    // The recent past probe must still trigger cooldown (60min).
    $r = PreCheck::evaluate([$hoursAgo(26)], [$future, $recent], $now, 25, 28, 60);
    assert_true($r['proceed'] === false);
    assert_eq(PreCheck::COOLDOWN, $r['code']);
    assert_eq(20, $r['minutesSinceNegative']);
});
