<?php

namespace App\Service;

use App\Entity\ProcessedSite;

class KpiSimulator
{
    public function generateTrafficData(ProcessedSite $site, string $type = 'before'): array
    {
        $maxTrafic = $site->getMaxTrafic() ?: 500;
        $hours = range(0, 23);
        $data = [];
        for ($h = 0; $h < 24; $h++) {
            $multiplier = 0.3 + 0.7 * (1 - abs($h - 20) / 20);
            $value = $maxTrafic * $multiplier * (0.8 + rand(0, 40) / 100);
            if ($type === 'after') {
                $value *= (0.3 + rand(30, 70) / 100);
            }
            $data[] = round($value, 2);
        }
        return ['labels' => $hours, 'values' => $data];
    }
}