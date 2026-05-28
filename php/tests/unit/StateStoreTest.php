<?php

declare(strict_types=1);

use App\State\StateStore;

test('StateStore: load missing file returns defaults', function () {
    $path = sys_get_temp_dir() . '/midpass-test-' . uniqid() . '.json';
    $store = new StateStore($path);
    $state = $store->load();
    assert_eq([], $state['WaitingAppointments']['LastConfirmation']);
    assert_eq([], $state['WaitingAppointments']['LastNegativeProbe']);
    assert_eq([], $state['Notifications']['LastPlaceAlert']);
});

test('StateStore: save then load round-trips data', function () {
    $path = sys_get_temp_dir() . '/midpass-test-' . uniqid() . '.json';
    $store = new StateStore($path);
    $state = $store->load();
    $state['WaitingAppointments']['LastConfirmation']['id1'] = '2026-01-01T00:00:00+00:00';
    $store->save($state);

    $reloaded = (new StateStore($path))->load();
    assert_eq('2026-01-01T00:00:00+00:00', $reloaded['WaitingAppointments']['LastConfirmation']['id1']);
    @unlink($path);
});

test('StateStore: save leaves no .tmp file behind', function () {
    $path = sys_get_temp_dir() . '/midpass-test-' . uniqid() . '.json';
    (new StateStore($path))->save(StateStore::withDefaults([]));
    assert_true(is_file($path));
    assert_true(!is_file($path . '.tmp'));
    @unlink($path);
});
