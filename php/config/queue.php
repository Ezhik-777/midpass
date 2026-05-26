<?php

return [
    'email' => $_ENV['EMAIL'],
    'password' => $_ENV['PASSWORD'],
    'countryId' =>  $_ENV['COUNTRY_ID'],
    'serviceProviderId' => $_ENV['SERVICE_PROVIDER_ID'],
    'rucaptchaApiKey' => $_ENV['RUCAPTCHA_API_KEY'] ?? '',
    'rucaptchaEndpoint' => $_ENV['RUCAPTCHA_ENDPOINT'] ?? 'https://api.rucaptcha.com',
];
