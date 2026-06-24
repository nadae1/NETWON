<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class FastApiPortDetector
{
    private $httpClient;
    private $apiPort = null;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
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
        $port = $this->detectPort();
        return $port ? "http://127.0.0.1:$port" : null;
    }
}