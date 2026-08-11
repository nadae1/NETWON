<?php
// src/Controller/UserTaskController.php

namespace App\Controller;

use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/tasks')]
class UserTaskController extends AbstractController
{
    public function __construct(
        private ProcessedSiteRepository $processedSiteRepo,
        private UserRepository $userRepo
    ) {}

    #[Route('/', name: 'user_tasks_dashboard')]
    public function dashboard(TicketTaskRepository $taskRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $userService = strtoupper($user->getService() ?? '');
        $department = $user->getDepartment() ?? '';

        // Redirection selon le service
        if ($userService === 'FO') {
            return $this->redirectToRoute('dashboard_fo_index');
        }
        if ($userService === 'FH') {
            return $this->redirectToRoute('user_fh_tasks');
        }
        if ($userService === 'DEPLOIEMENT') {
            // Redirection vers le département spécifique
            if ($department === 'support_radio') {
                return $this->redirectToRoute('user_support_radio_index');
            }
            if ($department === 'support_backhaul') {
                return $this->redirectToRoute('user_support_backhaul_index');
            }
            return $this->redirectToRoute('user_deploiement_index');
        }
        if ($userService === 'SHARED') {
    return $this->redirectToRoute('user_shared_tasks');
}

        // Fallback : afficher toutes les tâches assignées à l'utilisateur
        $allTasks = $taskRepo->findBy(['assignedTo' => $user], ['createdAt' => 'ASC']);

        // Filtrer par service pour les utilisateurs non-SHARED
        if ($userService !== 'SHARED') {
            $tasks = array_filter($allTasks, function (TicketTask $task) use ($userService) {
                $taskService = strtoupper($task->getServiceName() ?? '');
                return $taskService === $userService;
            });
        } else {
            $tasks = $allTasks;
        }

        $taskSitesMap = [];
        foreach ($tasks as $task) {
            $taskSitesMap[$task->getId()] = $this->resolveSitesFromSiteData($task->getSiteData());
        }

        return $this->render('dashboard/user/tasks/dashboard.html.twig', [
            'tasks' => $tasks,
            'user' => $user,
            'taskSitesMap' => $taskSitesMap,
        ]);
    }

    #[Route('/{id}', name: 'user_task_show', methods: ['GET'])]
    public function show(TicketTask $task): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        if ($task->getAssignedTo() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }

