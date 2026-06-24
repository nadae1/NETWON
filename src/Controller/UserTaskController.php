<?php
// src/Controller/UserTaskController.php

namespace App\Controller;

use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/tasks')]
class UserTaskController extends AbstractController
{
    #[Route('/', name: 'user_tasks_dashboard')]
    public function dashboard(TicketTaskRepository $taskRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $service = $user->getService();

        // Redirection pour le service FH
        if ($service === 'FH') {
            return $this->redirectToRoute('user_fh_tasks');
        }

        // Redirection pour le service DEPLOIEMENT
        if ($service === 'DEPLOIEMENT') {
            return $this->redirectToRoute('user_deploiement_tickets');
        }

        // Pour tous les autres services (FO, IP, SHARED, etc.) : afficher le dashboard générique
        $tasks = $taskRepo->findBy(['assignedTo' => $user], ['createdAt' => 'ASC']);

        return $this->render('dashboard/user/tasks/dashboard.html.twig', [
            'tasks' => $tasks,
            'user' => $user,
        ]);
    }

   #[Route('/{id}', name: 'user_task_show')]
public function show(TicketTask $task): Response
{
    $this->denyAccessUnlessGranted('ROLE_USER');
    $user = $this->getUser();
    if ($task->getAssignedTo() !== $user) {
        throw $this->createAccessDeniedException();
    }

    $ticketSite = $task->getTicket()->getTicketSites()->first();
    $typeTrans = $ticketSite ? strtoupper($ticketSite->getTypeTrans() ?? '') : '';

    // Si le site est FH, rediriger vers l'interface FH
    if (str_contains($typeTrans, 'FH')) {
        return $this->redirectToRoute('user_fh_task_show', ['id' => $task->getId()]);
    }

    if ($user->getDepartment() === 'deploiement') {
        return $this->render('dashboard/user/deploiement/show.html.twig', ['task' => $task]);
    }

    return $this->render('dashboard/user/tasks/show.html.twig', ['task' => $task]);
}


}