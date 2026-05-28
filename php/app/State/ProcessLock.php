<?php

declare(strict_types=1);

namespace App\State;

/**
 * Advisory exclusive lock (flock) so two overlapping runs — e.g. a manual run
 * racing the cron loop — cannot both load-modify-save the state file and lose
 * each other's updates. Non-blocking: a second runner skips instead of waiting.
 */
final class ProcessLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $path)
    {
    }

    /**
     * @return bool true if acquired, false if another process holds the lock.
     * @throws \RuntimeException if the lock file cannot be opened (setup error,
     *         e.g. a missing/unwritable directory) — distinct from contention.
     */
    public function tryAcquire(): bool
    {
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open lock file (check the temp directory exists and is writable): {$this->path}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false; // held by another process — normal contention
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
