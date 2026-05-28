<?php

declare(strict_types=1);

namespace App\State;

/**
 * Loads and atomically persists the bot's JSON state file. Ensures the expected
 * sub-arrays always exist so callers never deal with missing keys.
 */
final class StateStore
{
    public function __construct(private string $file)
    {
    }

    public function load(): array
    {
        $state = [];
        if (is_file($this->file)) {
            $decoded = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
        return self::withDefaults($state);
    }

    public function save(array $state): void
    {
        // Unique temp name so an overlapping process (e.g. a manual run racing
        // the cron loop) cannot clobber another writer's temp file. The rename
        // onto the final path is atomic on the same filesystem.
        $tmp = $this->file . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to persist state file: {$this->file}");
        }
    }

    public static function withDefaults(array $state): array
    {
        $state['WaitingAppointments'] ??= [];
        $state['WaitingAppointments']['LastConfirmation'] ??= [];
        $state['WaitingAppointments']['LastNegativeProbe'] ??= [];
        $state['Notifications'] ??= [];
        $state['Notifications']['LastPlaceAlert'] ??= [];
        $state['Notifications']['LastHoldAlert'] ??= [];
        return $state;
    }
}
