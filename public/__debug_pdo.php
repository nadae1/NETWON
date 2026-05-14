<?php

header('Content-Type: application/json');

echo json_encode([
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'php_binary' => PHP_BINARY,
    'loaded_ini' => php_ini_loaded_file(),
    'extension_dir' => ini_get('extension_dir'),
    'pdo_pgsql' => extension_loaded('pdo_pgsql'),
    'pgsql' => extension_loaded('pgsql'),
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'extensions' => array_values(array_filter(get_loaded_extensions(), static fn (string $e): bool => str_contains($e, 'pgsql') || str_contains($e, 'pdo'))),
], JSON_PRETTY_PRINT);

