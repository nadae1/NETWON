<?php
// src/Service/FastApiPortDetector.php

namespace App\Service;

class FastApiPortDetector
{
    public function detectPort(): ?int
    {
        $ports = [8001, 8002, 8010];

        foreach ($ports as $port) {
            $ch = curl_init("http://127.0.0.1:$port/health");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return $port;
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