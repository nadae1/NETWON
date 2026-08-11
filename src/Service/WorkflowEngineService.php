<?php
// src/Service/WorkflowEngineService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class WorkflowEngineService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notificationService,
        private TicketWorkflowService $ticketWorkflowService
    ) {}

    public function startTask(TicketTask $task, User $user): void
    {
        $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
        $task->setStartedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $this->addHistory(
            $task->getTicket(),
            $user,
            'task_started',
            'La tâche #' . $task->getId() . ' a été démarrée par ' . $user->getUsername()
        );

        $this->em->flush();
    }

    public function completeTask(TicketTask $task, User $user, string $decision, ?string $comment = null, ?string $proofFile = null): void
    {
        $task->setDecision($decision);
        $task->setComment($comment);
        $task->setProofFile($proofFile);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $this->addHistory(
            $task->getTicket(),
            $user,
            'task_completed',
            sprintf('Tâche terminée. Décision: %s. Commentaire: %s', $decision, $comment ?? 'aucun')
        );

        $this->refreshTicketProgress($task->getTicket());
        $this->em->flush();
    }

    public function completeTaskWithSiteValidation(
        TicketTask $task,
        User $currentUser,
        string $decision,
        ?string $validatedSite = null,
        ?string $comment = null,
        ?string $proofFile = null
    ): void {
        $ticket = $task->getTicket();

        $task->setDecision($decision);
        $task->setComment($comment);
        $task->setProofFile($proofFile);
        $task->setSiteValidated($validatedSite);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $historyMessage = sprintf(
            'Tâche terminée. Décision: %s.%s%s',
            $decision,
            $validatedSite ? ' Site validé: ' . $validatedSite . '.' : '',
            $comment ? ' Commentaire: ' . $comment : ''
        );

        $this->addHistory($ticket, $currentUser, 'task_completed', $historyMessage);
        $this->refreshTicketProgress($ticket);

        $nextStepInfo = $this->getNextStepInfo($task, $decision);
        if ($nextStepInfo) {
            // createTask() se charge maintenant d'envoyer la notification/email
            $nextTask = $this->createTask(
                $ticket,
                $nextStepInfo['user'],
                $nextStepInfo['title'],
                $nextStepInfo['description'],
                $nextStepInfo['stepCode'],
                $task->getSiteData()
            );

            $task->setNextAssignedTo($nextStepInfo['user']);
        } else {
            $ticket->setStatus('completed');
            $this->addHistory($ticket, $currentUser, 'workflow_completed', 'Workflow complètement terminé');
        }

        $this->em->flush();
    }

    public function createInitialFhTask(Ticket $ticket, User $assignedTo): void
    {
        $task = new TicketTask();
        $task->setTicket($ticket);
        $task->setTitle('Étude des prérequis FH');
        $task->setDescription('Compléter l\'étude des prérequis transmission');
        $task->setAssignedTo($assignedTo);
        $task->setStatus('pending');
        $task->setStepOrder(1);
        $task->setStepCode(TicketTask::STEP_FH_ETUDE_PREREQUIS);
        $task->setServiceName($assignedTo->getService());
        $this->em->persist($task);

        $this->notificationService->notify(
            $assignedTo,
            NotificationService::TYPE_WORKFLOW_ASSIGNED,
            sprintf(
                'Nouvelle tâche : %s pour le ticket #%d - %s',
                $task->getTitle(),
                $ticket->getId() ?? 0,
                $ticket->getTitle()
            ),
            $ticket
        );

        $this->em->flush();
    }

    private function getNextStepInfo(TicketTask $task, string $decision): ?array
    {
        $stepCode = $task->getStepCode();

        if ($stepCode === 'engineering_ip' || $stepCode === 'initial_analysis') {
            if ($decision === 'ok') {
                $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
                return $nextUser ? [
                    'user' => $nextUser,
                    'title' => 'Exécution / Déploiement',
                    'description' => 'Exécuter la demande sur le terrain.',
                    'stepCode' => 'execution_site'
                ] : null;
            } elseif ($decision === 'besoin_fo') {
                $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
                return $nextUser ? [
                    'user' => $nextUser,
                    'title' => 'Étude capillaire FO',
                    'description' => 'Étudier le besoin FO / deuxième paire FO.',
                    'stepCode' => 'capillaire_fo'
                ] : null;
            } elseif ($decision === 'swap_routeur') {
                $nextUser = $this->findUserByDepartmentOrService('ingenierie_ip', 'IP');
                return ($nextUser ?? $task->getAssignedTo()) ? [
                    'user' => $nextUser ?? $task->getAssignedTo(),
                    'title' => 'Reprise ingénierie IP',
                    'description' => 'Reprendre l\'étude après besoin de swap routeur.',
                    'stepCode' => 'engineering_ip'
                ] : null;
            }
        }

        if ($stepCode === 'capillaire_fo') {
            $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
            return $nextUser ? [
                'user' => $nextUser,
                'title' => 'Déploiement FO',
                'description' => 'Planifier et réaliser le déploiement FO.',
                'stepCode' => 'deploiement_fo'
            ] : null;
        }

        if ($stepCode === 'deploiement_fo') {
            $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
            return $nextUser ? [
                'user' => $nextUser,
                'title' => 'Validation FO',
                'description' => 'Valider la partie FO avant retour vers exécution.',
                'stepCode' => 'validation_fo'
            ] : null;
        }

        if ($stepCode === 'validation_fo') {
            $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
            return $nextUser ? [
                'user' => $nextUser,
                'title' => 'Exécution / Déploiement',
                'description' => 'Exécuter la demande après validation FO.',
                'stepCode' => 'execution_site'
            ] : null;
        }

        if ($stepCode === 'execution_site') {
            if ($decision === 'ok') {
                $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
                return $nextUser ? [
                    'user' => $nextUser,
                    'title' => 'Validation finale',
                    'description' => 'Valider définitivement le workflow.',
                    'stepCode' => 'validation_finale'
                ] : null;
            }
        }

        if ($stepCode === 'validation_finale') {
            if ($decision !== 'ok') {
                $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
                return $nextUser ? [
                    'user' => $nextUser,
                    'title' => 'Correction après validation NOK',
                    'description' => 'Corriger les remarques de validation finale.',
                    'stepCode' => 'execution_site'
                ] : null;
            }
        }

        return null;
    }

    public function choicesFor(TicketTask $task): array
    {
        $stepCode = $task->getStepCode();

        $choices = [
            '✅ OK - Action réalisée avec succès' => 'ok',
            '❌ NOK - Action non réalisée' => 'nok',
        ];

        if ($stepCode === 'analyse_complementaire') {
            $choices = [
                '🔧 Besoin 2ème paire FO' => 'besoin_fo',
                '🔄 Besoin swap routeur' => 'swap_routeur',
                '✅ OK - Pas d\'action supplémentaire' => 'ok',
            ];
        }

        if ($stepCode === 'engineering_ip' || $stepCode === 'initial_analysis') {
            $choices = [
                '✅ IP OK - Générer WO' => 'ok',
                '❌ IP NOK - Besoin 2ème paire FO' => 'besoin_fo',
                '🔄 IP NOK - Besoin swap routeur' => 'swap_routeur',
            ];
        }

        return $choices;
    }

    public function createInitialIpTask(Ticket $ticket, User $assignedUser, array $sites = []): TicketTask
    {
        $service = strtoupper($assignedUser->getService() ?? '');

        $task = new TicketTask();
        $task->setTicket($ticket);
        $task->setAssignedTo($assignedUser);
        $task->setServiceName($assignedUser->getService());
        $task->setDepartmentName($assignedUser->getDepartment());
        $task->setStatus(TicketTask::STATUS_PENDING);
        $task->setCreatedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());
        $task->setSiteData(array_map(fn($s) => $s->getId(), $sites));

        if ($service === 'FH') {
            $task->setTitle('Étude des prérequis FH');
            $task->setDescription('Compléter l\'étude des prérequis transmission FH');
            $task->setStepCode(TicketTask::STEP_FH_ETUDE_PREREQUIS);
        } elseif ($service === 'IP') {
            $task->setTitle('Étude initiale IP');
            $task->setDescription('Analyser la demande et décider OK / NOK.');
            $task->setStepCode('engineering_ip');
        } else {
            $task->setTitle('Étude initiale ' . $service);
            $task->setDescription('Analyser la demande et décider OK / NOK.');
            $task->setStepCode('initial_analysis');
        }

        $this->em->persist($task);

        $this->notificationService->notify(
            $assignedUser,
            NotificationService::TYPE_WORKFLOW_ASSIGNED,
            sprintf(
                'Nouvelle tâche : %s pour le ticket #%d - %s',
                $task->getTitle(),
                $ticket->getId() ?? 0,
                $ticket->getTitle()
            ),
            $ticket
        );

        $this->em->flush();

        return $task;
    }

    public function completeTaskAndMoveNext(TicketTask $task, User $user, string $decision = 'ok'): void
    {
        $ticket = $task->getTicket();

        $task->setDecision($decision);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        if ($ticket) {
            $ticket->setUpdatedAt(new \DateTime());

            $this->addHistory(
                $ticket,
                $user,
                'task_completed',
                'La tâche #' . $task->getId() . ' a été terminée. Décision: ' . strtoupper($decision)
            );

            $this->moveNext($ticket, $task, $decision);
            $this->refreshTicketProgress($ticket);
        }

        $this->em->flush();
    }

    private function moveNext(Ticket $ticket, TicketTask $task, string $decision): void
    {
        $stepCode = $task->getStepCode();
        $siteData = $task->getSiteData();

        if ($stepCode === 'engineering_ip' || $stepCode === 'initial_analysis') {
            if ($decision === 'ok') {
                $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
                if ($nextUser) {
                    $this->createTask(
                        $ticket, $nextUser,
                        'Exécution / Déploiement',
                        'Exécuter la demande sur le terrain.',
                        'execution_site',
                        $siteData
                    );
                }
            } elseif ($decision === 'besoin_fo') {
                $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
                if ($nextUser) {
                    $this->createTask(
                        $ticket, $nextUser,
                        'Étude capillaire FO',
                        'Étudier le besoin FO / deuxième paire FO.',
                        'capillaire_fo',
                        $siteData
                    );
                }
            } elseif ($decision === 'swap_routeur') {
                $nextUser = $this->findUserByDepartmentOrService('ingenierie_ip', 'IP');
                $this->createTask(
                    $ticket, $nextUser ?? $task->getAssignedTo(),
                    'Reprise ingénierie IP',
                    'Reprendre l\'étude après besoin de swap routeur.',
                    'engineering_ip',
                    $siteData
                );
            }
            return;
        }

        if ($stepCode === 'capillaire_fo') {
            $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
            if ($nextUser) {
                $this->createTask(
                    $ticket, $nextUser,
                    'Déploiement FO',
                    'Planifier et réaliser le déploiement FO.',
                    'deploiement_fo',
                    $siteData
                );
            }
            return;
        }

        if ($stepCode === 'deploiement_fo') {
            $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
            if ($nextUser) {
                $this->createTask(
                    $ticket, $nextUser,
                    'Validation FO',
                    'Valider la partie FO avant retour vers exécution.',
                    'validation_fo',
                    $siteData
                );
            }
            return;
        }

        if ($stepCode === 'validation_fo') {
            $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
            if ($nextUser) {
                $this->createTask(
                    $ticket, $nextUser,
                    'Exécution / Déploiement',
                    'Exécuter la demande après validation FO.',
                    'execution_site',
                    $siteData
                );
            }
            return;
        }

        if ($stepCode === 'execution_site') {
            $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
            if ($nextUser) {
                $this->createTask(
                    $ticket, $nextUser,
                    'Validation finale',
                    'Valider définitivement le workflow.',
                    'validation_finale',
                    $siteData
                );
            }
            return;
        }

        if ($stepCode === 'validation_finale') {
            if ($decision === 'ok') {
                $ticket->setStatus('completed');
                $this->addHistory($ticket, null, 'workflow_completed', 'Workflow terminé avec succès');
            } else {
                $ticket->setStatus('in_progress');
                $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
                if ($nextUser) {
                    $this->createTask(
                        $ticket, $nextUser,
                        'Correction après validation NOK',
                        'Corriger les remarques de validation finale.',
                        'execution_site',
                        $siteData
                    );
                }
            }
        }
    }

    public function createTask(
        Ticket $ticket,
        User $assignedUser,
        string $title,
        string $description,
        string $stepCode,
        ?array $siteData = null
    ): TicketTask {
        $task = new TicketTask();
        $task->setTicket($ticket);
        $task->setAssignedTo($assignedUser);
        $task->setTitle($title);
        $task->setDescription($description);
        $task->setServiceName($assignedUser->getService());
        $task->setDepartmentName($assignedUser->getDepartment());
        $task->setStepCode($stepCode);
        $task->setStatus(TicketTask::STATUS_PENDING);
        $task->setCreatedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());
        $task->setSiteData($siteData);

        $ticket->setStatus('in_progress');
        $ticket->setUpdatedAt(new \DateTime());

        $this->em->persist($task);

        $this->addHistory(
            $ticket,
            null,
            'task_created',
            'Nouvelle tâche créée: ' . $title . ' pour ' . ($assignedUser->getUsername() ?? $assignedUser->getEmail())
        );

        // ✅ Correction : notifier (base + email) l'utilisateur assigné à CHAQUE création de tâche
        $this->notificationService->notify(
            $assignedUser,
            NotificationService::TYPE_WORKFLOW_ASSIGNED,
            sprintf(
                'Nouvelle tâche : %s pour le ticket #%d - %s',
                $title,
                $ticket->getId() ?? 0,
                $ticket->getTitle()
            ),
            $ticket
        );

        $this->em->flush();

        return $task;
    }

    public function refreshTicketProgress(Ticket $ticket): void
    {
        $this->ticketWorkflowService->refreshTicketProgress($ticket);
    }

    private function findUserByDepartmentOrService(?string $department, ?string $service): ?User
    {
        $qb = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->setMaxResults(1)
            ->orderBy('u.id', 'ASC');

        if ($department) {
            $qb->andWhere('LOWER(u.department) = :department')
                ->setParameter('department', strtolower($department));
        } elseif ($service) {
            $qb->andWhere('UPPER(u.service) = :service')
                ->setParameter('service', strtoupper($service));
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Ajoute un historique avec date_jour correctement remplie.
     */
    private function addHistory(
        Ticket $ticket,
        ?User $user,
        string $action,
        ?string $details = null
    ): void {
        $history = new TicketHistory();
        $history->setTicket($ticket);
        $history->setUser($user);
        $history->setAction($action);
        $history->setDetails($details);
        $history->setDateJour(new \DateTime()); // ✅ Correction : remplir date_jour

        $this->em->persist($history);
    }
}