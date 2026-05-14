<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
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

        return $this->render('dashboard/user/deploiement-telecom/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }
}
