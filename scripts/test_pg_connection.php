<?php

$passwords = ['root', 'postgres', 'admin', '', 'toor', '1234', 'password'];
$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=PFE_5G';
$user = 'postgres';

foreach ($passwords as $pwd) {
    try {
        $pdo = new PDO($dsn, $user, $pwd);
        $pdo->query('SELECT 1');
        echo "OK password=" . var_export($pwd, true) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        echo "FAIL password=" . var_export($pwd, true) . " - " . $e->getMessage() . PHP_EOL;
    }
}

exit(1);

