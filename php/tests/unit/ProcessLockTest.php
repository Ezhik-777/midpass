<?php

declare(strict_types=1);

use App\State\ProcessLock;

test('ProcessLock: second acquire fails while first holds, succeeds after release', function () {
    $path = sys_get_temp_dir() . '/midpass-lock-' . uniqid() . '.lock';

    $a = new ProcessLock($path);
    assert_true($a->tryAcquire(), 'first acquire should succeed');

    $b = new ProcessLock($path);
    assert_true($b->tryAcquire() === false, 'second acquire should fail while held');

    $a->release();
    assert_true($b->tryAcquire(), 'acquire should succeed after release');
    $b->release();

    @unlink($path);
});

test('ProcessLock: throws on unopenable path (setup error, not contention)', function () {
    $bad = sys_get_temp_dir() . '/midpass-nope-' . uniqid() . '/sub/dir/x.lock';
    $lock = new ProcessLock($bad);
    $threw = false;
    try {
        $lock->tryAcquire();
    } catch (\RuntimeException) {
        $threw = true;
    }
    assert_true($threw, 'fopen failure on a missing directory must throw');
});
