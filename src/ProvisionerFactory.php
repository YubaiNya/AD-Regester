<?php

declare(strict_types=1);

namespace AdRegister;

final class ProvisionerFactory
{
    public static function make(array $config)
    {
        $backend = strtolower((string)($config['ad']['backend'] ?? 'ldap'));
        if ($backend === 'samba' || $backend === 'rpc') {
            return new SambaAdService($config);
        }

        return new AdService($config);
    }
}

