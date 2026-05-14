<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Repository\TicketRepository;
use App\Repository\TicketTaskRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/deploiement')]
class UserDeploiementController extends AbstractController
{
    #[Route('/tickets', name: 'user_deploiement_tickets', methods: ['GET'])]
    public function index(
        TicketRepository $ticketRepository,
        TicketTaskRepository $taskRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $tickets = $ticketRepository->findAllByService('DEPLOIEMENT');
        $tasks = $taskRepository->findAllWithTickets('DEPLOIEMENT');

        return $this->render('dashboard/user/deploiement/index.html.twig', [
            'tickets' => $tickets,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/ticket/{id}', name: 'user_deploiement_ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('dashboard/user/deploiement/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/ticket/{id}/task/{taskId}', name: 'user_deploiement_ticket_task', methods: ['POST'])]
    public function updateTask(
        Ticket $ticket,
        int $taskId,
        Request $request,
        TicketTaskRepository $taskRepository,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $task = $taskRepository->find($taskId);
        if (!$task || $task->getTicket()->getId() !== $ticket->getId()) {
            throw $this->createNotFoundException('Tâche introuvable.');
        }

        $action = $request->request->get('action');
        $comment = trim((string) $request->request->get('comment', ''));

        if ($action === 'complete') {
            $task->setStatus('done');
            $task->setComment($comment ?: $task->getComment());
            $task->setCompletedAt(new \DateTime());
            $notificationService->notify(
                $ticket->getCreatedBy(),
                Notification::TYPE_TICKET_STATUS_CHANGED,
                sprintf('Intervention déploiement marquée comme terminée pour le ticket #%d.', $ticket->getId()),
                $ticket
            );
        }

        $em->flush();

        return $this->redirectToRoute('user_deploiement_ticket_show', ['id' => $ticket->getId()]);
    }
}
