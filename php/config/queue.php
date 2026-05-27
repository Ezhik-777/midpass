<?php

return [
    'email' => $_ENV['EMAIL'],
    'password' => $_ENV['PASSWORD'],
    'countryId' =>  $_ENV['COUNTRY_ID'],
    'serviceProviderId' => $_ENV['SERVICE_PROVIDER_ID'],
    'rucaptchaApiKey' => $_ENV['RUCAPTCHA_API_KEY'] ?? '',
    'rucaptchaEndpoint' => $_ENV['RUCAPTCHA_ENDPOINT'] ?? 'https://api.rucaptcha.com',
    'renewalIntervalHours' => isset($_ENV['RENEWAL_INTERVAL_HOURS']) && $_ENV['RENEWAL_INTERVAL_HOURS'] !== ''
        ? (int) $_ENV['RENEWAL_INTERVAL_HOURS']
        : null,
    'negativeProbeCooldownMinutes' => isset($_ENV['NEGATIVE_PROBE_COOLDOWN_MINUTES']) && $_ENV['NEGATIVE_PROBE_COOLDOWN_MINUTES'] !== ''
        ? (int) $_ENV['NEGATIVE_PROBE_COOLDOWN_MINUTES']
        : 60,
    'maxSkipAgeHours' => isset($_ENV['MAX_SKIP_AGE_HOURS']) && $_ENV['MAX_SKIP_AGE_HOURS'] !== ''
        ? (int) $_ENV['MAX_SKIP_AGE_HOURS']
        : 72,
];
