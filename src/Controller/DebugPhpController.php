<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class DebugPhpController extends AbstractController
{
    #[Route('/__debug/php', name: 'debug_php_extensions', methods: ['GET'])]
    public function phpExtensions(): JsonResponse
    {
        // Safe diagnostic endpoint (no DB).
        // Only enabled in dev environment.
        if ($this->getParameter('kernel.environment') !== 'dev') {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'php_binary' => PHP_BINARY,
            'loaded_ini' => php_ini_loaded_file(),
            'scanned_ini' => php_ini_scanned_files(),
            'extension_dir' => ini_get('extension_dir'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'pgsql' => extension_loaded('pgsql'),
            'pdo_drivers' => \PDO::getAvailableDrivers(),
            'loaded_extensions' => array_values(array_filter(get_loaded_extensions(), static function (string $ext): bool {
                return str_contains($ext, 'pgsql') || str_contains($ext, 'pdo');
            })),
        ]);
    }
}

