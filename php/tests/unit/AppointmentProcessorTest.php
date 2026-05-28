<?php

declare(strict_types=1);

namespace Tests\Processor;

use App\Captcha\CaptchaServiceInterface;
use App\Http\MidpassApiClientInterface;
use App\Notifier\NotifierInterface;
use App\Queue\AppointmentProcessor;
use App\State\StateStore;

final class FakeApi implements MidpassApiClientInterface
{
    public array $confirmed = [];
    public function fetchCaptchaImage(): string { return 'png'; }
    public function login(string $e, string $p, string $c, string $sp): void {}
    public function logout(): void {}
    public function isAuthorized(): bool { return true; }
    public function fetchWaitingAppointmentsPage(int $i, int $s): array { return []; }
    public function fetchWaitingAppointments(int $s = 10, int $m = 100): array { return []; }
    public function confirmWaitingAppointment(string $id, string $captcha): void { $this->confirmed[] = $id; }
}

final class FakeCaptcha implements CaptchaServiceInterface
{
    public int $calls = 0;
    public function solve(string $purpose = 'login'): string { $this->calls++; return 'CODE'; }
}

final class FakeNotifier implements NotifierInterface
{
    public array $messages = [];
    public function isEnabled(): bool { return true; }
    public function send(string $text): bool { $this->messages[] = $text; return true; }
}

$mkState = static fn() => StateStore::withDefaults([]);

test('AppointmentProcessor: records negative probe when CanConfirm is false', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, true);
    $state = $mkState();
    $p->process(['WaitingAppointmentId' => 'id1', 'CanConfirm' => false, 'ServiceName' => 'X', 'PlaceInQueue' => 'Место 100'], $state);

    assert_eq([], $api->confirmed);
    assert_true(isset($state['WaitingAppointments']['LastNegativeProbe']['id1']));
    assert_true(!isset($state['WaitingAppointments']['LastConfirmation']['id1']));
    assert_eq([], $notif->messages);
});

test('AppointmentProcessor: holds (no confirm) when near the front', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, true);
    $state = $mkState();
    $p->process(['WaitingAppointmentId' => 'id1', 'CanConfirm' => true, 'ServiceName' => 'X', 'PlaceInQueue' => 'Место 2'], $state);

    assert_eq([], $api->confirmed, 'must not auto-confirm near the front');
    assert_true(!isset($state['WaitingAppointments']['LastConfirmation']['id1']));
    assert_eq(0, $cap->calls, 'no captcha spent on hold');
});

test('AppointmentProcessor: hold sends a definite "server opened" alert, no confirm', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, true, true);
    $state = $mkState();
    $p->process(['WaitingAppointmentId' => 'id1', 'CanConfirm' => true, 'ServiceName' => 'Загран', 'PlaceInQueue' => 'Место 2', 'FullName' => 'Ivanov', '_raw' => ['canConfirm' => true, 'email' => 'x']], $state);

    assert_eq([], $api->confirmed, 'must not auto-confirm near the front');
    assert_eq(1, count($notif->messages));
    assert_true(str_contains($notif->messages[0], 'СЕРВЕР ОТКРЫЛ'));
    assert_true(str_contains($notif->messages[0], 'Место 2'));
    assert_true(isset($state['Notifications']['LastHoldAlert']['id1']));
    // raw dump masks PII value but keeps key
    assert_true(str_contains($notif->messages[0], 'email'));
    assert_true(!str_contains($notif->messages[0], '"x"'));
});

test('AppointmentProcessor: hold alert is de-duped within 6h', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, true, true);
    $state = $mkState();
    $appt = ['WaitingAppointmentId' => 'id1', 'CanConfirm' => true, 'ServiceName' => 'X', 'PlaceInQueue' => 'Место 1', 'FullName' => 'N'];
    $p->process($appt, $state);
    $p->process($appt, $state);
    assert_eq(1, count($notif->messages), 'second hold alert within 6h must be de-duped');
});

test('AppointmentProcessor: confirms when far from front', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, true);
    $state = $mkState();
    $state['WaitingAppointments']['LastNegativeProbe']['id1'] = 'old';
    $p->process(['WaitingAppointmentId' => 'id1', 'CanConfirm' => true, 'ServiceName' => 'Загран', 'PlaceInQueue' => 'Место 100', 'FullName' => 'Ivanov'], $state);

    assert_eq(['id1'], $api->confirmed);
    assert_true(isset($state['WaitingAppointments']['LastConfirmation']['id1']));
    assert_true(!isset($state['WaitingAppointments']['LastNegativeProbe']['id1']), 'negative probe cleared on confirm');
    assert_eq(1, count($notif->messages));
    assert_true(str_contains($notif->messages[0], 'Место 100'));
    assert_true(str_contains($notif->messages[0], 'Ivanov'));
});

test('AppointmentProcessor: includeName=false omits the name', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, false);
    $state = $mkState();
    $p->process(['WaitingAppointmentId' => 'id1', 'CanConfirm' => true, 'ServiceName' => 'Загран', 'PlaceInQueue' => 'Место 100', 'FullName' => 'Ivanov'], $state);

    assert_true(str_contains($notif->messages[0], 'Место 100'));
    assert_true(!str_contains($notif->messages[0], 'Ivanov'), 'name must be omitted when includeName=false');
});

test('AppointmentProcessor: skips an appointment with a missing id', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 3, true);
    $state = $mkState();
    $p->process(['WaitingAppointmentId' => null, 'CanConfirm' => true, 'ServiceName' => 'X', 'PlaceInQueue' => 'Место 100'], $state);

    assert_eq([], $api->confirmed);
    assert_eq([], $state['WaitingAppointments']['LastConfirmation']);
    assert_eq([], $state['WaitingAppointments']['LastNegativeProbe']);
});

test('AppointmentProcessor: guardPlace 0 disables the hold guard', function () use ($mkState) {
    $api = new FakeApi(); $cap = new FakeCaptcha(); $notif = new FakeNotifier();
    $p = new AppointmentProcessor($api, $cap, $notif, null, 0, true);
    $state = $mkState();
    $p->process(['WaitingAppointmentId' => 'id1', 'CanConfirm' => true, 'ServiceName' => 'X', 'PlaceInQueue' => 'Место 1', 'FullName' => 'N'], $state);

    assert_eq(['id1'], $api->confirmed, 'with guard disabled, even place 1 auto-confirms');
});
