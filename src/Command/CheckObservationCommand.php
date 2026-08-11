<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\ProcessedSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:check-observation', description: 'Vérifie les sites en fin d\'observation')]
class CheckObservationCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTime();
        // Récupérer les sites dont la période d'observation est terminée
        $sites = $this->em->getRepository(ProcessedSite::class)
            ->createQueryBuilder('s')
            ->where('s.observationUntil <= :now AND s.observationUntil IS NOT NULL')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        // Recherche du Superuser via SQL natif (contourne le problème JSON)
        $conn = $this->em->getConnection();
        $sql = "SELECT id FROM \"user\" WHERE roles::text LIKE :role LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':role', '%"ROLE_SUPERUSER"%');
        $result = $stmt->executeQuery();
        $superuserId = $result->fetchOne();

        if (!$superuserId) {
            $output->writeln('⚠️ Aucun Superuser trouvé.');
            return Command::SUCCESS;
        }

        $superuser = $this->em->getRepository(User::class)->find($superuserId);

        foreach ($sites as $site) {
            $notification = new Notification();
            $notification->setUser($superuser);
            $notification->setType('observation_ended');
            $notification->setMessage("Le site {$site->getSiteName()} est en fin d'observation. Veuillez le revalider.");
            $this->em->persist($notification);
            $site->setObservationUntil(null);
        }
        $this->em->flush();
        $output->writeln('✅ Observation check done.');
        return Command::SUCCESS;
    }
}