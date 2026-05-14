<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class PythonApiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl
    ) {
    }

    /**
     * @throws \Exception
     */
    public function traiterFichiers(
        UploadedFile $trafic,
        UploadedFile $port,
        UploadedFile $liaison
    ): array {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/traiter', [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'trafic' => fopen($trafic->getPathname(), 'r'),
                    'port' => fopen($port->getPathname(), 'r'),
                    'type_liaison' => fopen($liaison->getPathname(), 'r'),
                ],
                'timeout' => 600,
                'max_duration' => 900,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \Exception(sprintf('API Python a retourné le code %d', $statusCode));
            }

            $data = $response->toArray(false);

            if (!isset($data['status'])) {
                throw new \Exception('Réponse API invalide: champ status manquant');
            }

            if ($data['status'] !== 'success') {
                throw new \Exception($data['message'] ?? 'Erreur inconnue du service Python');
            }

            return $data;

        } catch (TransportExceptionInterface $e) {
            throw new \Exception('Impossible de contacter le service Python: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw $e;
        }
    }
}