        $taskService = strtoupper($task->getServiceName() ?? '');
        $userService = strtoupper($user->getService() ?? '');
        if ($userService !== 'SHARED' && $taskService !== $userService) {
            $this->addFlash('error', 'Cette tâche n\'est pas destinée à votre service.');
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $siteData = $task->getSiteData() ?? [];
        $allSites = [];
        foreach ($siteData as $siteId) {
            $site = $this->processedSiteRepo->find($siteId);
            if ($site) {
                $allSites[] = $site;
            }
        }

        $filteredSites = array_filter(
            $this->resolveSitesFromSiteData($task->getSiteData()),
            function($site) use ($taskService) {
                if ($taskService === 'SHARED') {
                    return true;
                }
                $siteService = strtoupper($site->getService() ?? '');
                return $siteService === $taskService;
            }
        );
        if (empty($filteredSites)) {
            $this->addFlash('warning', 'Aucun site correspondant à votre service dans cette tâche.');
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        return $this->render('dashboard/user/tasks/show.html.twig', [
            'task' => $task,
            'sites' => array_values($filteredSites),
        ]);
    }

    #[Route('/{id}/complete', name: 'user_task_complete', methods: ['POST'])]
    public function completeTask(TicketTask $task, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($task->getAssignedTo() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }

        $task->setStatus(TicketTask::STATUS_COMPLETED);
        $task->setCompletedAt(new \DateTime());

        $ticket = $task->getTicket();
        $ticket->setUpdatedAt(new \DateTime());

        $em->flush();

        $this->addFlash('success', 'Tâche marquée comme terminée.');
        return $this->redirectToRoute('user_task_show', ['id' => $task->getId()]);
    }

    #[Route('/{taskId}/site/{siteId}/decision', name: 'user_task_site_decision', methods: ['POST'])]
    public function siteDecision(
        int $taskId,
        int $siteId,
        Request $request,
        EntityManagerInterface $em,
        TicketTaskRepository $taskRepo
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $task = $taskRepo->find($taskId);
        if (!$task || $task->getAssignedTo() !== $user) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $decision = $request->request->get('decision');
        $woIpRef = $request->request->get('wo_ip_reference');
        $comment = $request->request->get('comment');

        $siteDecisions = $task->getSiteDecisions() ?? [];
        $siteDecisions[$siteId] = [
            'decision' => $decision,
            'wo_ip_reference' => $woIpRef,
            'comment' => $comment,
        ];
        $task->setSiteDecisions($siteDecisions);

        if ($decision === 'NOK_2EME_PAIRE') {
            $nextUser = $this->userRepo->findOneBy(['service' => 'CAPILLAIRE'])
                ?? $this->userRepo->findOneBy(['service' => 'SHARED']);
            if ($nextUser) {
                $nextTask = new TicketTask();
                $nextTask->setTicket($task->getTicket());
                $nextTask->setAssignedTo($nextUser);
                $nextTask->setTitle('Étude 2ème paire FO');
                $nextTask->setDescription('Étudier la faisabilité de la 2ème paire FO pour le site #' . $siteId);
                $nextTask->setServiceName($nextUser->getService());
                $nextTask->setStatus(TicketTask::STATUS_PENDING);
                $nextTask->setStepCode('capillaire_study');
                $nextTask->setStepOrder($task->getStepOrder() + 1);
                $nextTask->setSiteData([$siteId]);
                $em->persist($nextTask);
            }
        }

        if ($decision === 'NOK_SWAP') {
            $nextUser = $this->userRepo->findOneBy(['service' => 'INGENIERIE_IP'])
                ?? $this->userRepo->findOneBy(['service' => 'SHARED']);
            if ($nextUser) {
                $nextTask = new TicketTask();
                $nextTask->setTicket($task->getTicket());
                $nextTask->setAssignedTo($nextUser);
                $nextTask->setTitle('Analyse swap routeur');
                $nextTask->setDescription('Analyser la faisabilité du swap routeur pour le site #' . $siteId);
                $nextTask->setServiceName($nextUser->getService());
                $nextTask->setStatus(TicketTask::STATUS_PENDING);
                $nextTask->setStepCode('swap_analysis');
                $nextTask->setStepOrder($task->getStepOrder() + 1);
                $nextTask->setSiteData([$siteId]);
                $em->persist($nextTask);
            }
        }

        if ($decision === 'OK') {
            $nextUser = $this->userRepo->findOneBy(['service' => 'INGENIERIE_IP'])
                ?? $this->userRepo->findOneBy(['service' => 'SHARED']);
            if ($nextUser) {
                $nextTask = new TicketTask();
                $nextTask->setTicket($task->getTicket());
                $nextTask->setAssignedTo($nextUser);
                $nextTask->setTitle('Création WO IP');
                $nextTask->setDescription('Créer le Work Order IP pour le site #' . $siteId);
                $nextTask->setServiceName($nextUser->getService());
                $nextTask->setStatus(TicketTask::STATUS_PENDING);
                $nextTask->setStepCode('wo_creation');
                $nextTask->setStepOrder($task->getStepOrder() + 1);
                $nextTask->setSiteData([$siteId]);
                $em->persist($nextTask);
            }
        }

        $em->flush();

        return $this->json(['success' => true]);
    }


    /**
     * 🔧 FIX défensif : task.siteData devrait toujours être une liste plate d'IDs entiers
     * (int|numeric-string), mais certains chemins de code y écrivent par erreur des tableaux
     * de données de formulaire. On ignore silencieusement toute entrée qui n'est pas un
     * identifiant scalaire valide, plutôt que de planter avec MissingIdentifierField.
     *
     * @return \App\Entity\ProcessedSite[]
     */
    private function resolveSitesFromSiteData(?array $siteData): array
    {
        if (!$siteData) {
            return [];
        }

        $sites = [];
        foreach ($siteData as $siteId) {
            if (!is_int($siteId) && !(is_string($siteId) && ctype_digit($siteId))) {
                // Donnée malformée (ex: tableau de formulaire) — on l'ignore proprement
                continue;
            }
            $site = $this->processedSiteRepo->find((int) $siteId);
            if ($site) {
                $sites[] = $site;
            }
        }
        return $sites;
    }
}