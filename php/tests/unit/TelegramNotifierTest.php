<?php

declare(strict_types=1);

use App\Notifier\TelegramNotifier;

test('TelegramNotifier: disabled when token/chat empty', function () {
    assert_true((new TelegramNotifier('', ''))->isEnabled() === false);
    assert_true((new TelegramNotifier('tok', ''))->isEnabled() === false);
    assert_true((new TelegramNotifier('', 'chat'))->isEnabled() === false);
});

test('TelegramNotifier: enabled when both set', function () {
    assert_true((new TelegramNotifier('tok', 'chat'))->isEnabled());
});

test('TelegramNotifier: send is a no-op returning false when disabled', function () {
    assert_true((new TelegramNotifier('', ''))->send('hello') === false);
});

test('TelegramNotifier: redactToken removes the bot token from strings', function () {
    $n = new TelegramNotifier('SECRET123', 'chat');
    $m = new \ReflectionMethod($n, 'redactToken');
    $m->setAccessible(true);
    assert_eq(
        'connect to https://api.telegram.org/bot***/sendMessage failed',
        $m->invoke($n, 'connect to https://api.telegram.org/botSECRET123/sendMessage failed')
    );
});

test('TelegramNotifier: redactToken is a no-op when token empty', function () {
    $n = new TelegramNotifier('', '');
    $m = new \ReflectionMethod($n, 'redactToken');
    $m->setAccessible(true);
    assert_eq('nothing to redact', $m->invoke($n, 'nothing to redact'));
});

if (class_exists(\GuzzleHttp\Handler\MockHandler::class)) {
    test('TelegramNotifier: send returns true on HTTP 200', function () {
        $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200, [], '{"ok":true}')]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $n = new TelegramNotifier('tok', 'chat', null, $stack);
        assert_true($n->send('hi') === true);
    });

    test('TelegramNotifier: send returns false on non-200', function () {
        $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(400, [], '{"ok":false}')]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $n = new TelegramNotifier('tok', 'chat', null, $stack);
        assert_true($n->send('hi') === false);
    });

    test('TelegramNotifier: send returns false on transport exception', function () {
        $mock = new \GuzzleHttp\Handler\MockHandler([new \RuntimeException('boom')]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $n = new TelegramNotifier('tok', 'chat', null, $stack);
        assert_true($n->send('hi') === false);
    });
}
