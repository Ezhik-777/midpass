<?php

declare(strict_types=1);

use App\Exceptions\PermanentException;
use App\Http\Endpoints;
use App\Http\MidpassApiClient;

// These tests need Guzzle (vendor). When run without composer install (pure
// bundled-PHP mode) they are skipped so the rest of the suite still runs.
$guzzleAvailable = class_exists(\GuzzleHttp\Handler\MockHandler::class);

$makeClient = static function (array $responses) {
    $mock = new \GuzzleHttp\Handler\MockHandler($responses);
    $stack = \GuzzleHttp\HandlerStack::create($mock);
    return new MidpassApiClient(new Endpoints('https://q.midpass.ru'), null, $stack);
};

$json = static fn(array $body, array $headers = []) =>
    new \GuzzleHttp\Psr7\Response(200, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($body));

if (!$guzzleAvailable) {
    test('MidpassApiClient: SKIPPED (Guzzle not loaded — run after composer install)', function () {
        assert_true(true);
    });
    return;
}

$pngBytes = "\x89PNG\r\n\x1a\n" . 'fake-image-data';

test('MidpassApiClient: fetchCaptchaImage returns raw PNG bytes', function () use ($makeClient, $pngBytes) {
    $client = $makeClient([new \GuzzleHttp\Psr7\Response(200, [], $pngBytes)]);
    assert_eq($pngBytes, $client->fetchCaptchaImage());
});

test('MidpassApiClient: fetchCaptchaImage rejects non-PNG body', function () use ($makeClient) {
    // Five non-PNG responses (the client retries transient-only, but throws the
    // non-PNG error which is not retried, so one is enough — queue a couple).
    $client = $makeClient([
        new \GuzzleHttp\Psr7\Response(200, [], 'not-a-png'),
    ]);
    $threw = false;
    try {
        $client->fetchCaptchaImage();
    } catch (\Exception) {
        $threw = true;
    }
    assert_true($threw);
});

test('MidpassApiClient: login success sets authorized when session cookie present', function () use ($makeClient, $json) {
    $client = $makeClient([
        $json(['result' => true], ['Set-Cookie' => '.AspNetCore.Session=xyz; path=/']),
    ]);
    $client->login('e@x', 'pw', 'CAP123', 'sp-id');
    assert_true($client->isAuthorized());
});

test('MidpassApiClient: login missing session cookie throws', function () use ($makeClient, $json) {
    $client = $makeClient([$json(['result' => true])]);
    $threw = false;
    try {
        $client->login('e@x', 'pw', 'CAP123', 'sp-id');
    } catch (\Exception) {
        $threw = true;
    }
    assert_true($threw);
    assert_true($client->isAuthorized() === false);
});

test('MidpassApiClient: bad credentials -> PermanentException', function () use ($makeClient, $json) {
    $client = $makeClient([$json(['result' => false, 'text' => 'Controller.Account.Validation.WrongCredentials'])]);
    $isPermanent = false;
    try {
        $client->login('e@x', 'pw', 'CAP', 'sp');
    } catch (PermanentException) {
        $isPermanent = true;
    } catch (\Throwable) {
        $isPermanent = false;
    }
    assert_true($isPermanent);
});

test('MidpassApiClient: captcha error is temporary (not Permanent)', function () use ($makeClient, $json) {
    $client = $makeClient([$json(['result' => false, 'text' => 'Controller.Account.Validation.WrongCaptcha'])]);
    $permanent = false;
    $threw = false;
    try {
        $client->login('e@x', 'pw', 'CAP', 'sp');
    } catch (PermanentException) {
        $permanent = true;
        $threw = true;
    } catch (\Exception) {
        $threw = true;
    }
    assert_true($threw);
    assert_true($permanent === false);
});

test('MidpassApiClient: fetchWaitingAppointmentsPage maps items', function () use ($makeClient, $json) {
    $client = $makeClient([$json(['Items' => [['id' => 'a', 'placeInQueueString' => 'Место 9', 'canConfirm' => false]]])]);
    $page = $client->fetchWaitingAppointmentsPage(0, 10);
    assert_eq(1, count($page));
    assert_eq('a', $page[0]['WaitingAppointmentId']);
    assert_eq('Место 9', $page[0]['PlaceInQueue']);
});

