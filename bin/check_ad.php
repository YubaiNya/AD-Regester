<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use AdRegister\ProvisionerFactory;

$config = app_config();

echo "== AD registration portal check ==\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
echo 'ldap extension: ' . (extension_loaded('ldap') ? 'loaded' : 'NOT LOADED') . "\n";

try {
    $info = ProvisionerFactory::make($config)->diagnose();
    foreach ($info as $key => $value) {
        echo $key . ': ' . $value . "\n";
    }
    echo "OK: LDAP bind, Base DN and VDI group check completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
