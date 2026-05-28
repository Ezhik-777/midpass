<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Masks personal data (email, phone, name, document/passport, birth date,
 * address, etc.) in a raw appointment item before it is sent to an external
 * channel (Telegram). Non-PII fields — including the service name, place and
 * any unknown offered-slot field — are preserved for diagnosis.
 */
final class AppointmentRedactor
{
    // Matched case-insensitively against each key. Bare "name" is deliberately
    // excluded so ServiceName is kept; specific name parts are matched instead.
    private const PII_KEY_PATTERN =
        '/email|phone|passport|document|\bdoc\b|snils|\binn\b|birth|\bdob\b|address|\bfio\b|fullname|firstname|lastname|middlename|patronymic|surname/i';

    // Non-PII fields whose VALUES are safe to show in an alert. Everything else
    // has its value masked while its key is preserved — so an unknown offered-slot
    // field is revealed by name without leaking any value.
    public const ALERT_ALLOWLIST = [
        'waitingappointmentid', 'id', 'placeinqueue', 'placeinqueuestring',
        'canconfirm', 'cancancel', 'serviceid', 'servicename',
        'serviceprovidercode', 'scheduleddatetimestring', 'scheduleddatetime',
        // Non-PII operational metrics (safe to show; help read the situation).
        'daysofregistration', 'daysofconfirmation', 'countofconfirmations',
        'countofactiveappointments', 'countofcancels', 'orderbyvalue',
    ];

    /**
     * Allowlist redaction for outbound alerts: keep values only for known-safe
     * keys; mask every other scalar value while keeping its key visible. Recurses
     * into EVERY nested array (allowed-keyed or not) so nested field names stay
     * visible while their non-allowlisted values are still masked — deep PII
     * cannot slip through. The allowlist is matched case-insensitively.
     *
     * @param string[] $allow allowed key names (any case)
     */
    public static function maskValuesExcept(array $raw, array $allow = self::ALERT_ALLOWLIST): array
    {
        $allowLower = array_map('strtolower', $allow);
        $out = [];
        foreach ($raw as $key => $value) {
            $allowed = in_array(strtolower((string) $key), $allowLower, true);
            if (is_array($value)) {
                // Always recurse into arrays (allowed or not) so nested field NAMES
                // stay visible — only their non-allowlisted VALUES get masked.
                $out[$key] = self::maskValuesExcept($value, $allow);
            } else {
                $out[$key] = $allowed ? $value : '***';
            }
        }
        return $out;
    }

    public static function redactPii(array $raw): array
    {
        foreach ($raw as $key => $value) {
            if (preg_match(self::PII_KEY_PATTERN, (string) $key) === 1) {
                $raw[$key] = '***';
            } elseif (is_array($value)) {
                // Recurse so nested applicant/document sub-objects are masked too.
                $raw[$key] = self::redactPii($value);
            }
        }
        return $raw;
    }
}