test('MidpassApiClient: fetchWaitingAppointments paginates until short page', function () use ($makeClient, $json) {
    $client = $makeClient([
        $json(['Items' => [['id' => 'a'], ['id' => 'b']]]), // full page (size 2)
        $json(['Items' => [['id' => 'c']]]),                 // short page -> stop
    ]);
    $all = $client->fetchWaitingAppointments(2, 100);
    assert_eq(3, count($all));
    assert_eq(['a', 'b', 'c'], \App\Queue\AppointmentMapper::ids($all));
});

test('MidpassApiClient: confirm success does not throw', function () use ($makeClient, $json) {
    $client = $makeClient([$json(['IsSuccessful' => true])]);
    $client->confirmWaitingAppointment('id1', 'CAP');
    assert_true(true);
});

test('MidpassApiClient: confirm failure throws with server message', function () use ($makeClient, $json) {
    $client = $makeClient([$json(['IsSuccessful' => false, 'ErrorMessage' => 'nope'])]);
    $msg = null;
    try {
        $client->confirmWaitingAppointment('id1', 'CAP');
    } catch (\Exception $e) {
        $msg = $e->getMessage();
    }
    assert_eq('nope', $msg);
});

test('MidpassApiClient: logout never throws and clears authorized', function () use ($makeClient, $json) {
    $client = $makeClient([
        $json(['result' => true], ['Set-Cookie' => '.AspNetCore.Session=xyz; path=/']),
        new \GuzzleHttp\Psr7\Response(500, [], 'err'),
    ]);
    $client->login('e@x', 'pw', 'CAP', 'sp');
    $client->logout();
    assert_true($client->isAuthorized() === false);
});

test('MidpassApiClient: sends Origin and Referer headers', function () use ($json) {
    $history = [];
    $mock = new \GuzzleHttp\Handler\MockHandler([$json(['Items' => []])]);
    $stack = \GuzzleHttp\HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($history));
    $client = new MidpassApiClient(new Endpoints('https://q.midpass.ru'), null, $stack);

    $client->fetchWaitingAppointmentsPage(0, 10);

    $request = $history[0]['request'];
    assert_eq('https://q.midpass.ru', $request->getHeaderLine('Origin'));
    assert_eq('https://q.midpass.ru/ru/Appointments/WaitingList', $request->getHeaderLine('Referer'));
    assert_eq('XMLHttpRequest', $request->getHeaderLine('X-Requested-With'));
});

test('MidpassApiClient: login retries a transient 500 then succeeds', function () use ($makeClient, $json) {
    $client = $makeClient([
        new \GuzzleHttp\Psr7\Response(500, [], 'temporarily down'),
        $json(['result' => true], ['Set-Cookie' => '.AspNetCore.Session=xyz; path=/']),
    ]);
    $client->login('e@x', 'pw', 'CAP', 'sp');
    assert_true($client->isAuthorized());
});

test('MidpassApiClient: pagination truncation logs a warning', function () use ($json) {
    $warnings = [];
    $logger = new class($warnings) extends \Psr\Log\AbstractLogger {
        public function __construct(public array &$warnings) {}
        public function log($level, $message, array $context = []): void {
            if ($level === \Psr\Log\LogLevel::WARNING) {
                $this->warnings[] = (string) $message;
            }
        }
    };
    $mock = new \GuzzleHttp\Handler\MockHandler([
        $json(['Items' => [['id' => 'a']]]), // full page (size 1)
        $json(['Items' => [['id' => 'b']]]), // full page -> hits maxPages=2 bound
    ]);
    $stack = \GuzzleHttp\HandlerStack::create($mock);
    $client = new MidpassApiClient(new Endpoints('https://q.midpass.ru'), $logger, $stack);

    $all = $client->fetchWaitingAppointments(1, 2);
    assert_eq(2, count($all));
    $hit = false;
    foreach ($warnings as $w) {
        if (str_contains($w, 'safety bound')) {
            $hit = true;
        }
    }
    assert_true($hit, 'expected a truncation warning');
});
