<?php
// src/Controller/UserDeploiementController.php

namespace App\Controller;

use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use App\Service\DeploiementWorkflowService;
use App\Service\FoWorkflowService;
use App\Service\FhWorkflowService;
use App\Service\TicketWorkflowService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/deploiement')]
class UserDeploiementController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketWorkflowService $ticketWorkflowService,
        private FoWorkflowService $foWorkflowService,
        private FhWorkflowService $fhWorkflowService,
        private DeploiementWorkflowService $deploiementWorkflowService,
        private TicketTaskRepository $taskRepo,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {}

    #[Route('/', name: 'user_deploiement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $search = trim((string) $request->query->get('search', ''));
        $status = (string) $request->query->get('status', '');

        $allTasks = $this->taskRepo->findBy(['assignedTo' => $user], ['createdAt' => 'DESC']);

        $deploiementTasks = array_values(array_filter(
            $allTasks,
            fn(TicketTask $t) => $t->getDepartmentName() === 'deploiement_telecom'
        ));

        $tasks = $deploiementTasks;
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $tasks = array_values(array_filter($tasks, function (TicketTask $t) use ($needle) {
                $haystack = mb_strtolower($t->getTitle() . ' ' . ($t->getTicket()?->getTitle() ?? '') . ' ' . ($t->getTicket()?->getSiteName() ?? ''));
                return str_contains($haystack, $needle);
            }));
        }
        if ($status !== '') {
            $tasks = array_values(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === $status));
        }

        $total = count($deploiementTasks);
        $pending = count(array_filter($deploiementTasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_PENDING));
        $inProgress = count(array_filter($deploiementTasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_IN_PROGRESS));
        $blocked = count(array_filter($deploiementTasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_BLOCKED));
        $done = count(array_filter($deploiementTasks, fn(TicketTask $t) => $t->isDone()));

        $now = new \DateTime();
        $overdue = 0;
        foreach ($deploiementTasks as $t) {
            $deadline = $t->getTicket()?->getDeadline();
            if ($deadline && $deadline < $now && !$t->isDone()) {
                $overdue++;
            }
        }

        $taskSitesMap = [];
        foreach ($tasks as $task) {
            $taskSitesMap[$task->getId()] = $this->getSitesForTask($task);
        }

        return $this->render('dashboard/user/deploiement-telecom/index.html.twig', [
            'tasks' => $tasks,
            'taskSitesMap' => $taskSitesMap,
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'blocked' => $blocked,
            'done' => $done,
            'overdue' => $overdue,
            'searchQuery' => $search,
            'currentStatus' => $status,
        ]);
    }

    #[Route('/task/{id}', name: 'user_deploiement_task_show', methods: ['GET'])]
    public function show(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToDeploiement($task);

        $sites = $this->getSitesForTask($task);
        $selectedSiteId = $request->query->get('site');
        $selectedSite = null;
        if ($selectedSiteId) {
            foreach ($sites as $s) {
                if ((string) $s->getId() === (string) $selectedSiteId) {
                    $selectedSite = $s;
                    break;
                }
            }
        }
        if (!$selectedSite && !empty($sites)) {
            $selectedSite = $sites[0];
        }

        $siteDecisions = $task->getSiteDecisions() ?? [];
        $fhFields = $task->getFhFields() ?? [];
        $ticket = $task->getTicket();

        $stepCode = $task->getStepCode();

        // Détection des cas
        $isCreationWoIp = ($stepCode === TicketTask::STEP_FO_WO_IP_CREATION);
        $isPlanification = ($stepCode === TicketTask::STEP_DEPLOIEMENT_PLANIFICATION || $stepCode === TicketTask::STEP_FO_DEPLOYMENT_PLANNING);
        $isExecution = ($stepCode === TicketTask::STEP_DEPLOIEMENT_EXECUTION || $stepCode === TicketTask::STEP_FO_SITE_EXECUTION);
        $isSwap = ($stepCode === TicketTask::STEP_FO_IP_SWAP_ANALYSIS);
        $isRaccordement = ($stepCode === TicketTask::STEP_FO_CAPILLAIRE_DEPLOYMENT);

        // ✅ Détection du cas hard upgrade FH
        $isHardUpgrade = false;
        if ($isPlanification) {
            $deploiementData = $task->getDeploiementData() ?? [];
            if (($deploiementData['upgrade_type'] ?? '') === 'hard') {
                $isHardUpgrade = true;
            }
        }

        return $this->render('dashboard/user/deploiement-telecom/show.html.twig', [
            'task' => $task,
            'ticket' => $ticket,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'siteDecisions' => $siteDecisions,
            'totalSites' => count($sites),
            'fhFields' => $fhFields,
            'isCreationWoIp' => $isCreationWoIp,
            'isPlanification' => $isPlanification,
            'isExecution' => $isExecution,
            'isRaccordement' => $isRaccordement,
            'isSwap' => $isSwap,
            'isHardUpgrade' => $isHardUpgrade,
        ]);
    }

    #[Route('/task/{id}/site/{siteId}/decision', name: 'user_deploiement_site_decision', methods: ['POST'])]
    public function siteDecision(TicketTask $task, int $siteId, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToDeploiement($task);

        $sites = $this->getSitesForTask($task);
        $ticketSite = null;
        foreach ($sites as $s) {
            if ($s->getId() === $siteId) {
                $ticketSite = $s;
                break;
            }
        }
        if (!$ticketSite) {
            $this->addFlash('error', 'Site introuvable.');
            return $this->redirectToRoute('user_deploiement_task_show', ['id' => $task->getId()]);
        }

        $siteDecisions = $task->getSiteDecisions() ?? [];
        $stepCode = $task->getStepCode();

        // Cas 1 : Planification standard (FO ou FH soft) OU hard upgrade FH
        if ($stepCode === TicketTask::STEP_DEPLOIEMENT_PLANIFICATION || $stepCode === TicketTask::STEP_FO_DEPLOYMENT_PLANNING) {
            // Vérifier si c'est un hard upgrade FH
            $deploiementData = $task->getDeploiementData() ?? [];
            if (($deploiementData['upgrade_type'] ?? '') === 'hard') {
                // ✅ Cas hard upgrade FH : gestion MLO
                $mloDecision = $request->request->get('mlo_decision', 'OK');
                $comment = $request->request->get('comment');

                // Sauvegarder la décision
                $siteDecisions[$siteId] = array_merge($siteDecisions[$siteId] ?? [], [
                    'mlo_decision' => $mloDecision,
                    'mlo_comment' => $comment,
                    'status' => 'mlo_traite',
                ]);
                $task->setSiteDecisions($siteDecisions);

                // Marquer le site comme traité dans TicketSite
                $ticketSite->setStatus('completed');

                // Vérifier si tous les sites sont traités
                $allDone = true;
                foreach ($this->getSitesForTask($task) as $s) {
                    if ($s->getStatus() !== 'completed') {
                        $allDone = false;
                        break;
                    }
                }

                if ($allDone) {
                    // Tous les sites sont traités : terminer la tâche de planification
                    $task->setStatus(TicketTask::STATUS_DONE);
                    $task->setCompletedAt(new \DateTime());

                    // Créer la tâche suivante selon la décision MLO
                    $ticket = $task->getTicket();
                    if ($mloDecision === 'OK') {
                        // MLO OK → Ingénierie Capillaire (étape FH_MLO)
                        $nextUser = $this->findUserForDepartment('ingenierie_capillaire');
                        if (!$nextUser) {
                            $this->addFlash('error', 'Aucun utilisateur trouvé pour l\'ingénierie capillaire.');
                            return $this->redirectToRoute('user_deploiement_task_show', ['id' => $task->getId()]);
                        }
                        $newTask = $this->ticketWorkflowService->moveToNextTask($task, $nextUser, TicketTask::STEP_FH_MLO);
                        $newTask->setTitle('MLO (Déploiement Télécom)');
                        $newTask->setServiceName('FH');
                        $newTask->setDepartmentName('ingenierie_capillaire');
                        $newTask->setSiteData($task->getSiteData());
                        $newTask->setFhFields($task->getFhFields());
                        $newTask->setDeploiementData(['upgrade_type' => 'hard']); // conserver

                        $this->ticketWorkflowService->addHistory(
                            $ticket,
                            $this->getUser(),
                            'task_transferred',
                            'MLO OK, tâche transmise à Ingénierie Capillaire.'
                        );
                    } else {
                        // MLO NOK → Ingénierie IP (étape FO_WO_IP_CREATION)
                        $nextUser = $this->findUserForDepartment('ingenierie_ip');
                        if (!$nextUser) {
                            $this->addFlash('error', 'Aucun utilisateur trouvé pour l\'ingénierie IP.');
                            return $this->redirectToRoute('user_deploiement_task_show', ['id' => $task->getId()]);
                        }
                        $newTask = $this->ticketWorkflowService->moveToNextTask($task, $nextUser, TicketTask::STEP_FO_WO_IP_CREATION);
                        $newTask->setTitle('Création WO IP (MLO NOK)');
                        $newTask->setServiceName('FO');
                        $newTask->setDepartmentName('ingenierie_ip');
                        $newTask->setSiteData($task->getSiteData());
                        $newTask->setFhFields($task->getFhFields());

                        $this->ticketWorkflowService->addHistory(
                            $ticket,
                            $this->getUser(),
                            'task_transferred',
                            'MLO NOK, tâche transmise à Ingénierie IP pour WO IP.'
                        );
                    }

                    // Mettre à jour le ticket
                    $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
                    $ticket->setUpdatedAt(new \DateTime());
                    $this->ticketWorkflowService->refreshTicketProgress($ticket);
                    $this->em->flush();

                    $this->addFlash('success', 'Décision MLO enregistrée et tâche suivante créée.');
                } else {
                    // Il reste des sites : rester en cours
                    $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
                    $this->em->flush();
                    $this->addFlash('info', 'Décision MLO enregistrée pour ce site. Il reste des sites à traiter.');
                }

                return $this->redirectToRoute('user_deploiement_task_show', ['id' => $task->getId()]);
            }

            // Sinon, planification standard (FO, FH soft, raccordement, etc.)
            $planification = $request->request->get('planification');
            $radioOk = $request->request->get('radio_ok') ? true : false;
            $backhaulOk = $request->request->get('backhaul_ok') ? true : false;
            $comment = $request->request->get('comment');

            $siteDecisions[$siteId] = array_merge($siteDecisions[$siteId] ?? [], [
                'planification' => $planification,
                'radio_ok' => $radioOk,
                'backhaul_ok' => $backhaulOk,
                'comment' => $comment,
                'status' => 'planifie',
            ]);
            $task->setSiteDecisions($siteDecisions);
            $task->setStatus(TicketTask::STATUS_IN_PROGRESS);

            $supports = [];
            if ($radioOk) $supports[] = 'radio';
            if ($backhaulOk) $supports[] = 'backhaul';
            if (!empty($supports)) {
                $this->deploiementWorkflowService->createSupportTasks($task, $planification, $comment, $supports);
                $this->addFlash('success', 'Supports notifiés.');
            } else {
                $this->addFlash('warning', 'Aucun support sélectionné.');
            }

            $this->em->flush();
            $this->addFlash('info', 'Planification enregistrée.');
        }
        // Cas 2 : Exécution
        elseif ($stepCode === TicketTask::STEP_DEPLOIEMENT_EXECUTION || $stepCode === TicketTask::STEP_FO_SITE_EXECUTION) {
            $decision = $request->request->get('decision', 'OK');
            $comment = $request->request->get('comment');

            $siteDecisions[$siteId] = array_merge($siteDecisions[$siteId] ?? [], [
                'execution_decision' => $decision,
                'execution_comment' => $comment,
                'status' => 'executed',
            ]);
            $task->setSiteDecisions($siteDecisions);

            if ($decision === 'OK') {
                $task->setStatus(TicketTask::STATUS_DONE);
                $this->foWorkflowService->completeFoTask($task, 'OK', null, $this->getUser());
                $this->addFlash('success', 'Exécution validée.');
            } else {
                $task->setStatus(TicketTask::STATUS_BLOCKED);
                $this->em->flush();
                $this->addFlash('error', 'Exécution NOK.');
            }
        }
        // Cas 3 : Raccordement 2ème paire
        elseif ($stepCode === TicketTask::STEP_FO_CAPILLAIRE_DEPLOYMENT) {
            $raccordementOk = $request->request->get('raccordement_ok') === 'OK' ? 'OK' : 'NOK';
            $comment = $request->request->get('comment');
            $planification = $request->request->get('planification');

            $siteDecisions[$siteId] = array_merge($siteDecisions[$siteId] ?? [], [
                'planification' => $planification,
                'raccordement_ok' => $raccordementOk,
                'comment' => $comment,
            ]);
            $task->setSiteDecisions($siteDecisions);

            $task->setStatus(TicketTask::STATUS_DONE);
            $this->foWorkflowService->completeFoTask($task, $raccordementOk, null, $this->getUser());
            $this->addFlash('success', 'Raccordement traité.');
        }

        $this->em->flush();
        $this->ticketWorkflowService->refreshTicketProgress($task->getTicket());

        return $this->redirectToRoute('user_deploiement_task_show', ['id' => $task->getId()]);
    }

    #[Route('/task/{id}/start', name: 'user_deploiement_task_start', methods: ['GET'])]
    public function startTask(TicketTask $task): Response
    {
        $this->denyAccessUnlessTaskBelongsToDeploiement($task);
        if ($task->getStatus() === TicketTask::STATUS_PENDING) {
            $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
            $this->em->flush();
            $this->addFlash('info', 'Tâche démarrée.');
        }
        return $this->redirectToRoute('user_deploiement_task_show', ['id' => $task->getId()]);
    }

    private function getSitesForTask(TicketTask $task): array
    {
        $ticket = $task->getTicket();
        if (!$ticket) {
            return [];
        }
        return $ticket->getTicketSites()->toArray();
    }

    private function denyAccessUnlessTaskBelongsToDeploiement(TicketTask $task): void
    {
        if ($task->getDepartmentName() !== 'deploiement_telecom') {
            throw $this->createAccessDeniedException('Cette tâche n\'appartient pas au Déploiement.');
        }
        $user = $this->getUser();
        if ($task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }
    }

    private function findUserForDepartment(string $department): ?User
    {
        $users = $this->em->getRepository(User::class)->findBy(['department' => $department]);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }
        return null;
    }
}