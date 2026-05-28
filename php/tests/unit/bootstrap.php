<?php

declare(strict_types=1);

// Load composer's autoloader when present (so tests that need third-party
// packages like Guzzle can run in the container / CI after `composer install`).
$vendor = __DIR__ . '/../../vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
}

// Minimal PSR-4 autoloader for App\ -> php/app/ so the pure unit tests also run
// with the bundled PHP without composer. Registered in addition to (after) the
// vendor autoloader; the classes under test that don't need third-party
// packages load either way.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../../app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** @var array<int,array{0:string,1:callable}> $__TESTS */
$GLOBALS['__TESTS'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__TESTS'][] = [$name, $fn];
}

function assert_true(bool $cond, string $msg = ''): void
{
    if ($cond !== true) {
        throw new \RuntimeException('assert_true failed' . ($msg !== '' ? ": $msg" : ''));
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            'assert_eq failed' . ($msg !== '' ? ": $msg" : '')
            . "\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true)
        );
    }
}

function run_tests(): int
{
    $pass = 0;
    $fail = 0;
    foreach ($GLOBALS['__TESTS'] as [$name, $fn]) {
        try {
            $fn();
            echo "PASS  {$name}\n";
            $pass++;
        } catch (\Throwable $e) {
            echo "FAIL  {$name}\n      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n";
            $fail++;
        }
    }
    echo "\n{$pass} passed, {$fail} failed\n";
    return $fail === 0 ? 0 : 1;
}
