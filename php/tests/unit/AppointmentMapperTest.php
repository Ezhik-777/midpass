<?php

declare(strict_types=1);

use App\Queue\AppointmentMapper;

test('AppointmentMapper: maps Items with PascalCase keys', function () {
    $data = ['Items' => [[
        'WaitingAppointmentId' => 'abc',
        'PlaceInQueue' => 'Место 106',
        'CanConfirm' => false,
        'ServiceName' => 'Загранпаспорт',
    ]]];
    $out = AppointmentMapper::fromResponse($data);
    assert_eq(1, count($out));
    assert_eq('abc', $out[0]['WaitingAppointmentId']);
    assert_eq('Место 106', $out[0]['PlaceInQueue']);
    assert_eq(false, $out[0]['CanConfirm']);
    assert_eq('Загранпаспорт', $out[0]['ServiceName']);
    assert_true(array_key_exists('_raw', $out[0]));
});

test('AppointmentMapper: supports lowercase keys and fallbacks', function () {
    $data = ['items' => [[
        'id' => 'x1',
        'placeInQueueString' => 'Место 5',
        'canConfirm' => true,
    ]]];
    $out = AppointmentMapper::fromResponse($data);
    assert_eq('x1', $out[0]['WaitingAppointmentId']);
    assert_eq('Место 5', $out[0]['PlaceInQueue']);
    assert_eq(true, $out[0]['CanConfirm']);
    assert_eq('(unknown service)', $out[0]['ServiceName']);
});

test('AppointmentMapper: missing Items section throws', function () {
    $threw = false;
    try {
        AppointmentMapper::fromResponse(['foo' => 'bar']);
    } catch (\RuntimeException) {
        $threw = true;
    }
    assert_true($threw);
});

test('AppointmentMapper: non-array Items throws', function () {
    $threw = false;
    try {
        AppointmentMapper::fromResponse(['Items' => 'nope']);
    } catch (\RuntimeException) {
        $threw = true;
    }
    assert_true($threw);
});

test('AppointmentMapper: ids() extracts only ids', function () {
    $out = AppointmentMapper::fromResponse(['Items' => [['id' => 'a'], ['id' => 'b']]]);
    assert_eq(['a', 'b'], AppointmentMapper::ids($out));
});
