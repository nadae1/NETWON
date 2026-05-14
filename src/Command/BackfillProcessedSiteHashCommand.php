<?php

namespace App\Command;

use App\Repository\ProcessedSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:backfill-processed-site-hash',
    description: 'Fill data_hash for existing processed_site rows'
)]
class BackfillProcessedSiteHashCommand extends Command
{
    public function __construct(
        private ProcessedSiteRepository $processedSiteRepository,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sites = $this->processedSiteRepository->findAll();
        $count = 0;
        $batchSize = 100;

        foreach ($sites as $index => $site) {
            if ($site->getDataHash()) {
                continue;
            }

            $hash = hash('sha256', implode('|', [
                mb_strtolower(trim((string) $site->getSiteName())),
                mb_strtolower(trim((string) ($site->getPairedSiteName() ?? ''))),
                mb_strtolower(trim((string) $site->getClassification())),
                mb_strtolower(trim((string) ($site->getTypeTrans() ?? ''))),
                number_format((float) ($site->getMaxTrafic() ?? 0), 6, '.', ''),
                $site->getDateMax() ? $site->getDateMax()->format('Y-m-d H:i:s') : '',
                number_format((float) ($site->getSeuilCritique() ?? 0), 6, '.', ''),
                (int) $site->getNombreOccurrences(),
                (int) $site->getTotalMeasures(),
                mb_strtolower(trim((string) ($site->getServiceName() ?? '')))
            ]));

            $site->setDataHash($hash);
            $count++;

            if (($index + 1) % $batchSize === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $output->writeln("Hashes générés: $count");

        return Command::SUCCESS;
    }
}