<?php

namespace App\Controller;

use App\Service\WorkflowAutoAssigner;
use App\Entity\Ticket;
use App\Entity\TicketSite;
use App\Entity\User;
use App\Form\SuperuserTicketType;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Service\NotificationService;
use App\Service\TicketWorkflowService;
use App\Service\WorkflowEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/superuser/workflow')]
class SuperuserWorkflowController extends AbstractController
{
    #[Route('/', name: 'superuser_workflow_index', methods: ['GET'])]
    public function index(Request $request, TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $status = $request->query->get('status');
        $allowedStatuses = ['open', 'in_progress', 'completed', 'closed', 'blocked'];

        if ($status && !in_array($status, $allowedStatuses, true)) {
            $status = null;
        }

        // For superusers, show all tickets or filter by status
        $tickets = $ticketRepository->findByStatusOrdered($status);

        return $this->render('dashboard/superuser/workflow/index.html.twig', [
            'tickets' => $tickets,
            'currentStatus' => $status,
        ]);
    }

    #[Route('/ticket/{id}', name: 'superuser_workflow_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        return $this->render('dashboard/superuser/workflow/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }

   

    #[Route('/new', name: 'superuser_workflow_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $ticketWorkflowService,
        WorkflowEngineService $engine,
        ProcessedSiteRepository $processedSiteRepository,
        NotificationService $notificationService,
        WorkflowAutoAssigner $autoAssigner  // <- NOUVEAU
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $ticket = new Ticket();
        $form = $this->createForm(SuperuserTicketType::class, $ticket);
        $form->handleRequest($request);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $availableSites = $processedSiteRepository->findLatestSites(null, 5000);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedSiteIds = $request->request->all('site_ids');
            if (empty($selectedSiteIds)) {
                $this->addFlash('error', 'Veuillez choisir au moins un site.');
                return $this->redirectToRoute('superuser_workflow_new');
            }

            $ticket->setCreatedBy($currentUser);
            $ticket->setStatus('open');
            $ticket->setProgress(0);
            $ticket->setCreatedAt(new \DateTime());
            $ticket->setUpdatedAt(new \DateTime());

            $em->persist($ticket);

            $selectedSites = [];
            foreach ($selectedSiteIds as $siteId) {
                $processedSite = $processedSiteRepository->find((int) $siteId);
                if (!$processedSite) continue;

                $ticketSite = new TicketSite();
                $ticketSite->setTicket($ticket);
                $ticketSite->setSiteName($processedSite->getSiteName());
                $ticketSite->setTypeTrans($processedSite->getTypeTrans());
                $ticketSite->setServiceName($processedSite->getServiceName());
                $em->persist($ticketSite);
                $selectedSites[] = $processedSite;
            }

            // =====================================================
            // Assignation automatique des utilisateurs en fonction des tâches
            // =====================================================
            $assignedUsers = $autoAssigner->assignUsersForSites($selectedSites, $ticket, $currentUser);

            // Mise à jour de la progression et historique
            $ticketWorkflowService->refreshTicketProgress($ticket);
            $ticketWorkflowService->addHistory(
                $ticket,
                $currentUser,
                'ticket_created',
                sprintf('Workflow créé automatiquement avec %d site(s) et %d utilisateur(s) assigné(s).',
                    count($selectedSites), count($assignedUsers))
            );

            $em->flush();

            // Notifications
            if (!empty($assignedUsers)) {
                $notificationService->notifyWorkflowAssignment($ticket, $assignedUsers, count($assignedUsers));
                $em->flush();
            }

            $this->addFlash('success', 'Workflow créé avec succès (assignation auto).');
            return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
        }

        // ... reste de la méthode inchangé (rendu du formulaire)
        return $this->render('dashboard/superuser/workflow/new.html.twig', [
            'form' => $form->createView(),
            'availableSites' => $availableSites,
            'users' => [], // plus utilisé, mais gardé pour compatibilité template
            'availableUsers' => [],
            'currentService' => $request->query->get('service'),
        ]);

        
    }

    private function getBlockerInfo(Ticket $ticket): ?array
{
    if ($ticket->getStatus() === 'waiting_capillaire') {
        return ['service' => 'Ingénierie Capillaire', 'user' => null];
    }
    if ($ticket->getStatus() === 'waiting_swap') {
        return ['service' => 'Ingénierie IP (swap)', 'user' => null];
    }
    // ... le reste
}


}