<?php

declare(strict_types=1);

use App\Queue\AppointmentRedactor;

test('AppointmentRedactor: masks personal data, keeps diagnostic fields', function () {
    $in = [
        'Email' => 'a@b.com',
        'PhoneNumber' => '+100',
        'FullName' => 'Ivanov Ivan',
        'PassportNumber' => '1234',
        'BirthDate' => '1990-01-01',
        'Address' => 'Somewhere',
        'ServiceName' => 'Загранпаспорт',
        'PlaceInQueue' => 'Место 5',
        'CanConfirm' => true,
        'someSlotDate' => '2026-06-01T10:00',
    ];
    $out = AppointmentRedactor::redactPii($in);

    // Masked PII
    assert_eq('***', $out['Email']);
    assert_eq('***', $out['PhoneNumber']);
    assert_eq('***', $out['FullName']);
    assert_eq('***', $out['PassportNumber']);
    assert_eq('***', $out['BirthDate']);
    assert_eq('***', $out['Address']);

    // Kept (non-PII / diagnostic)
    assert_eq('Загранпаспорт', $out['ServiceName']);
    assert_eq('Место 5', $out['PlaceInQueue']);
    assert_eq(true, $out['CanConfirm']);
    assert_eq('2026-06-01T10:00', $out['someSlotDate']);
});

test('AppointmentRedactor: case-insensitive key match', function () {
    $out = AppointmentRedactor::redactPii(['email' => 'x', 'phoneNumber' => 'y', 'keep' => 'z']);
    assert_eq('***', $out['email']);
    assert_eq('***', $out['phoneNumber']);
    assert_eq('z', $out['keep']);
});

test('AppointmentRedactor: every PII key family is masked', function () {
    $piiKeys = [
        'Email', 'email', 'PhoneNumber', 'phone',
        'PassportSeries', 'DocumentNumber', 'doc',
        'Snils', 'INN', 'BirthDate', 'dob',
        'Address', 'FIO', 'FullName', 'FirstName',
        'LastName', 'MiddleName', 'Patronymic', 'Surname',
    ];
    foreach ($piiKeys as $key) {
        $out = AppointmentRedactor::redactPii([$key => 'secret']);
        assert_eq('***', $out[$key], "key '{$key}' should be masked");
    }
});

test('AppointmentRedactor: a PII-named subtree is masked wholesale', function () {
    // The "document" key itself matches the PII pattern, so the entire subtree
    // is replaced — the most conservative outcome.
    $out = AppointmentRedactor::redactPii(['document' => ['PassportNumber' => '1234', 'IssuedBy' => 'OVD']]);
    assert_eq('***', $out['document']);
});

test('AppointmentRedactor: recurses into non-PII nested objects', function () {
    $in = [
        'ServiceName' => 'X',
        'applicant' => [
            'FullName' => 'Ivanov',
            'PlaceInQueue' => 'Место 2',
            'extra' => ['PassportNumber' => '1234', 'IssuedBy' => 'OVD'],
        ],
    ];
    $out = AppointmentRedactor::redactPii($in);
    assert_eq('X', $out['ServiceName']);
    assert_eq('***', $out['applicant']['FullName']);
    assert_eq('Место 2', $out['applicant']['PlaceInQueue']);
    assert_eq('***', $out['applicant']['extra']['PassportNumber']);
    assert_eq('OVD', $out['applicant']['extra']['IssuedBy']);
});

test('AppointmentRedactor: maskValuesExcept keeps allowlisted values, masks others by value (keys kept)', function () {
    $in = [
        'PlaceInQueue' => 'Место 1',
        'ServiceName' => 'Загран',
        'CanConfirm' => true,
        'FullName' => 'Ivanov',
        'offeredSlotDate' => '2026-06-01T10:00', // unknown field: name revealed, value masked
    ];
    $out = AppointmentRedactor::maskValuesExcept($in);
    assert_eq('Место 1', $out['PlaceInQueue']);
    assert_eq('Загран', $out['ServiceName']);
    assert_eq(true, $out['CanConfirm']);
    assert_eq('***', $out['FullName']);
    // The unknown key stays visible (so we learn the field name) but its value is masked.
    assert_true(array_key_exists('offeredSlotDate', $out));
    assert_eq('***', $out['offeredSlotDate']);
});

test('AppointmentRedactor: maskValuesExcept recurses into allowed nested arrays', function () {
    $out = AppointmentRedactor::maskValuesExcept(
        ['parent' => ['ServiceName' => 'X', 'FullName' => 'Ivanov']],
        ['parent', 'servicename']
    );
    assert_eq('X', $out['parent']['ServiceName']);
    assert_eq('***', $out['parent']['FullName'], 'nested non-allowed value must be masked');
});

test('AppointmentRedactor: maskValuesExcept allowlist is case-insensitive', function () {
    $out = AppointmentRedactor::maskValuesExcept(['ServiceName' => 'X', 'Other' => 'y'], ['SERVICENAME']);
    assert_eq('X', $out['ServiceName']);
    assert_eq('***', $out['Other']);
});

test('AppointmentRedactor: maskValuesExcept recurses into NON-allowed nested arrays (names visible, values masked)', function () {
    $out = AppointmentRedactor::maskValuesExcept([
        'unknownParent' => ['offeredSlotDate' => '2026-06-01', 'ServiceName' => 'Загран'],
    ]);
    assert_true(is_array($out['unknownParent']), 'nested subtree should be kept as a structure, not collapsed to ***');
    assert_true(array_key_exists('offeredSlotDate', $out['unknownParent']), 'nested unknown field NAME must stay visible');
    assert_eq('***', $out['unknownParent']['offeredSlotDate'], 'nested unknown VALUE masked');
    assert_eq('Загран', $out['unknownParent']['ServiceName'], 'nested allowlisted value kept');
});

test('AppointmentRedactor: non-PII keys are never masked', function () {
    $keepKeys = ['ServiceName', 'PlaceInQueue', 'CanConfirm', 'WaitingAppointmentId', 'ScheduledDateTimeString', 'someUnknownSlotField'];
    foreach ($keepKeys as $key) {
        $out = AppointmentRedactor::redactPii([$key => 'value']);
        assert_eq('value', $out[$key], "key '{$key}' should be kept");
    }
});
