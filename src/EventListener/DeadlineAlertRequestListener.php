<?php

namespace App\EventListener;

use App\Service\DeadlineAlertService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ✅ Déclenche automatiquement DeadlineAlertService::checkAndNotify()
 * pendant l'utilisation normale de l'application, sans CLI/cron.
 *
 * ✅ CORRIGÉ : ne dépend plus de symfony/lock (composant non installé
 * dans ce projet, causait un RuntimeException "class not found" au
 * démarrage de toute commande Symfony). Le "verrou" est maintenant géré
 * uniquement via une entrée de cache avec une expiration courte posée
 * AVANT l'exécution (et non après) : si deux requêtes arrivent presque
 * simultanément, la seconde verra très probablement déjà l'entrée
 * posée par la première et abandonnera. Ce n'est pas parfaitement
 * atomique, mais :
 *  - la fenêtre de collision est de quelques millisecondes seulement,
 *  - alreadyNotifiedToday() dans DeadlineAlertService empêche de toute
 *    façon les doublons de notifications même si les deux passent.
 * C'est un compromis raisonnable qui évite d'ajouter une dépendance.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: -50)]
class DeadlineAlertRequestListener
{
    private const CACHE_KEY = 'workflow_deadline_last_check_v1';
    private const INTERVAL_SECONDS = 900; // 15 minutes

    public function __construct(
        private DeadlineAlertService $alertService,
        private CacheItemPoolInterface $cache,
        private Security $security,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Ne rien faire pour les visiteurs anonymes (login, assets...).
        if (!$this->security->getUser()) {
            return;
        }

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
        } catch (\Throwable $e) {
            return;
        }

        if ($item->isHit()) {
            return; // Vérifié récemment, rien à faire.
        }

        // ✅ On pose le marqueur AVANT d'exécuter checkAndNotify(), pour
        // réduire au maximum la fenêtre pendant laquelle une requête
        // concurrente pourrait aussi passer le test isHit() ci-dessus.
        try {
            $item->set(true);
            $item->expiresAfter(self::INTERVAL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            return;
        }

        try {
            $count = $this->alertService->checkAndNotify();

            if ($count > 0) {
                $this->logger->info(sprintf(
                    'Vérification automatique des échéances déclenchée par une requête HTTP : %d notification(s) créée(s).',
                    $count
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de la vérification automatique des échéances : ' . $e->getMessage());
        }
    }
}