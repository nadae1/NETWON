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

#[Route('/user/radio')]
class UserRadioController extends AbstractController
{
    #[Route('/tickets', name: 'user_radio_tickets', methods: ['GET'])]
    public function index(
        TicketRepository $ticketRepository,
        TicketTaskRepository $taskRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $tickets = $ticketRepository->findAllByService('RADIO');
        $tasks = $taskRepository->findAllWithTickets('RADIO');

        return $this->render('dashboard/user/radio/index.html.twig', [
            'tickets' => $tickets,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/ticket/{id}', name: 'user_radio_ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('dashboard/user/radio/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/ticket/{id}/validate', name: 'user_radio_validate', methods: ['POST'])]
    public function validate(
        Ticket $ticket,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $comment = trim((string) $request->request->get('comment', ''));

        $ticket->setStatus('validated');

        $notificationService->notify(
            $ticket->getCreatedBy(),
            Notification::TYPE_TICKET_STATUS_CHANGED,
            sprintf('Support Radio a validé le ticket #%d. Commentaire: %s', $ticket->getId(), $comment ?: 'OK'),
            $ticket
        );

        $em->flush();

        return $this->redirectToRoute('user_radio_ticket_show', ['id' => $ticket->getId()]);
    }
}
