<?php

declare(strict_types=1);

namespace AdRegister;

final class Config
{
    public static function load(): array
    {
        $domain = (string)Env::get('AD_DOMAIN', 'example.local');
        $baseDn = (string)Env::get('AD_BASE_DN', self::domainToDn($domain));
        $groupCn = (string)Env::get('AD_GROUP_CN', 'VDI_Users');

        return [
            'app' => [
                'name' => (string)Env::get('APP_NAME', 'Cloud Desktop Registration Portal'),
                'env' => (string)Env::get('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                'root' => APP_ROOT,
            ],
            'ad' => [
                'backend' => strtolower((string)Env::get('AD_BACKEND', 'ldap')),
                'host' => (string)Env::get('AD_HOST', 'ad.example.local'),
                'port' => Env::int('AD_PORT', Env::bool('AD_USE_SSL', true) ? 636 : 389),
                'use_ssl' => Env::bool('AD_USE_SSL', true),
                'start_tls' => Env::bool('AD_START_TLS', false),
                'tls_verify' => Env::bool('AD_TLS_VERIFY', true),
                'timeout' => Env::int('AD_TIMEOUT', 8),
                'bind_user' => (string)Env::get('AD_BIND_USER', ''),
                'bind_password' => (string)Env::get('AD_BIND_PASSWORD', ''),
                'domain' => $domain,
                'upn_suffix' => (string)Env::get('AD_UPN_SUFFIX', $domain),
                'base_dn' => $baseDn,
                'user_base_dn' => (string)Env::get('AD_USER_BASE_DN', 'CN=Users,' . $baseDn),
                'group_cn' => $groupCn,
                'group_dn' => (string)Env::get('AD_GROUP_DN', 'CN=' . $groupCn . ',CN=Users,' . $baseDn),
                'enable_account' => Env::bool('AD_ENABLE_ACCOUNT', true),
                'password_never_expires' => Env::bool('AD_PASSWORD_NEVER_EXPIRES', false),
                'change_password_at_next_logon' => Env::bool('AD_CHANGE_PASSWORD_AT_NEXT_LOGON', false),
                'samba' => [
                    'net_path' => (string)Env::get('AD_SAMBA_NET_PATH', '/usr/bin/net'),
                    'domain' => (string)Env::get('AD_SAMBA_DOMAIN', self::defaultNetbiosDomain($domain)),
                    'user' => (string)Env::get('AD_SAMBA_USER', self::defaultSambaUser((string)Env::get('AD_BIND_USER', ''))),
                    'password' => (string)Env::get('AD_SAMBA_PASSWORD', (string)Env::get('AD_BIND_PASSWORD', '')),
                    'timeout' => Env::int('AD_SAMBA_TIMEOUT', 25),
                    'ldap_post_update' => Env::bool('AD_SAMBA_LDAP_POST_UPDATE', true),
                ],
            ],
            'registration' => [
                'invite_code' => (string)Env::get('INVITE_CODE', ''),
                'invite_required' => Env::bool('INVITE_REQUIRED', true),
                'username_min' => Env::int('USERNAME_MIN_LENGTH', 3),
                'username_max' => Env::int('USERNAME_MAX_LENGTH', 20),
                'password_min' => Env::int('PASSWORD_MIN_LENGTH', 10),
                'password_complexity' => Env::bool('PASSWORD_REQUIRE_COMPLEXITY', true),
                'rate_limit_attempts' => Env::int('RATE_LIMIT_ATTEMPTS', 5),
                'rate_limit_window' => Env::int('RATE_LIMIT_WINDOW_SECONDS', 600),
            ],
            'admin' => [
                'username' => (string)Env::get('ADMIN_USERNAME', 'admin'),
                'password_hash' => (string)Env::get('ADMIN_PASSWORD_HASH', ''),
                'password' => (string)Env::get('ADMIN_PASSWORD', ''),
            ],
            'paths' => [
                'log_file' => APP_ROOT . '/storage/logs/app.log',
                'ratelimit_dir' => APP_ROOT . '/storage/ratelimit',
                'tmp_dir' => APP_ROOT . '/storage/tmp',
                'data_dir' => APP_ROOT . '/storage/data',
                'database_file' => APP_ROOT . '/storage/data/app.sqlite',
            ],
        ];
    }

    private static function domainToDn(string $domain): string
    {
        $parts = array_filter(explode('.', $domain), static fn($part) => $part !== '');
        $dnParts = array_map(static fn($part) => 'DC=' . $part, $parts);

        return implode(',', $dnParts);
    }

    private static function defaultNetbiosDomain(string $domain): string
    {
        $first = explode('.', $domain)[0] ?? $domain;

        return strtoupper($first);
    }

    private static function defaultSambaUser(string $bindUser): string
    {
        $bindUser = trim($bindUser);
        if ($bindUser === '') {
            return '';
        }

        if (strpos($bindUser, '@') !== false) {
            return explode('@', $bindUser, 2)[0];
        }

        if (strpos($bindUser, '\\') !== false) {
            $parts = explode('\\', $bindUser);
            return (string)end($parts);
        }

        return $bindUser;
    }
}
