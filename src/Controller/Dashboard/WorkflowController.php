<?php
// src/Controller/Dashboard/WorkflowController.php

namespace App\Controller\Dashboard;

use App\Entity\ProcessedSite;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Form\TaskCompletionType;
use App\Form\TicketCommentType;
use App\Form\TicketType;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\UserRepository;
use App\Service\TicketWorkflowService;
use App\Service\WorkflowEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/workflow')]
class WorkflowController extends AbstractController
{
    #[Route('/', name: 'dashboard_workflow')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_workflow_index');
        }

        if ($this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('superuser_workflow_index');
        }

        return $this->redirectToRoute('user_tasks_dashboard');
    }

    #[Route('/new', name: 'dashboard_workflow_new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $ticketWorkflowService,
        WorkflowEngineService $engine,
        ProcessedSiteRepository $processedSiteRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $ticket = new Ticket();
        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        $currentUser = $this->getUser();

        $availableSites = $processedSiteRepository->findLatestSites(null, 2000);

        $allUsers = $em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->andWhere('u.id != :currentId')
            ->setParameter('currentId', $currentUser->getId())
            ->orderBy('u.service', 'ASC')
            ->addOrderBy('u.department', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        $users = array_filter($allUsers, function (User $user): bool {
            return !in_array('ROLE_ADMIN', $user->getRoles(), true)
                && !in_array('ROLE_SUPERUSER', $user->getRoles(), true);
        });

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedSiteIds = $request->request->all('site_ids');
            $selectedUserIds = $request->request->all('assigned_user_ids');

            if (empty($selectedSiteIds)) {
                $this->addFlash('error', 'Veuillez choisir au moins un site.');
                return $this->redirectToRoute('dashboard_workflow_new');
            }

            if (empty($selectedUserIds)) {
                $this->addFlash('error', 'Veuillez choisir au moins un utilisateur.');
                return $this->redirectToRoute('dashboard_workflow_new');
            }

            $ticket->setCreatedBy($currentUser);
            $ticket->setStatus('open');
            $ticket->setProgress(0);
            $ticket->setCreatedAt(new \DateTime());
            $ticket->setUpdatedAt(new \DateTime());

            $em->persist($ticket);

            $selectedProcessedSites = [];
            foreach ($selectedSiteIds as $siteId) {
                $processedSite = $processedSiteRepository->find((int) $siteId);
                if (!$processedSite) {
                    continue;
                }

                $selectedProcessedSites[] = $processedSite;

                $ticketSite = new TicketSite();
                $ticketSite->setTicket($ticket);
                $ticketSite->setSiteName($processedSite->getSiteName());
                $ticketSite->setTypeTrans($processedSite->getTypeTrans());
                $ticketSite->setServiceName($processedSite->getServiceName());
                $em->persist($ticketSite);
            }

            foreach ($selectedUserIds as $userId) {
                $assignedUser = $em->getRepository(User::class)->find((int) $userId);
                if (!$assignedUser) {
                    continue;
                }

                $userService = strtoupper($assignedUser->getService() ?? '');
                $userSites = array_values(array_filter(
                    $selectedProcessedSites,
                    fn(ProcessedSite $site): bool => strtoupper($site->getService() ?? '') === $userService
                ));

                $engine->createInitialIpTask($ticket, $assignedUser, $userSites);
            }

            $ticketWorkflowService->refreshTicketProgress($ticket);
            $ticketWorkflowService->addHistory(
                $ticket,
                $currentUser,
                'ticket_created',
                'Ticket créé avec ' . count($selectedUserIds) . ' étape(s) et ' . count($selectedSiteIds) . ' site(s).'
            );

            $em->flush();
            $this->addFlash('success', 'Workflow créé avec succès.');

            return $this->redirectToRoute('dashboard_workflow_show', ['id' => $ticket->getId()]);
        }

        return $this->render('dashboard/workflow/new.html.twig', [
            'form' => $form->createView(),
            'availableSites' => $availableSites,
            'users' => $users,
            'currentService' => null,
        ]);
    }

    #[Route('/ticket/{id}', name: 'dashboard_workflow_show', methods: ['GET', 'POST'])]
    public function show(
        Ticket $ticket,
        Request $request,
        TicketWorkflowService $workflowService,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $user = $this->getUser();

        if (!$workflowService->canAccessTicket($ticket, $user)) {
            throw $this->createAccessDeniedException();
        }

        $commentForm = $this->createForm(TicketCommentType::class);
        $commentForm->handleRequest($request);

        if ($commentForm->isSubmitted() && $commentForm->isValid()) {
            $commentData = $commentForm->getData();

            $ticketComment = new TicketComment();
            $ticketComment->setTicket($ticket);
            $ticketComment->setUser($user);
            $ticketComment->setMessage($commentData['message']);

            $uploadedFile = $commentForm->get('filePath')->getData();
            if ($uploadedFile) {
                $filename = uniqid('ticket_comment_', true) . '.' . $uploadedFile->guessExtension();
                try {
                    $uploadedFile->move($this->getParameter('ticket_proofs_directory'), $filename);
                    $ticketComment->setFilePath($filename);
                } catch (FileException) {
                    $this->addFlash('error', 'Erreur lors de l’upload du fichier.');
                }
            }

            $em->persist($ticketComment);
            $workflowService->addHistory($ticket, $user, 'comment_added', 'Commentaire ajouté.');
            $em->flush();

            $this->addFlash('success', 'Commentaire ajouté.');
            return $this->redirectToRoute('dashboard_workflow_show', ['id' => $ticket->getId()]);
        }

        return $this->render('dashboard/workflow/show.html.twig', [
            'ticket' => $ticket,
            'commentForm' => $commentForm->createView(),
        ]);
    }

    #[Route('/task/{id}/start', name: 'dashboard_task_start', methods: ['POST'])]
    public function startTask(
        TicketTask $task,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $user = $this->getUser();

        if (!$user || $task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($task->getStatus() === 'pending') {
            $task->setStatus('in_progress');
            $task->setUpdatedAt(new \DateTime());

            $ticket = $task->getTicket();
            if ($ticket) {
                $ticket->setStatus('in_progress');
                $ticket->setUpdatedAt(new \DateTime());
                $workflowService->refreshTicketProgress($ticket);
                $workflowService->addHistory($ticket, $user, 'task_started', 'La tâche #' . $task->getId() . ' a été démarrée.');
            }

            $em->flush();
        }

        return $this->redirectToRoute('dashboard_workflow_show', ['id' => $task->getTicket()->getId()]);
    }

    #[Route('/task/{id}/complete', name: 'dashboard_task_complete', methods: ['GET', 'POST'])]
    public function completeTask(
        TicketTask $task,
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService,
        WorkflowEngineService $engine
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $user = $this->getUser();

        if (!$user || $task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(TaskCompletionType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 🔥 CORRECTION : récupération sécurisée des champs du formulaire
            $comment = $form->has('comment') ? $form->get('comment')->getData() : null;
            $decision = $form->has('decision') ? $form->get('decision')->getData() : null;
            $file = $form->has('proofFile') ? $form->get('proofFile')->getData() : null;

            if ($comment !== null) {
                $task->setComment($comment);
            }
            if ($decision !== null) {
                $task->setDecision($decision);
            }
            if ($file) {
                $filename = uniqid('task_proof_', true) . '.' . $file->guessExtension();
                try {
                    $file->move($this->getParameter('ticket_proofs_directory'), $filename);
                    $task->setProofFile($filename);
                } catch (FileException) {
                    $this->addFlash('error', 'Erreur upload fichier.');
                }
            }

            $decisionFinal = $task->getDecision() ?: 'ok';
            $engine->completeTask($task, $user, $decisionFinal, $task->getComment(), $task->getProofFile());
            $em->flush();

            $this->addFlash('success', 'Tâche traitée avec succès.');
            return $this->redirectToRoute('dashboard_workflow_show', ['id' => $task->getTicket()->getId()]);
        }

        return $this->render('dashboard/workflow/complete_task.html.twig', [
            'task' => $task,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/ticket/{id}/close', name: 'dashboard_ticket_close', methods: ['POST'])]
    public function closeTicket(
        Ticket $ticket,
        EntityManagerInterface $em,
        TicketWorkflowService $workflowService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $user = $this->getUser();

        if (!$workflowService->canAccessTicket($ticket, $user)) {
            throw $this->createAccessDeniedException();
        }

        $workflowService->refreshTicketProgress($ticket);

        if ($ticket->getProgress() !== 100) {
            $this->addFlash('error', 'Impossible de clôturer un ticket non terminé.');
            return $this->redirectToRoute('dashboard_workflow_show', ['id' => $ticket->getId()]);
        }

        $ticket->setStatus('closed');
        $ticket->setClosedAt(new \DateTime());
        $ticket->setUpdatedAt(new \DateTime());
        $workflowService->addHistory($ticket, $user, 'ticket_closed', 'Le ticket a été clôturé.');
        $em->flush();

        $this->addFlash('success', 'Ticket clôturé.');
        return $this->redirectToRoute('dashboard_workflow_show', ['id' => $ticket->getId()]);
    }

    #[Route('/users-by-services', name: 'dashboard_workflow_users_by_services', methods: ['GET'])]
    public function usersByServices(Request $request, UserRepository $userRepository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $services = $request->query->all('services');
        if (empty($services)) {
            return $this->json([]);
        }

        $users = $userRepository->findByServices($services);
        $data = [];
        foreach ($users as $user) {
            $roles = $user->getRoles();
            if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPERUSER', $roles, true)) {
                continue;
            }
            $data[] = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'service' => $user->getService(),
                'department' => $user->getDepartment(),
            ];
        }
        return $this->json($data);
    }
}