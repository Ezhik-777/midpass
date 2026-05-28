<?php

declare(strict_types=1);

use App\Http\Endpoints;

test('Endpoints: builds API URLs from base', function () {
    $e = new Endpoints('https://q.midpass.ru');
    assert_eq('https://q.midpass.ru', $e->origin());
    assert_eq('https://q.midpass.ru/api/Account/CaptchaImage?123', $e->captchaImage(123));
    assert_eq('https://q.midpass.ru/api/Account/DoPrivatePersonLogOn', $e->login());
    assert_eq('https://q.midpass.ru/api/Account/Logoff', $e->logoff());
    assert_eq('https://q.midpass.ru/api/Appointments/FindWaitingAppointments?pageIndex=0&pageSize=10', $e->findWaiting('pageIndex=0&pageSize=10'));
    assert_eq('https://q.midpass.ru/api/Appointments/ConfirmWaitingAppointments', $e->confirm());
    assert_eq('https://q.midpass.ru/ru/account/PrivatePersonLogOn', $e->loginReferer());
    assert_eq('https://q.midpass.ru/ru/Appointments/WaitingList', $e->waitingListReferer());
});

test('Endpoints: trims trailing slash from base', function () {
    $e = new Endpoints('https://staging.example.com/');
    assert_eq('https://staging.example.com', $e->origin());
    assert_eq('https://staging.example.com/api/Account/Logoff', $e->logoff());
});
