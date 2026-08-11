<?php

namespace App\Command;

use App\Service\DeadlineAlertService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:workflow:check-deadlines')]
class CheckDeadlinesCommand extends Command
{
    // ✅ Même clé de cache que DeadlineAlertRequestListener, pour éviter
    // que la commande CLI et le listener HTTP ne se déclenchent en même
    // temps. Ce n'est pas un verrou atomique parfait (contrairement à
    // symfony/lock), mais suffisant ici : la fenêtre de collision réelle
    // est infime (checkAndNotify() dure quelques centaines de ms au
    // pire), et alreadyNotifiedToday() dans DeadlineAlertService empêche
    // de toute façon les doublons même en cas de double exécution.
    private const CACHE_KEY = 'workflow_deadline_last_check_v1';
    private const INTERVAL_SECONDS = 900; // 15 minutes

    public function __construct(
        private DeadlineAlertService $alertService,
        private CacheItemPoolInterface $cache
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = $this->alertService->checkAndNotify();

        // Rafraîchit le marqueur de cache pour que le listener HTTP ne
        // relance pas immédiatement une vérification redondante.
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set(true);
            $item->expiresAfter(self::INTERVAL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            // Non bloquant : si le cache échoue, on a quand même fait
            // la vérification, seul le throttling du listener est affecté.
        }

        $output->writeln(sprintf('✅ Vérification terminée. Notifications créées : %d', $created));
        return Command::SUCCESS;
    }
}