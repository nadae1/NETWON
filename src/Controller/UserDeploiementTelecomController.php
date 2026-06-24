<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/deploiement-telecom')]
class UserDeploiementTelecomController extends AbstractController
{
    #[Route('/tickets', name: 'user_deploiement_telecom_tickets', methods: ['GET'])]
    public function index(TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $tickets = $ticketRepository->findAllByService('DEPLOIEMENT_TELECOM');

        return $this->render('dashboard/user/deploiement-telecom/index.html.twig', [
            'tickets' => $tickets,
        ]);
    }

    #[Route('/ticket/{id}', name: 'user_deploiement_telecom_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $tasks = $ticket->getTasks()->toArray();
        usort($tasks, fn($a, $b) => $a->getStepOrder() <=> $b->getStepOrder());

        return $this->render('dashboard/user/deploiement-telecom/show.html.twig', [
            'ticket' => $ticket,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/ticket/{id}/comment', name: 'user_deploiement_telecom_comment', methods: ['POST'])]
    public function addComment(Ticket $ticket, Request $request, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $message = trim((string) $request->request->get('comment', ''));
        if ($message !== '') {
            $comment = new \App\Entity\TicketComment();
            $comment->setTicket($ticket);
            $comment->setUser($this->getUser());
            $comment->setMessage($message);
            $em->persist($comment);
            $notificationService->notify(
                $ticket->getCreatedBy(),
                Notification::TYPE_TICKET_STATUS_CHANGED,
                sprintf('Un commentaire a été ajouté au ticket #%d.', $ticket->getId()),
                $ticket
            );
            $em->flush();
        }
        $this->addFlash('success', 'Commentaire ajouté.');
        return $this->redirectToRoute('user_deploiement_telecom_show', ['id' => $ticket->getId()]);
    }

    #[Route('/ticket/{id}/return', name: 'user_deploiement_telecom_return', methods: ['POST'])]
    public function returnToTransmission(Ticket $ticket, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $ticket->setStatus('waiting_transmission');
        $notificationService->notify(
            $ticket->getCreatedBy(),
            Notification::TYPE_TICKET_STATUS_CHANGED,
            sprintf('Le sous-workflow Déploiement Télécom est terminé, retour à la transmission pour le ticket #%d.', $ticket->getId()),
            $ticket
        );
        $em->flush();
        $this->addFlash('success', 'Ticket renvoyé à la transmission.');
        return $this->redirectToRoute('user_deploiement_telecom_show', ['id' => $ticket->getId()]);
    }
}
