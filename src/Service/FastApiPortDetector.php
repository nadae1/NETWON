<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class FastApiPortDetector
{
    private $httpClient;
    private $apiPort = null;
    private ?string $apiBaseUrl;

    public function __construct(HttpClientInterface $httpClient, ?string $apiBaseUrl = null)
    {
        $this->httpClient = $httpClient;
        $this->apiBaseUrl = $apiBaseUrl ? rtrim($apiBaseUrl, '/') : null;
    }

    public function detectPort(): ?int
    {
        if ($this->apiPort !== null) {
            return $this->apiPort;
        }
        $ports = [8001, 8000, 8002];
        foreach ($ports as $port) {
            try {
                $response = $this->httpClient->request('GET', "http://127.0.0.1:$port/health", ['timeout' => 2]);
                if ($response->getStatusCode() === 200) {
                    $this->apiPort = $port;
                    return $port;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return null;
    }

    public function getApiUrl(): ?string
    {
        if ($this->apiBaseUrl !== null) {
            try {
                $response = $this->httpClient->request('GET', $this->apiBaseUrl . '/health', ['timeout' => 2]);
                return $response->getStatusCode() === 200 ? $this->apiBaseUrl : null;
            } catch (\Exception $e) {
                return null;
            }
        }

        $port = $this->detectPort();
        return $port ? "http://127.0.0.1:$port" : null;
    }
}
