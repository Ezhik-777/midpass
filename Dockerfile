FROM debian:bookworm-slim AS downloader

ARG MIDPASS_VERSION=1.0.4

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        tar \
        gzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt
RUN curl -fL -o midpass.tar.gz \
        "https://github.com/gugglegum/midpass/releases/download/${MIDPASS_VERSION}/midpass-${MIDPASS_VERSION}-linux.tar.gz" \
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
