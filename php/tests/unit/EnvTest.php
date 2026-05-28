<?php

declare(strict_types=1);

use App\Config\Env;

test('Env::int returns default for empty/missing/non-numeric', function () {
    unset($_ENV['X_INT']);
    assert_eq(28, Env::int('X_INT', 28));
    $_ENV['X_INT'] = '';
    assert_eq(28, Env::int('X_INT', 28));
    $_ENV['X_INT'] = 'abc';
    assert_eq(28, Env::int('X_INT', 28));
    unset($_ENV['X_INT']);
});

test('Env::int falls back to default when out of range', function () {
    $_ENV['X_INT'] = '-5';
    assert_eq(28, Env::int('X_INT', 28, 0));        // below min -> default
    $_ENV['X_INT'] = '999';
    assert_eq(28, Env::int('X_INT', 28, 0, 100));   // above max -> default
    $_ENV['X_INT'] = '50';
    assert_eq(50, Env::int('X_INT', 28, 0, 100));   // in range -> value
    unset($_ENV['X_INT']);
});

test('Env::intOrNull null default, default when out of range', function () {
    unset($_ENV['X_NULL']);
    assert_eq(null, Env::intOrNull('X_NULL', null, 1));
    $_ENV['X_NULL'] = '0';
    assert_eq(null, Env::intOrNull('X_NULL', null, 1)); // 0 < min 1 -> default null
    $_ENV['X_NULL'] = '25';
    assert_eq(25, Env::intOrNull('X_NULL', null, 1, 168));
    unset($_ENV['X_NULL']);
});

test('Env::str falls back to default when empty', function () {
    unset($_ENV['X_STR']);
    assert_eq('def', Env::str('X_STR', 'def'));
    $_ENV['X_STR'] = 'val';
    assert_eq('val', Env::str('X_STR', 'def'));
    unset($_ENV['X_STR']);
});

test('Env::bool default when unset, falsey strings disable, others enable', function () {
    unset($_ENV['X_BOOL']);
    assert_true(Env::bool('X_BOOL', true));
    assert_true(Env::bool('X_BOOL', false) === false);
    foreach (['0', 'false', 'NO', 'Off'] as $falsey) {
        $_ENV['X_BOOL'] = $falsey;
        assert_true(Env::bool('X_BOOL', true) === false, "'{$falsey}' should disable");
    }
    foreach (['1', 'true', 'yes', 'whatever'] as $truthy) {
        $_ENV['X_BOOL'] = $truthy;
        assert_true(Env::bool('X_BOOL', false), "'{$truthy}' should enable");
    }
    unset($_ENV['X_BOOL']);
});
