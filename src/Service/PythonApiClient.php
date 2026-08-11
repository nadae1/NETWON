<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PythonApiClient
{
    private HttpClientInterface $httpClient;
    private FastApiPortDetector $detector;
    private ?string $baseUrl;

    public function __construct(HttpClientInterface $httpClient, FastApiPortDetector $detector)
    {
        $this->httpClient = $httpClient;
        $this->detector = $detector;
        $this->baseUrl = $detector->getApiUrl();
    }

    public function isAvailable(): bool
    {
        return $this->baseUrl !== null;
    }

    private function buildMultipartBody(array $files): array
    {
        $boundary = uniqid('boundary_', true);
        $body = '';
        foreach ($files as $fieldName => $info) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$fieldName\"; filename=\"{$info['filename']}\"\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= file_get_contents($info['path']) . "\r\n";
        }
        $body .= "--$boundary--\r\n";
        return ['body' => $body, 'boundary' => $boundary];
    }

    private function postMultipart(string $endpoint, array $fields): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        $boundary = uniqid('boundary_', true);
        $body = '';
        foreach ($fields as $name => $content) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Disposition: form-data; name=\"$name\"; filename=\"file.xlsx\"\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= $content . "\r\n";
        }
        $body .= "--$boundary--\r\n";

        $headers = ['Content-Type' => 'multipart/form-data; boundary=' . $boundary];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . $endpoint, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 120,
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Erreur communication : ' . $e->getMessage()];
        }
    }

    public function processFiles(UploadedFile $trafic, ?UploadedFile $port, ?UploadedFile $liaison, ?UploadedFile $gps = null): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        $files = [
            'trafic' => ['path' => $trafic->getPathname(), 'filename' => $trafic->getClientOriginalName()],
        ];
        if ($port) {
            $files['port'] = ['path' => $port->getPathname(), 'filename' => $port->getClientOriginalName()];
        }
        if ($liaison) {
            $files['type_liaison'] = ['path' => $liaison->getPathname(), 'filename' => $liaison->getClientOriginalName()];
        }
        if ($gps) {
            $files['gps'] = ['path' => $gps->getPathname(), 'filename' => $gps->getClientOriginalName()];
        }

        $multipart = $this->buildMultipartBody($files);
        $headers = ['Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary']];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/traiter', [
                'headers' => $headers,
                'body' => $multipart['body'],
                'timeout' => 300,
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Erreur communication : ' . $e->getMessage()];
        }
    }

    /**
     * ✅ NOUVEAU : Importe le fichier capacités unifié (trafic + capacités)
     */
    public function processCapaciteUnifiee(string $fileContent): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        return $this->postMultipart('/capacite/unifie', ['fichier' => $fileContent]);
    }

    /**
     * ✅ NOUVEAU : Importe les données de ports (fichier optionnel séparé)
     */
    public function importPortData(string $fileContent): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        return $this->postMultipart('/update_port_data', ['fichier' => $fileContent]);
    }

    /**
     * ✅ NOUVEAU : Importe les types de liaison (fichier optionnel séparé)
     */
    public function importTypeLiaison(string $fileContent): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        return $this->postMultipart('/update_type_liaison', ['fichier' => $fileContent]);
    }

    /**
     * Import capacité FO (legacy - conservé pour rétro-compatibilité)
     */
    public function importCapaciteFo(string $fileContent): array
    {
        return $this->postMultipart('/capacite/fo', ['fichier' => $fileContent]);
    }

    /**
     * Import capacité FH (legacy - conservé pour rétro-compatibilité)
     */
    public function importCapaciteFh(string $fileContent): array
    {
        return $this->postMultipart('/capacite/fh', ['fichier' => $fileContent]);
    }

    /**
     * Import capacité Backbone (legacy - conservé pour rétro-compatibilité)
     */
    public function importCapaciteBackbone(string $fileContent): array
    {
        return $this->postMultipart('/capacite/backbone', ['fichier' => $fileContent]);
    }

    /**
     * Import GPS (utilitaire)
     */
    public function importGps(UploadedFile $file): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        $files = [
            'fichier' => ['path' => $file->getPathname(), 'filename' => $file->getClientOriginalName()],
        ];

        $multipart = $this->buildMultipartBody($files);
        $headers = ['Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary']];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/gps/import', [
                'headers' => $headers,
                'body' => $multipart['body'],
                'timeout' => 120,
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Mise à jour manuelle d'un site
     */
    public function updateSiteManuel(string $site, ?float $capaciteTdd, ?float $capaciteFdd, ?string $typeTrans): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/site/manuel', [
                'body' => [
                    'site' => $site,
                    'capacite_tdd' => $capaciteTdd,
                    'capacite_fdd' => $capaciteFdd,
                    'type_trans' => $typeTrans,
                ],
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Import DropCong/Indicateurs
     */
    public function importDropcong(UploadedFile $fichier, ?UploadedFile $drop1 = null, ?UploadedFile $drop2 = null): array
    {
        if (!$this->isAvailable()) {
            return ['status' => 'error', 'message' => 'API Python hors ligne.'];
        }

        $files = [
            'fichier' => ['path' => $fichier->getPathname(), 'filename' => $fichier->getClientOriginalName()],
        ];

        if ($drop1) {
            $files['drop1'] = ['path' => $drop1->getPathname(), 'filename' => $drop1->getClientOriginalName()];
        }

        if ($drop2) {
            $files['drop2'] = ['path' => $drop2->getPathname(), 'filename' => $drop2->getClientOriginalName()];
        }

        $multipart = $this->buildMultipartBody($files);
        $headers = ['Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary']];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/dropcong', [
                'headers' => $headers,
                'body' => $multipart['body'],
                'timeout' => 120,
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}