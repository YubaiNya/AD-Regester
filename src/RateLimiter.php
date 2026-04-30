<?php

declare(strict_types=1);

namespace AdRegister;

final class RateLimiter
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }

    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $attempts = [];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $attempts = array_values(array_filter($decoded, static fn($ts) => is_int($ts) && $ts > ($now - $windowSeconds)));
            }
        }

        if (count($attempts) >= $maxAttempts) {
            @file_put_contents($file, json_encode($attempts), LOCK_EX);
            return false;
        }

        $attempts[] = $now;
        @file_put_contents($file, json_encode($attempts), LOCK_EX);

        return true;
    }
}

