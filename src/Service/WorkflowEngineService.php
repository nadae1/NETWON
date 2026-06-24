<?php

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
        private NotificationService $notificationService
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

    /**
     * Complète une tâche avec validation du site (pour workflows multi-sites)
     * et crée automatiquement la tâche suivante pour l'utilisateur suivant
     */
    public function completeTaskWithSiteValidation(
        TicketTask $task,
        User $currentUser,
        string $decision,
        ?string $validatedSite = null,
        ?string $comment = null,
        ?string $proofFile = null
    ): void {
        $ticket = $task->getTicket();

        // Marquer la tâche comme complétée
        $task->setDecision($decision);
        $task->setComment($comment);
        $task->setProofFile($proofFile);
        $task->setSiteValidated($validatedSite);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        // Ajouter un historique
        $historyMessage = sprintf(
            'Tâche terminée. Décision: %s.%s%s',
            $decision,
            $validatedSite ? ' Site validé: ' . $validatedSite . '.' : '',
            $comment ? ' Commentaire: ' . $comment : ''
        );

        $this->addHistory($ticket, $currentUser, 'task_completed', $historyMessage);

        // Mettre à jour la progression du ticket
        $this->refreshTicketProgress($ticket);

        // Créer la tâche suivante si applicable
        $nextStepInfo = $this->getNextStepInfo($task, $decision);
        if ($nextStepInfo) {
            $nextTask = $this->createTask(
                $ticket,
                $nextStepInfo['user'],
                $nextStepInfo['title'],
                $nextStepInfo['description'],
                $nextStepInfo['stepCode']
            );

            // Enregistrer l'utilisateur suivant dans la tâche actuelle
            $task->setNextAssignedTo($nextStepInfo['user']);

            // Notifier l'utilisateur suivant
            $this->notificationService->notify(
                $nextStepInfo['user'],
                'task_assigned',
                sprintf(
                    'Nouvelle tâche : %s pour le ticket #%d - %s',
                    $nextStepInfo['title'],
                    $ticket->getId(),
                    $ticket->getTitle()
                ),
                $ticket
            );
        } else {
            // Aucune étape suivante, marquer le ticket comme complété
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
}

    /**
     * Détermine l'étape suivante et l'utilisateur cible
     */
    private function getNextStepInfo(TicketTask $task, string $decision): ?array
    {
        $stepCode = $task->getStepCode();

        if ($stepCode === 'engineering_ip') {
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
        
        if ($stepCode === 'engineering_ip') {
            $choices = [
                '✅ IP OK - Générer WO' => 'ok',
                '❌ IP NOK - Besoin 2ème paire FO' => 'besoin_fo',
                '🔄 IP NOK - Besoin swap routeur' => 'swap_routeur',
            ];
        }
        
        return $choices;
    }

   public function createInitialIpTask(Ticket $ticket, User $assignedUser): TicketTask
{
    $site = $ticket->getTicketSites()->first();
    $typeTrans = $site ? strtoupper($site->getTypeTrans() ?? '') : '';
    
    $task = new TicketTask();
    $task->setTicket($ticket);
    $task->setAssignedTo($assignedUser);
    $task->setServiceName($assignedUser->getService());
    $task->setDepartmentName($assignedUser->getDepartment());
    $task->setStatus(TicketTask::STATUS_PENDING);
    $task->setCreatedAt(new \DateTime());
    $task->setUpdatedAt(new \DateTime());

    if (str_contains($typeTrans, 'FH')) {
        $task->setTitle('Étude des prérequis FH');
        $task->setDescription('Compléter l\'étude des prérequis transmission FH');
        $task->setStepCode(TicketTask::STEP_FH_ETUDE_PREREQUIS);
    } else {
        $task->setTitle('Étude initiale IP');
        $task->setDescription('Analyser la demande et décider OK / NOK.');
        $task->setStepCode('engineering_ip');
    }

    $this->em->persist($task);
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

        if ($stepCode === 'engineering_ip') {
            if ($decision === 'ok') {
                $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
                if ($nextUser) {
                    $this->createTask(
                        $ticket,
                        $nextUser,
                        'Exécution / Déploiement',
                        'Exécuter la demande sur le terrain.',
                        'execution_site'
                    );
                }
            } elseif ($decision === 'besoin_fo') {
                $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
                if ($nextUser) {
                    $this->createTask(
                        $ticket,
                        $nextUser,
                        'Étude capillaire FO',
                        'Étudier le besoin FO / deuxième paire FO.',
                        'capillaire_fo'
                    );
                }
            } elseif ($decision === 'swap_routeur') {
                $nextUser = $this->findUserByDepartmentOrService('ingenierie_ip', 'IP');
                $this->createTask(
                    $ticket,
                    $nextUser ?? $task->getAssignedTo(),
                    'Reprise ingénierie IP',
                    'Reprendre l\'étude après besoin de swap routeur.',
                    'engineering_ip'
                );
            }
            return;
        }

        if ($stepCode === 'capillaire_fo') {
            $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
            if ($nextUser) {
                $this->createTask(
                    $ticket,
                    $nextUser,
                    'Déploiement FO',
                    'Planifier et réaliser le déploiement FO.',
                    'deploiement_fo'
                );
            }
            return;
        }

        if ($stepCode === 'deploiement_fo') {
            $nextUser = $this->findUserByDepartmentOrService('support_fo', 'FO');
            if ($nextUser) {
                $this->createTask(
                    $ticket,
                    $nextUser,
                    'Validation FO',
                    'Valider la partie FO avant retour vers exécution.',
                    'validation_fo'
                );
            }
            return;
        }

        if ($stepCode === 'validation_fo') {
            $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
            if ($nextUser) {
                $this->createTask(
                    $ticket,
                    $nextUser,
                    'Exécution / Déploiement',
                    'Exécuter la demande après validation FO.',
                    'execution_site'
                );
            }
            return;
        }

        if ($stepCode === 'execution_site') {
            $nextUser = $this->findUserByDepartmentOrService('deploiement', 'DEPLOIEMENT');
            if ($nextUser) {
                $this->createTask(
                    $ticket,
                    $nextUser,
                    'Validation finale',
                    'Valider définitivement le workflow.',
                    'validation_finale'
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
                        $ticket,
                        $nextUser,
                        'Correction après validation NOK',
                        'Corriger les remarques de validation finale.',
                        'execution_site'
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
        string $stepCode
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

        $ticket->setStatus('in_progress');
        $ticket->setUpdatedAt(new \DateTime());

        $this->em->persist($task);

        $this->addHistory(
            $ticket,
            null,
            'task_created',
            'Nouvelle tâche créée: ' . $title . ' pour ' . ($assignedUser->getUsername() ?? $assignedUser->getEmail())
        );

        $this->em->flush();

        return $task;
    }

    public function refreshTicketProgress(Ticket $ticket): void
    {
        $tasks = $ticket->getTasks();
        $total = count($tasks);

        if ($total === 0) {
            $ticket->setProgress(0);
            return;
        }

        $done = 0;
        foreach ($tasks as $task) {
            if ($task->getStatus() === TicketTask::STATUS_DONE) {
                $done++;
            }
        }

        $progress = (int) round(($done / $total) * 100);
        $ticket->setProgress($progress);

        if ($progress >= 100 && $ticket->getStatus() !== 'closed') {
            $ticket->setStatus('completed');
        } elseif ($progress > 0 && $ticket->getStatus() !== 'closed' && $ticket->getStatus() !== 'completed') {
            $ticket->setStatus('in_progress');
        }
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

        $this->em->persist($history);
    }
}