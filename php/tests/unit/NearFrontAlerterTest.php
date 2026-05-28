<?php

declare(strict_types=1);

namespace Tests\Alerter;

use App\Notifier\NearFrontAlerter;
use App\Notifier\NotifierInterface;
use App\State\StateStore;

final class FakeNotifier implements NotifierInterface
{
    public array $messages = [];
    public bool $enabled = true;
    public bool $deliver = true;
    public function isEnabled(): bool { return $this->enabled; }
    public function send(string $text): bool { $this->messages[] = $text; return $this->deliver; }
}

$mkState = static fn() => StateStore::withDefaults([]);
$appt = static fn(int|string|null $place, string $id = 'id1', array $extra = []) => array_merge([
    'WaitingAppointmentId' => $id,
    'PlaceInQueue' => $place,
    'ServiceName' => 'Загран',
    'FullName' => 'Ivanov',
    '_raw' => ['PlaceInQueue' => $place, 'FullName' => 'Ivanov', 'offeredSlotDate' => '2026-06-01'],
], $extra);

test('NearFrontAlerter: alerts at/under threshold and records dedup state', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 2'), $state);
    assert_eq(1, count($n->messages));
    assert_true(str_contains($n->messages[0], 'Место 2'));
    assert_true(isset($state['Notifications']['LastPlaceAlert']['id1']));
});

test('NearFrontAlerter: no alert above threshold', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 100'), $state);
    assert_eq([], $n->messages);
});

test('NearFrontAlerter: skips appointment without id', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 1', ''), $state);
    assert_eq([], $n->messages);
});

test('NearFrontAlerter: de-dupes the same place within 12h', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 2'), $state);
    $a->maybeAlert($appt('Место 2'), $state);
    assert_eq(1, count($n->messages), 'second identical alert should be de-duped');
});

test('NearFrontAlerter: dumpRaw off omits the data dump', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, false, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 2'), $state);
    assert_true(str_contains($n->messages[0], 'TELEGRAM_DUMP_RAW=0'));
});

test('NearFrontAlerter: dump reveals unknown field name but masks its value', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 2'), $state);
    assert_true(str_contains($n->messages[0], 'offeredSlotDate'), 'unknown field name should be visible');
    assert_true(!str_contains($n->messages[0], '2026-06-01'), 'unknown field value must be masked');
});

test('NearFrontAlerter: a failed delivery is not de-duped (retried next tick)', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $n->deliver = false; // simulate Telegram failure
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('Место 2'), $state);
    assert_true(!isset($state['Notifications']['LastPlaceAlert']['id1']), 'must not record de-dup on failed send');
    // next tick: delivery recovers -> alert is attempted again
    $n->deliver = true;
    $a->maybeAlert($appt('Место 2'), $state);
    assert_eq(2, count($n->messages));
    assert_true(isset($state['Notifications']['LastPlaceAlert']['id1']));
});

test('NearFrontAlerter: corrupted dedup timestamp is treated as stale', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $state['Notifications']['LastPlaceAlert']['id1'] = ['place' => 2, 'ts' => 'not-a-date'];
    $a->maybeAlert($appt('Место 2'), $state);
    assert_eq(1, count($n->messages), 'invalid stored timestamp must not suppress the alert');
});

test('NearFrontAlerter: alerts when place cannot be parsed (anomaly)', function () use ($mkState, $appt) {
    $n = new FakeNotifier();
    $a = new NearFrontAlerter($n, null, 5, true, true);
    $state = $mkState();
    $a->maybeAlert($appt('нет данных'), $state);
    assert_eq(1, count($n->messages));
});
