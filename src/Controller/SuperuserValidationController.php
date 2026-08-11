<?php
// src/Controller/SuperuserValidationController.php

namespace App\Controller;

use App\Entity\ProcessedSite;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Service\IaRecommendationService;
use App\Service\KpiSimulator;
use App\Service\NotificationService;
use App\Service\SiteStateCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/superuser/validation')]
class SuperuserValidationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('/ticket/{id}', name: 'superuser_validation_ticket', methods: ['GET'])]
    public function showTicket(
        int $id,
        ProcessedSiteRepository $siteRepo,
        TicketRepository $ticketRepo,
        IaRecommendationService $iaService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $ticket = $ticketRepo->find($id);
        if (!$ticket) {
            throw $this->createNotFoundException('Ticket non trouvé.');
        }

        $sites = [];
        foreach ($ticket->getTicketSites() as $ticketSite) {
            $siteName = $ticketSite->getSiteName();
            $processedSite = $siteRepo->findOneBy(['siteName' => $siteName]);
            if ($processedSite) {
                $sites[] = $processedSite;
            }
        }

        $allValidated = true;
        foreach ($sites as $site) {
            $validated = false;
            foreach ($ticket->getTicketSites() as $ts) {
                if ($ts->getSiteName() === $site->getSiteName() && $ts->getStatus() === 'validated') {
                    $validated = true;
                    break;
                }
            }
            if (!$validated) {
                $allValidated = false;
                break;
            }
        }

        return $this->render('dashboard/superuser/validation.html.twig', [
            'ticket' => $ticket,
            'sites' => $sites,
            'allValidated' => $allValidated,
            // ✅ NOUVEAU : liste d'actions pour le champ "action effectuée"
            // du formulaire de validation, réutilise le même référentiel
            // que les recommandations IA pour rester cohérent.
            'actionTypes' => $iaService->getAllActionTypes(),
        ]);
    }

    #[Route('/site/{id}/validate', name: 'superuser_validation_site_validate', methods: ['POST'])]
    public function validateSite(
        int $id,
        Request $request,
        ProcessedSiteRepository $siteRepo,
        TicketRepository $ticketRepo,
        NotificationService $notificationService,
        SiteStateCalculatorService $stateCalculator
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $site = $siteRepo->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé.');
        }

        $ticketId = $request->request->get('ticket_id');
        $ticket = $ticketRepo->find($ticketId);
        if (!$ticket) {
            throw $this->createNotFoundException('Ticket non trouvé.');
        }

        $action = $request->request->get('action');

        if ($action === 'validate') {
            // ✅ CORRIGÉ : la capacité saisie ne doit JAMAIS écraser
            // maxTrafic (une valeur MESURÉE, pas déclarée). L'ancien code
            // faisait `$site->setMaxTrafic((float) $capacity)`, ce qui
            // forçait artificiellement le taux d'utilisation à 100% sur
            // chaque site validé et corrompait durablement le trafic
            // mesuré. On ne touche désormais QUE la capacité.
            $capacityRaw = $request->request->get('capacity');
            $capacityChanged = false;

            if ($capacityRaw !== null && $capacityRaw !== '') {
                $capacityValue = (float) $capacityRaw;
                if ($capacityValue > 0 && $capacityValue !== (float) ($site->getCapaciteMbps() ?? 0)) {
                    $site->setCapaciteMbps($capacityValue);
                    $capacityChanged = true;
                } elseif ($capacityValue > 0) {
                    // Valeur resaisie identique : pas de "changement" au
                    // sens strict mais on considère quand même que c'est
                    // une confirmation explicite de la capacité actuelle.
                    $site->setCapaciteMbps($capacityValue);
                }
            }

            // ✅ NOUVEAU : action réellement effectuée sur le site,
            // choisie par le superuser dans le formulaire de validation.
            $actionPerformedCustom = trim((string) $request->request->get('action_performed_custom', ''));
            $actionPerformedSelect = trim((string) $request->request->get('action_performed', ''));
            $actionPerformed = $actionPerformedCustom !== '' ? $actionPerformedCustom : $actionPerformedSelect;

            if ($actionPerformed !== '') {
                $site->setLastActionPerformed($actionPerformed);
            }
            if ($capacityChanged) {
                $site->setCapaciteUpdatedAt(new \DateTime());
            }

            // ✅ CORRIGÉ (bug principal) : au lieu de forcer
            // `setIsCritical(false)` + `setSiteStatus('secured')`
            // (valeur minuscule que rien d'autre dans l'app ne reconnaît
            // -- les badges/filtres attendent 'CRITIQUE'/'SURVEILLANCE'/
            // 'SECURISE'), on recalcule l'état RÉELLEMENT à partir de la
            // nouvelle capacité et du trafic mesuré, via le même service
            // que l'import de capacité. Si le site est encore au-dessus
            // du seuil malgré la nouvelle capacité, il restera visible
            // comme tel plutôt que d'être artificiellement "sécurisé".
            $stateCalculator->recalculer($site);

            $site->setSupervisionUntil(null);

            foreach ($ticket->getTicketSites() as $ts) {
                if ($ts->getSiteName() === $site->getSiteName()) {
                    $ts->setStatus('validated');
                    break;
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Site ' . $site->getSiteName() . ' validé (capacité et état mis à jour).');

        } elseif ($action === 'supervise') {
            $supervisionDays = (int) $request->request->get('supervision_days', 7);
            $supervisionUntil = (new \DateTime())->modify("+$supervisionDays days");
            $site->setSupervisionUntil($supervisionUntil);

            foreach ($ticket->getTicketSites() as $ts) {
                if ($ts->getSiteName() === $site->getSiteName()) {
                    $ts->setStatus('supervision');
                    break;
                }
            }

            $this->em->flush();
            $this->addFlash('info', 'Site ' . $site->getSiteName() . ' placé sous supervision jusqu\'au ' . $supervisionUntil->format('d/m/Y'));

        } elseif ($action === 'reject') {
            foreach ($ticket->getTicketSites() as $ts) {
                if ($ts->getSiteName() === $site->getSiteName()) {
                    $ts->setStatus('rejected');
                    break;
                }
            }
            $this->em->flush();
            $this->addFlash('warning', 'Site ' . $site->getSiteName() . ' rejeté.');
        }

        // ✅ Le workflow ne se clôture QUE si TOUS les sites du ticket
        // sont validés ou en supervision -- valider un sous-ensemble
        // (ex: 3 sites sur X) laisse le ticket ouvert, comme demandé.
        $allValidated = true;
        foreach ($ticket->getTicketSites() as $ts) {
            if ($ts->getStatus() !== 'validated' && $ts->getStatus() !== 'supervision') {
                $allValidated = false;
                break;
            }
        }

        if ($allValidated) {
            $ticket->setStatus('closed');
            $ticket->setClosedAt(new \DateTime());
            $ticket->setValidatedAt(new \DateTime());
            $this->em->flush();
            $this->addFlash('success', 'Tous les sites validés. Ticket #' . $ticket->getId() . ' clôturé.');
            $notificationService->notify($this->getUser(), 'ticket_closed', 'Ticket #' . $ticket->getId() . ' clôturé après validation de tous les sites.', $ticket);
        }

        return $this->redirectToRoute('superuser_validation_ticket', ['id' => $ticket->getId()]);
    }

    #[Route('/site/{id}', name: 'superuser_validation_site_show', methods: ['GET'])]
    public function showSite(int $id, Request $request, ProcessedSiteRepository $siteRepo, KpiSimulator $simulator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $site = $siteRepo->find($id);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé.');
        }

        $ticketId = $request->query->get('ticket_id');
        if (!$ticketId) {
            $ticket = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
                ->join('t.ticketSites', 'ts')
                ->where('ts.siteName = :siteName')
                ->setParameter('siteName', $site->getSiteName())
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($ticket) {
                $ticketId = $ticket->getId();
            }
        }

        $before = $simulator->generateTrafficData($site, 'before');
        $after = $simulator->generateTrafficData($site, 'after');

        $trafficBefore = $before['values'];
        $trafficAfter = $after['values'];
        $maxBefore = max($trafficBefore);
        $maxAfter = max($trafficAfter);
        $reduction = $maxBefore - $maxAfter;
        $improvement = $maxBefore > 0 ? round(($reduction / $maxBefore) * 100, 1) : 0;

        return $this->render('dashboard/superuser/validation_site.html.twig', [
            'site' => $site,
            'ticketId' => $ticketId,
            'trafficBefore' => $trafficBefore,
            'trafficAfter' => $trafficAfter,
            'hours' => $before['labels'],
            'maxBefore' => $maxBefore,
            'maxAfter' => $maxAfter,
            'reduction' => $reduction,
            'improvement' => $improvement,
        ]);
    }
}