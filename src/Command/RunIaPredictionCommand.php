<?php
// src/Command/RunIaPredictionCommand.php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:ia:predict-nightly', description: 'Lance la prédiction IA pour tous les sites (tous horizons)')]
class RunIaPredictionCommand extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'PYTHON_API_BASE_URL')] private string $mlServiceUrl,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (['h24', 'd7', 'd30'] as $horizon) {
            $output->writeln("Prédiction horizon={$horizon}...");
            try {
                $response = $this->httpClient->request('GET', $this->mlServiceUrl . '/ia/predict/batch/all', [
                    'query' => ['horizon' => $horizon, 'limit' => 3000, 'persist' => 'true'],
                    'timeout' => 900,
                ]);
                $data = $response->toArray();
                $output->writeln("  → {$data['total']} sites prédits, {$data['errors']} erreurs.");
            } catch (\Exception $e) {
                $output->writeln("  ❌ Erreur : " . $e->getMessage());
            }
        }
        return Command::SUCCESS;
    }
}
