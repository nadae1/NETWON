<?php

namespace App\Controller;

use App\Entity\WorkflowTicket;
use App\Entity\WorkflowTicketHistory;
use App\Repository\WorkflowTicketHistoryRepository;
use App\Repository\WorkflowTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/workflow')]
class WorkflowTicketController extends AbstractController
{
    #[Route('', name: 'workflow_dashboard')]
    public function dashboard(WorkflowTicketRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('workflow/dashboard.html.twig', [
            'tickets' => $repo->findForService(null),
            'created' => $repo->countByStatus('CREATED'),
            'inProgress' => $repo->countByStatus('IN_PROGRESS'),
            'blocked' => $repo->countByStatus('BLOCKED'),
            'closed' => $repo->countByStatus('CLOSED'),
        ]);
    }

    #[Route('/new', name: 'workflow_ticket_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($request->isMethod('POST')) {
            $ticket = new WorkflowTicket();
            $ticket->setSiteName($request->request->get('siteName'));
            $ticket->setService($request->request->get('service', 'FO'));
            $ticket->setPriority($request->request->get('priority', 'NORMAL'));
            $ticket->setDescription($request->request->get('description'));
            $ticket->setTrafficBefore((float) $request->request->get('trafficBefore'));
            $ticket->setCreatedBy($this->getUser());
            $ticket->setAssignedService('INGENIERIE_IP');
            $ticket->setStatus('SENT_TO_IP');
            $ticket->setCurrentStep('IP_ANALYSIS');

            $em->persist($ticket);

            $history = new WorkflowTicketHistory();
            $history->setTicket($ticket);
            $history->setUser($this->getUser());
            $history->setAction('Création ticket et envoi vers Ingénierie IP');
            $history->setFromStep('TRANSVERSE_ANALYSIS');
            $history->setToStep('IP_ANALYSIS');
            $history->setComment($request->request->get('description'));

            $em->persist($history);
            $em->flush();

            return $this->redirectToRoute('workflow_ticket_show', ['id' => $ticket->getId()]);
        }

        return $this->render('workflow/new.html.twig');
    }

    #[Route('/ticket/{id}', name: 'workflow_ticket_show')]
    public function show(
        WorkflowTicket $ticket,
        WorkflowTicketHistoryRepository $historyRepo
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('workflow/show.html.twig', [
            'ticket' => $ticket,
            'history' => $historyRepo->findByTicketOrdered($ticket),
        ]);
    }

    #[Route('/ticket/{id}/action', name: 'workflow_ticket_action', methods: ['POST'])]
    public function action(
        WorkflowTicket $ticket,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $decision = $request->request->get('decision');
        $comment = $request->request->get('comment');

        $fromStep = $ticket->getCurrentStep();

        match ($decision) {
            'IP_OK' => $this->move($ticket, 'WO_CREATED', 'CREATION_WO_IP', 'DEPLOIEMENT', 'IN_PROGRESS'),
            'IP_NOK_FO_PAIR' => $this->move($ticket, 'FO_CAPILLARY_ANALYSIS', 'INGENIERIE_CAPILLAIRE', 'INGENIERIE_CAPILLAIRE', 'BLOCKED'),
            'IP_NOK_SWAP' => $this->move($ticket, 'ROUTER_SWAP_ANALYSIS', 'INGENIERIE_IP_SWAP', 'INGENIERIE_IP', 'BLOCKED'),
            'WO_CREATED' => $this->move($ticket, 'DEPLOYMENT_PLANNED', 'DEPLOYMENT_PLANNING', 'DEPLOIEMENT', 'IN_PROGRESS'),
            'DEPLOYMENT_READY' => $this->move($ticket, 'ON_SITE_EXECUTION', 'EXECUTION_SITE', 'EXECUTION_SITE', 'IN_PROGRESS'),
            'SITE_DONE' => $this->move($ticket, 'FINAL_VALIDATION', 'VALIDATION_FINALE', 'VALIDATION', 'IN_PROGRESS'),
            'FINAL_OK' => $this->move($ticket, 'KPI_VERIFICATION', 'KPI_VERIFICATION', 'KPI', 'IN_PROGRESS'),
            'FINAL_NOK' => $this->move($ticket, 'RETURN_TO_IP', 'IP_ANALYSIS', 'INGENIERIE_IP', 'BLOCKED'),
            'FO_OK' => $this->move($ticket, 'RETURN_TO_IP', 'CREATION_WO_IP', 'INGENIERIE_IP', 'IN_PROGRESS'),
            'FO_NOK' => $this->move($ticket, 'FO_CAPILLARY_ANALYSIS', 'INGENIERIE_CAPILLAIRE', 'INGENIERIE_CAPILLAIRE', 'BLOCKED'),
            'KPI_OK' => $this->close($ticket),
            'KPI_NOK' => $this->move($ticket, 'REOPENED', 'IP_ANALYSIS', 'INGENIERIE_IP', 'BLOCKED'),
            default => null,
        };

        $ticket->setUpdatedAt(new \DateTimeImmutable());

        $history = new WorkflowTicketHistory();
        $history->setTicket($ticket);
        $history->setUser($this->getUser());
        $history->setAction($decision);
        $history->setFromStep($fromStep);
        $history->setToStep($ticket->getCurrentStep());
        $history->setComment($comment);

        $em->persist($history);
        $em->flush();

        return $this->redirectToRoute('workflow_ticket_show', ['id' => $ticket->getId()]);
    }

    private function move(
        WorkflowTicket $ticket,
        string $status,
        string $step,
        string $assignedService,
        string $globalStatus
    ): void {
        $ticket->setStatus($globalStatus);
        $ticket->setCurrentStep($step);
        $ticket->setAssignedService($assignedService);
    }

    private function close(WorkflowTicket $ticket): void
    {
        $ticket->setStatus('CLOSED');
        $ticket->setCurrentStep('CLOSED');
        $ticket->setAssignedService('NONE');
        $ticket->setIsClosed(true);
    }
}