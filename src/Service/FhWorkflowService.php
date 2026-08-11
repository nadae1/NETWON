<?php
// src/Service/FhWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FhWorkflowService
{
    private const STEP_FLOW = [
        TicketTask::STEP_FH_ETUDE_PREREQUIS => [
            'next' => TicketTask::STEP_FH_MAJ_CAPACITE,
            'title' => 'MAJ Capacité',
            'service' => 'FH',
            'department' => 'support_fh',
        ],
        TicketTask::STEP_FH_MAJ_CAPACITE => [
            'next' => TicketTask::STEP_FH_ING_TRANS_CAP,
            'title' => 'Ingénierie Transmission Capacité',
            'service' => 'FH',
            'department' => 'ingenierie_capillaire',
        ],
        TicketTask::STEP_FH_ING_TRANS_CAP => [
            'next' => null, // géré manuellement
            'title' => 'Ingénierie Transmission Capacité',
            'service' => 'FH',
            'department' => 'ingenierie_capillaire',
        ],
        TicketTask::STEP_FH_MLO => [
            'next' => TicketTask::STEP_FH_LLD,
            'title' => 'MLO (Déploiement Télécom)',
            'service' => 'FH',
            'department' => 'deploiement_telecom',
        ],
        TicketTask::STEP_FH_LLD => [
            'next' => TicketTask::STEP_FH_EXECUTION_WO,
            'title' => 'LLD Port Routeur (Ingénierie IP)',
            'service' => 'FO',
            'department' => 'ingenierie_ip',
        ],
        TicketTask::STEP_FH_EXECUTION_WO => [
            'next' => null,
            'title' => 'Exécution WO (Support Trans)',
            'service' => 'FO',
            'department' => 'support_trans',
        ],
    ];

    private const SHARED_DEPARTMENTS = [
        'deploiement_telecom',
        'support_radio',
        'support_backhaul',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private TicketWorkflowService $workflowService,
        private UserRepository $userRepo,
        private LoggerInterface $logger,
    ) {}

    public function processFhTask(TicketTask $task, string $decision, ?array $formData, User $actor): void
    {
        $this->logger->info('FhWorkflowService: processing task', [
            'task_id' => $task->getId(),
            'step' => $task->getStepCode(),
            'decision' => $decision,
        ]);

        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $ticket = $task->getTicket();

        if ($formData) {
            $task->setFhFields($formData);
        }

        $stepCode = $task->getStepCode();
        $nextStep = null;

        $obsoleteSteps = ['fh_maj_nomenclature', 'fh_wo_nomenclature'];
        if (in_array($stepCode, $obsoleteSteps, true)) {
            $nextStep = null;
            $this->logger->info('Ignoring obsolete step: ' . $stepCode);
        } else {
            if ($stepCode === TicketTask::STEP_FH_ETUDE_PREREQUIS) {
                $nextStep = TicketTask::STEP_FH_MAJ_CAPACITE;
            } elseif ($stepCode === TicketTask::STEP_FH_MAJ_CAPACITE) {
                $nextStep = TicketTask::STEP_FH_ING_TRANS_CAP;
            } elseif ($stepCode === TicketTask::STEP_FH_ING_TRANS_CAP) {
                $upgradeType = $formData['type_upgrade'] ?? 'soft';
                if (strtolower($upgradeType) === 'soft') {
                    $nextStep = TicketTask::STEP_FH_EXECUTION_WO;
                } else {
                    // ✅ UPGRADE HARD → PLANIFICATION DÉPLOIEMENT
                    $this->createDeploiementPlanningTask($ticket, $task, $actor, 'Upgrade Hard FH');
                    return;
                }
            } elseif ($stepCode === TicketTask::STEP_FH_MLO) {
                $nextStep = TicketTask::STEP_FH_LLD;
            } elseif ($stepCode === TicketTask::STEP_FH_LLD) {
                $nextStep = TicketTask::STEP_FH_EXECUTION_WO;
            } elseif ($stepCode === TicketTask::STEP_FH_EXECUTION_WO) {
                $nextStep = null;
            } elseif ($stepCode === TicketTask::STEP_FO_CAPILLAIRE_STUDY) {
                $faisabilite = $formData['faisabilite_ok'] ?? 'OK';
                if ($faisabilite === 'OK') {
                    $nextStep = TicketTask::STEP_DEPLOIEMENT_PLANIFICATION;
                } else {
                    $nextStep = TicketTask::STEP_FO_WO_IP_CREATION;
                }
            }
        }

        // Cas spécial : planification déploiement (pour FO)
        if ($nextStep === TicketTask::STEP_DEPLOIEMENT_PLANIFICATION) {
            $this->createDeploiementPlanningTask($ticket, $task, $actor, 'Raccordement FO (2ème paire)');
            return;
        }

        // Cas spécial : retour WO IP
        if ($nextStep === TicketTask::STEP_FO_WO_IP_CREATION) {
            $this->createWoIpTaskForIngenierieIp($ticket, $task, $actor);
            return;
        }

        if (!$nextStep) {
            $ticket->setCurrentStep($ticket->getTotalSteps());
            $this->workflowService->refreshTicketProgress($ticket);
            $this->workflowService->addHistory($ticket, $actor, 'workflow_completed', 'Workflow terminé, en attente de validation superuser.');
            $this->em->flush();
            return;
        }

        $flowDef = self::STEP_FLOW[$nextStep] ?? null;
        if (!$flowDef) {
            throw new \RuntimeException("Étape suivante non définie : $nextStep");
        }

        $nextUser = $this->pickUser($flowDef['service'], $flowDef['department'], $actor);
        $newTask = $this->workflowService->moveToNextTask($task, $nextUser, $nextStep);
        $newTask->setTitle($flowDef['title']);
        $newTask->setServiceName($flowDef['service']);
        $newTask->setDepartmentName($flowDef['department']);
        $newTask->setSiteData($task->getSiteData());
        $newTask->setFhFields($task->getFhFields());

        $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
        $ticket->setUpdatedAt(new \DateTime());

        $this->workflowService->refreshTicketProgress($ticket);
        $this->workflowService->addHistory(
            $ticket,
            $actor,
            'task_transferred',
            sprintf('Étape "%s" transmise à %s (%s).', $flowDef['title'], $nextUser->getUserIdentifier(), $flowDef['service'])
        );

        $this->em->flush();

        $this->logger->info('FhWorkflowService: task transferred', [
            'new_task_id' => $newTask->getId(),
            'assigned_to' => $nextUser->getUserIdentifier(),
        ]);
    }

    private function createDeploiementPlanningTask(Ticket $ticket, TicketTask $currentTask, User $actor, string $title): void
    {
        $nextUser = $this->pickUser('DEPLOIEMENT', 'deploiement_telecom', $actor);
        $newTask = $this->workflowService->moveToNextTask($currentTask, $nextUser, TicketTask::STEP_DEPLOIEMENT_PLANIFICATION);
        $newTask->setTitle('Planification - ' . $title);
        $newTask->setServiceName('DEPLOIEMENT');
        $newTask->setDepartmentName('deploiement_telecom');
        $newTask->setSiteData($currentTask->getSiteData());
        $newTask->setFhFields($currentTask->getFhFields());
        // ✅ Stockage du type d'upgrade pour le Déploiement
        $newTask->setDeploiementData([
            'capillaire' => false,
            'upgrade_type' => 'hard',  // pour identifier le cas hard FH
        ]);

        $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
        $ticket->setUpdatedAt(new \DateTime());

        $this->workflowService->refreshTicketProgress($ticket);
        $this->workflowService->addHistory(
            $ticket,
            $actor,
            'task_transferred',
            sprintf('Étape "Planification - %s" transmise à %s (DEPLOIEMENT).', $title, $nextUser->getUserIdentifier())
        );

        $this->em->flush();
    }

    private function createWoIpTaskForIngenierieIp(Ticket $ticket, TicketTask $currentTask, User $actor): void
    {
        $nextUser = $this->pickUser('FO', 'ingenierie_ip', $actor);
        $newTask = $this->workflowService->moveToNextTask($currentTask, $nextUser, TicketTask::STEP_FO_WO_IP_CREATION);
        $newTask->setTitle('Création WO IP');
        $newTask->setServiceName('FO');
        $newTask->setDepartmentName('ingenierie_ip');
        $newTask->setSiteData($currentTask->getSiteData());
        $newTask->setFhFields($currentTask->getFhFields());

        $ticket->setCurrentStep($ticket->getCurrentStep() + 1);
        $ticket->setUpdatedAt(new \DateTime());

        $this->workflowService->refreshTicketProgress($ticket);
        $this->workflowService->addHistory(
            $ticket,
            $actor,
            'task_transferred',
            sprintf('Étape "Création WO IP" transmise à %s (FO).', $nextUser->getUserIdentifier())
        );

        $this->em->flush();
    }

    /**
     * 🔧 CORRIGÉ : recherche d’un utilisateur pour un service/département donné.
     * En dernier recours, cherche un superuser, sinon lève une exception.
     */
    private function pickUser(string $service, string $department, User $fallbackUser): User
    {
        // 1. Recherche par département uniquement (pour les départements partagés)
        if (in_array($department, self::SHARED_DEPARTMENTS, true)) {
            $users = $this->userRepo->findBy(['department' => $department]);
            foreach ($users as $u) {
                if (in_array('ROLE_USER', $u->getRoles(), true)) {
                    return $u;
                }
            }
        }

        // 2. Recherche par service + département
        $users = $this->userRepo->findBy(['service' => $service, 'department' => $department]);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }

        // 3. Si département partagé, cherche dans l'autre service
        if (in_array($department, self::SHARED_DEPARTMENTS, true)) {
            $otherService = ($service === 'FO') ? 'FH' : 'FO';
            $users = $this->userRepo->findBy(['service' => $otherService, 'department' => $department]);
            foreach ($users as $u) {
                if (in_array('ROLE_USER', $u->getRoles(), true)) {
                    return $u;
                }
            }
        }

        // 4. Recherche par service uniquement
        $users = $this->userRepo->findBy(['service' => $service]);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }

        // 5. Recherche par service 'SHARED'
        $users = $this->userRepo->findBy(['service' => 'SHARED']);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }

        // 6. Dernier recours : chercher un superuser
        $superusers = $this->userRepo->findUsersByRole('ROLE_SUPERUSER');
        if (!empty($superusers)) {
            $this->logger->warning('Aucun utilisateur ROLE_USER trouvé pour le service/département, utilisation d\'un superuser.', [
                'service' => $service,
                'department' => $department,
                'superuser' => $superusers[0]->getUserIdentifier(),
            ]);
            return $superusers[0];
        }

        // 7. Échec total : lever une exception pour éviter une assignation erronée
        throw new \RuntimeException(sprintf(
            'Impossible de trouver un utilisateur pour le service "%s" et le département "%s". Veuillez créer un utilisateur avec ces attributs ou un superuser.',
            $service, $department
        ));
    }
}