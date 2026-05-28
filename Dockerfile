FROM debian:bookworm-slim AS downloader

ARG MIDPASS_VERSION=1.0.4
# SHA256 of midpass-${MIDPASS_VERSION}-linux.tar.gz — verified before extraction
# to protect the build against a tampered/corrupted upstream release.
ARG MIDPASS_SHA256=15980a634648de5ce29af61a5628e68affc6e41cef2bb9611168a936a7149669

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        tar \
        gzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt
RUN curl -fL -o midpass.tar.gz \
        "https://github.com/gugglegum/midpass/releases/download/${MIDPASS_VERSION}/midpass-${MIDPASS_VERSION}-linux.tar.gz" \
    && echo "${MIDPASS_SHA256}  midpass.tar.gz" | sha256sum -c - \
    && mkdir -p /opt/midpass \
    && tar -xzf midpass.tar.gz -C /opt/midpass --no-same-owner \
    && rm midpass.tar.gz \
    && chmod +x /opt/midpass/confirm-queue.sh \
    && find /opt/midpass -name "*.sh" -exec chmod +x {} \;

FROM ubuntu:22.04

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        tzdata \
        libpng16-16 \
        libjpeg-turbo8 \
        libxml2 \
        libonig5 \
        libcurl3-gnutls \
        libssl3 \
        libzip4 \
        libsqlite3-0 \
        fonts-liberation \
        cron \
        tini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=downloader /opt/midpass /opt/midpass

# Apply local patches that adapt the bot to the new Angular-SPA backend
# (new /api/* endpoints, PNG captcha, JSON payloads, rucaptcha solver).
COPY php/app/Console/Commands/ConfirmQueueCommand.php /opt/midpass/php/app/Console/Commands/ConfirmQueueCommand.php
COPY php/app/CaptchaSolver/CaptchaSolverQMidPass.php /opt/midpass/php/app/CaptchaSolver/CaptchaSolverQMidPass.php
COPY php/app/CaptchaSolver/CaptchaSolverRuCaptcha.php /opt/midpass/php/app/CaptchaSolver/CaptchaSolverRuCaptcha.php
COPY php/app/Notifier/NotifierInterface.php /opt/midpass/php/app/Notifier/NotifierInterface.php
COPY php/app/Notifier/TelegramNotifier.php /opt/midpass/php/app/Notifier/TelegramNotifier.php
COPY php/app/Notifier/NearFrontAlerter.php /opt/midpass/php/app/Notifier/NearFrontAlerter.php
COPY php/app/Captcha/CaptchaServiceInterface.php /opt/midpass/php/app/Captcha/CaptchaServiceInterface.php
COPY php/app/Captcha/CaptchaService.php /opt/midpass/php/app/Captcha/CaptchaService.php
COPY php/app/Config/Env.php /opt/midpass/php/app/Config/Env.php
COPY php/app/Http/Endpoints.php /opt/midpass/php/app/Http/Endpoints.php
COPY php/app/Http/MidpassApiClientInterface.php /opt/midpass/php/app/Http/MidpassApiClientInterface.php
COPY php/app/Http/MidpassApiClient.php /opt/midpass/php/app/Http/MidpassApiClient.php
COPY php/app/Queue/PlaceParser.php /opt/midpass/php/app/Queue/PlaceParser.php
COPY php/app/Queue/AppointmentRedactor.php /opt/midpass/php/app/Queue/AppointmentRedactor.php
COPY php/app/Queue/AppointmentMapper.php /opt/midpass/php/app/Queue/AppointmentMapper.php
COPY php/app/Queue/AppointmentProcessor.php /opt/midpass/php/app/Queue/AppointmentProcessor.php
COPY php/app/Queue/PreCheck.php /opt/midpass/php/app/Queue/PreCheck.php
COPY php/app/State/StateStore.php /opt/midpass/php/app/State/StateStore.php
COPY php/app/State/ProcessLock.php /opt/midpass/php/app/State/ProcessLock.php
COPY php/config/queue.php /opt/midpass/php/config/queue.php

# Linux replacement for the Tahoma font expected by the built-in solver
RUN cp /usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf /opt/midpass/tahoma.ttf

ENV TZ=Europe/Berlin
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

WORKDIR /opt/midpass

VOLUME ["/opt/midpass/logs", "/opt/midpass/temp"]

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
