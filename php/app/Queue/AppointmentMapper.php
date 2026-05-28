<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Pure normalization of FindWaitingAppointments API items into the internal
 * appointment shape the command works with. Kept dependency-free so the schema
 * handling (key fallbacks, missing "Items" section) is unit-testable.
 */
final class AppointmentMapper
{
    /** @return list<array<string,mixed>> */
    public static function fromResponse(array $data): array
    {
        $itemsKey = array_key_exists('Items', $data)
            ? 'Items'
            : (array_key_exists('items', $data) ? 'items' : null);
        if ($itemsKey === null) {
            // PII-safe: never dump the whole body — it may contain names/emails.
            throw new \RuntimeException('Missing "Items" section in the FindWaitingAppointments response (keys: ' . implode(',', array_keys($data)) . ')');
        }
        if (!is_array($data[$itemsKey])) {
            throw new \RuntimeException('"Items" property is not array in the FindWaitingAppointments response');
        }

        $out = [];
        foreach ($data[$itemsKey] as $item) {
            $out[] = self::map(is_array($item) ? $item : []);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function map(array $item): array
    {
        return [
            'WaitingAppointmentId' => $item['WaitingAppointmentId'] ?? $item['waitingAppointmentId'] ?? $item['id'] ?? null,
            'PlaceInQueue' => $item['PlaceInQueue'] ?? $item['placeInQueue'] ?? $item['placeInQueueString'] ?? null,
            // Normalized to a strict bool so API drift (e.g. a truthy string)
            // cannot silently change the confirm/probe flow downstream.
            'CanConfirm' => ($item['CanConfirm'] ?? $item['canConfirm'] ?? false) === true,
            'CanCancel' => ($item['CanCancel'] ?? $item['canCancel'] ?? false) === true,
            'Email' => $item['Email'] ?? $item['email'] ?? null,
            'FullName' => $item['FullName'] ?? $item['fullName'] ?? null,
            'PhoneNumber' => $item['PhoneNumber'] ?? $item['phoneNumber'] ?? null,
            'ScheduledDateTimeString' => $item['ScheduledDateTimeString'] ?? $item['scheduledDateTimeString'] ?? $item['scheduledDateTime'] ?? null,
            'ServiceProviderCode' => $item['ServiceProviderCode'] ?? $item['serviceProviderCode'] ?? null,
            'ServiceId' => $item['ServiceId'] ?? $item['serviceId'] ?? null,
            'ServiceName' => $item['ServiceName'] ?? $item['serviceName'] ?? $item['applicantInfo'] ?? '(unknown service)',
            // Full raw item — used only to dump complete (PII-redacted) data into
            // the "near the front" Telegram alert so an offered appointment slot
            // (whatever its field name turns out to be) is visible. Not persisted.
            '_raw' => $item,
        ];
    }

    /** @return list<int|string|null> ids only — safe to log (no PII) */
    public static function ids(array $mapped): array
    {
        return array_map(static fn($a) => $a['WaitingAppointmentId'] ?? null, $mapped);
    }
}
