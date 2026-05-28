<?php

use App\Config\Env;

return [
    'email' => $_ENV['EMAIL'],
    'password' => $_ENV['PASSWORD'],
    'baseUrl' => Env::str('QUEUE_BASE_URL', 'https://q.midpass.ru'),
    'countryId' =>  $_ENV['COUNTRY_ID'],
    'serviceProviderId' => $_ENV['SERVICE_PROVIDER_ID'],
    'rucaptchaApiKey' => Env::str('RUCAPTCHA_API_KEY'),
    'rucaptchaEndpoint' => Env::str('RUCAPTCHA_ENDPOINT', 'https://api.rucaptcha.com'),
    // null disables the pre-check entirely; an out-of-range value falls back to
    // null (safe: attempt every tick) rather than a broken 0-hour interval.
    'renewalIntervalHours' => Env::intOrNull('RENEWAL_INTERVAL_HOURS', null, 1, 168),
    'negativeProbeCooldownMinutes' => Env::int('NEGATIVE_PROBE_COOLDOWN_MINUTES', 60, 0, 1440),
    'maxSkipAgeHours' => Env::int('MAX_SKIP_AGE_HOURS', 28, 1, 720),
    'telegramBotToken' => Env::str('TELEGRAM_BOT_TOKEN'),
    'telegramChatId' => Env::str('TELEGRAM_CHAT_ID'),
    // Include the applicant's name in Telegram messages (the user's own chat).
    // Set TELEGRAM_INCLUDE_NAME=0 to keep names out of outbound messages.
    'telegramIncludeName' => Env::bool('TELEGRAM_INCLUDE_NAME', true),
    'telegramAlertPlaceThreshold' => Env::int('TELEGRAM_ALERT_PLACE_THRESHOLD', 5, 0, 100000),
    // Include the redacted raw appointment dump in the near-front alert (reveals
    // unknown field NAMES to spot the offered-slot field; values are masked).
    // Set TELEGRAM_DUMP_RAW=0 to omit it.
    'telegramDumpRaw' => Env::bool('TELEGRAM_DUMP_RAW', true),
    // When place in queue is at/under this value the bot will NOT auto-confirm
    // (to avoid silently accepting an offered appointment slot) and instead only
    // sends a Telegram alert so the user confirms manually. 0 disables the guard.
    // A non-numeric/unknown place also triggers the hold (fail-safe).
    'autoConfirmGuardPlace' => Env::int('AUTO_CONFIRM_GUARD_PLACE', 3, 0, 100000),
];
