<?php

declare(strict_types=1);

namespace AdRegister;

final class AdminAuth
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function username(): string
    {
        return (string)($this->config['admin']['username'] ?? 'admin');
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['admin_user']) && $_SESSION['admin_user'] === $this->username();
    }

    public function login(string $username, string $password): bool
    {
        if (!hash_equals($this->username(), trim($username))) {
            return false;
        }

        $hash = (string)($this->config['admin']['password_hash'] ?? '');
        $plain = (string)($this->config['admin']['password'] ?? '');
        $ok = false;
        if ($hash !== '') {
            $ok = password_verify($password, $hash);
        } elseif ($plain !== '') {
            $ok = hash_equals($plain, $password);
        }

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_user'] = $this->username();
        }

        return $ok;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_user']);
    }

    public function ensureConfigured(): bool
    {
        return (string)($this->config['admin']['password_hash'] ?? '') !== ''
            || (string)($this->config['admin']['password'] ?? '') !== '';
    }
}
