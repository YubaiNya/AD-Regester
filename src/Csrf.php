<?php

declare(strict_types=1);

namespace AdRegister;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = self::randomToken();
        }

        return (string)$_SESSION['_csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        if (!is_string($token) || $token === '' || empty($_SESSION['_csrf_token'])) {
            return false;
        }

        return hash_equals((string)$_SESSION['_csrf_token'], $token);
    }

    private static function randomToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return sha1(uniqid('', true) . mt_rand());
        }
    }
}

