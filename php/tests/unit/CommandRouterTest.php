<?php

declare(strict_types=1);

use App\Console\CommandRouter;
use App\Console\Commands\ConfirmQueueCommand;
use App\Console\Commands\HelpCommand;

test('CommandRouter: maps command name to class name', function () {
    assert_eq(
        'App\\Console\\Commands\\ConfirmQueueCommand',
        CommandRouter::commandToClass('confirm-queue')
    );
});

test('CommandRouter: routes known commands', function () {
    assert_eq(ConfirmQueueCommand::class, CommandRouter::route('confirm-queue'));
    assert_eq(HelpCommand::class, CommandRouter::route('help'));
});

test('CommandRouter: unknown command throws', function () {
    $threw = false;
    try {
        CommandRouter::route('does-not-exist');
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    assert_true($threw);
});

test('CommandRouter: classToCommand reverses mapping', function () {
    assert_eq('confirm-queue', CommandRouter::classToCommand(ConfirmQueueCommand::class));
});
