#!/usr/bin/env bash
set -euo pipefail

MODE="${MODE:-cron}"
INTERVAL_MINUTES="${INTERVAL_MINUTES:-60}"

run_once() {
    echo "[$(date -Iseconds)] === Запуск confirm-queue ==="
    cd /opt/midpass
    # Вызываем PHP напрямую, чтобы пробросить exit code. Стандартный
    # confirm-queue.sh заканчивается `read -n 1 ...` и затирает код возврата.
    set +e
    ./bin/php -c ./bin/php.ini -n ./php/console.php "confirm-queue"
    rc=$?
    set -e
    if [[ $rc -eq 0 ]]; then
        echo "[$(date -Iseconds)] === OK ==="
    else
        echo "[$(date -Iseconds)] === FAIL (exit=$rc) ===" >&2
        if [[ "$MODE" == "oneshot" ]]; then
            exit $rc
        fi
    fi
}

case "$MODE" in
    oneshot)
        run_once
        ;;
    cron)
        JITTER=$((RANDOM % 300))
        echo "[$(date -Iseconds)] Старт в режиме loop, интервал ${INTERVAL_MINUTES} мин. Первый запуск через ${JITTER}с..."
        sleep "$JITTER"
        while true; do
            run_once
            EXTRA=$((RANDOM % 600 - 300))
            SLEEP_SEC=$((INTERVAL_MINUTES * 60 + EXTRA))
            [[ $SLEEP_SEC -lt 600 ]] && SLEEP_SEC=600
            echo "[$(date -Iseconds)] Следующий запуск через ${SLEEP_SEC}с"
            sleep "$SLEEP_SEC"
        done
        ;;
    *)
        echo "Неизвестный MODE='$MODE'. Используйте 'cron' или 'oneshot'." >&2
        exit 2
        ;;
esac
