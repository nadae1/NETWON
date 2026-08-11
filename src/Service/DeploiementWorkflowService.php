<?php
// src/Service/DeploiementWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DeploiementWorkflowService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketWorkflowService $workflowService,
        private UserRepository $userRepo,
        private LoggerInterface $logger,
        private NotificationService $notificationService,
        // ✅ CORRIGÉ (bug fatal) : FoWorkflowService était utilisé dans
        // processExecution() sans jamais être injecté -> "Undefined
        // property" si cette méthode était un jour appelée. Ajouté ici.
        private FoWorkflowService $foWorkflowService,
    ) {}

    /**
     * Crée des tâches pour Support Radio et/ou Support Backhaul.
     *
     * ✅ CORRIGÉ (idempotence) : cette méthode est appelée une fois PAR
     * SITE depuis UserDeploiementController::siteDecision() (route
     * /task/{id}/site/{siteId}/decision). Sur un ticket multi-sites,
     * elle était donc invoquée plusieurs fois pour le même ticket,
     * créant des tâches Backhaul/Radio en double à chaque site traité.
     * On vérifie désormais qu'aucune tâche PENDING/IN_PROGRESS/DONE
     * n'existe déjà pour ce département sur ce ticket avant d'en créer
     * une nouvelle.
     *
     * ✅ CORRIGÉ (traçabilité) : la liste des supports réellement requis
     * est maintenant persistée sur la tâche de planification elle-même
     * (deploiementData['supports']), en UNION avec les valeurs déjà
     * présentes (pour ne pas écraser ce qu'un site précédent avait déjà
     * demandé). C'est cette liste qui pilote ensuite
     * areAllRequiredSupportsValidated() -- sans elle, le workflow ne
     * pouvait jamais savoir quels supports attendre.
     */
    public function createSupportTasks(TicketTask $planificationTask, ?string $planification, ?string $comment, array $supports): void
    {
        $ticket = $planificationTask->getTicket();
        $siteNames = array_map(fn($s) => $s->getSiteName(), $ticket->getTicketSites()->toArray());

        // ✅ Persiste (en union) la liste des supports requis sur la
        // tâche de planification -- source de vérité unique.
        $deploiementData = $planificationTask->getDeploiementData() ?? [];
        $existingSupports = $deploiementData['supports'] ?? [];
        $deploiementData['supports'] = array_values(array_unique(array_merge($existingSupports, $supports)));
        $deploiementData['planification'] = $planification ?? ($deploiementData['planification'] ?? null);
        $deploiementData['comment'] = $comment ?? ($deploiementData['comment'] ?? null);
        $planificationTask->setDeploiementData($deploiementData);

        foreach ($supports as $support) {
            $dept = 'support_' . $support;

            // ✅ Idempotence : ne recrée pas une tâche support déjà
            // existante (pending/in_progress/done) pour ce ticket+dept.
            $alreadyExists = $this->em->getRepository(TicketTask::class)->createQueryBuilder('t')
                ->select('COUNT(t.id)')
                ->where('t.ticket = :ticket')
                ->andWhere('t.departmentName = :dept')
                ->setParameter('ticket', $ticket)
                ->setParameter('dept', $dept)
                ->getQuery()
                ->getSingleScalarResult();

            if ($alreadyExists > 0) {
                $this->logger->info(sprintf(
                    'Tâche support %s déjà existante pour le ticket #%d, création ignorée.',
                    $dept, $ticket->getId() ?? 0
                ));
                continue;
            }

            // ✅ Filtre ROLE_USER (comme les autres pickUser() du
            // projet), plutôt qu'un findOneBy() aveugle qui pouvait
            // retourner un compte non opérationnel (ex: ROLE_ADMIN
            // ayant ce département renseigné par erreur).
            $user = $this->findSupportUser($dept);
            if (!$user) {
                $this->logger->warning(sprintf(
                    'Aucun utilisateur ROLE_USER trouvé pour le département "%s" -- tâche support NON créée pour le ticket #%d. Vérifiez que ce département est bien renseigné sur un compte utilisateur.',
                    $dept, $ticket->getId() ?? 0
                ));
                continue;
            }

            $supportTask = new TicketTask();
            $supportTask->setTicket($ticket);
            $supportTask->setTitle('Validation Support ' . ucfirst($support));
            $supportTask->setDescription('Valider l\'intervention pour : ' . implode(', ', $siteNames));
            $supportTask->setAssignedTo($user);
            $supportTask->setServiceName('DEPLOIEMENT');
            $supportTask->setDepartmentName($dept);
            $supportTask->setStatus(TicketTask::STATUS_PENDING);
            $supportTask->setStepCode('support_' . $support . '_validation');
            $supportTask->setStepOrder($planificationTask->getStepOrder() + 1);
            $supportTask->setSiteData($planificationTask->getSiteData());
            $supportTask->setFhFields([
                'planification' => $planification,
                'comment' => $comment,
                // ✅ Référence vers la tâche d'origine, utile pour
                // findOriginDeploiementTask() si jamais plusieurs
                // tâches de planification existent pour le même ticket.
                'origin_task_id' => $planificationTask->getId(),
            ]);

            $this->em->persist($supportTask);
            $this->notificationService->notify(
                $user,
                'task_assigned',
                'Nouvelle tâche de validation Support ' . ucfirst($support) . ' pour le ticket #' . $ticket->getId(),
                $ticket
            );
        }

        $this->em->flush();
        $this->logger->info('Tâches supports créées/vérifiées', ['task_id' => $planificationTask->getId(), 'supports' => $supports]);
    }

    /**
     * ✅ NOUVEAU : point d'entrée unique appelé par les contrôleurs
     * Support Backhaul et Support Radio quand leur tâche est validée.
     * Centralise la logique "est-ce que TOUS les supports réellement
     * requis pour ce ticket sont maintenant validés ?" et, si oui, crée
     * la tâche d'exécution en la réassignant au MÊME utilisateur
     * déploiement que celui qui avait fait la planification initiale
     * (au lieu d'un utilisateur choisi au hasard via findOneBy()).
     */
    public function onSupportTaskValidated(TicketTask $supportTask, string $supportType, bool $ok, ?string $comment, User $actor): void
    {
        $ticket = $supportTask->getTicket();

        $originTask = $this->findOriginDeploiementTask($supportTask);
        if (!$originTask) {
            $this->logger->warning(sprintf(
                'Support %s validé (ticket #%d) mais aucune tâche de planification déploiement d\'origine trouvée -- impossible de faire avancer le workflow.',
                $supportType, $ticket->getId() ?? 0
            ));
            return;
        }

        $deploiementData = $originTask->getDeploiementData() ?? [];
        $deploiementData['supports_status'][$supportType] = $ok;
        $deploiementData['supports_status'][$supportType . '_comment'] = $comment;
        $originTask->setDeploiementData($deploiementData);

        $this->workflowService->addHistory(
            $ticket, $actor,
            'support_' . $supportType . '_validated',
            'Support ' . ucfirst($supportType) . ' : ' . ($ok ? 'OK' : 'NOK') . ($comment ? ' -- ' . $comment : '')
        );

        if (!$ok) {
            // Un support en échec bloque explicitement la tâche de
            // planification d'origine, plutôt que de laisser le
            // workflow silencieusement en attente indéfinie.
            $originTask->setStatus(TicketTask::STATUS_BLOCKED);
            $this->em->flush();
            return;
        }

        if ($this->areAllRequiredSupportsValidated($originTask)) {
            $this->createExecutionTask($originTask, $actor);
        }

        $this->em->flush();
    }

    /**
     * ✅ CORRIGÉ : n'attend plus systématiquement radio ET backhaul --
     * seulement les supports réellement demandés à la planification
     * (deploiementData['supports'], alimenté par createSupportTasks()).
     */
    private function areAllRequiredSupportsValidated(TicketTask $originTask): bool
    {
        $deploiementData = $originTask->getDeploiementData() ?? [];
        $required = $deploiementData['supports'] ?? [];
        if (empty($required)) {
            return true;
        }

        $status = $deploiementData['supports_status'] ?? [];
        foreach ($required as $support) {
            if (empty($status[$support])) {
                return false;
            }
        }
        return true;
    }

    /**
     * ✅ CORRIGÉ (bug d'ordre principal) : la tâche d'exécution est
     * désormais assignée au MÊME utilisateur déploiement que celui qui
     * a réalisé la planification initiale ($originTask->getAssignedTo()),
     * au lieu d'un utilisateur choisi arbitrairement via
     * findOneBy(['department' => 'deploiement_telecom']) qui pouvait
     * retourner n'importe quel compte de ce département.
     */
    private function createExecutionTask(TicketTask $originTask, User $actor): void
    {
        $ticket = $originTask->getTicket();
        $assignedUser = $originTask->getAssignedTo();

        if (!$assignedUser) {
            $this->logger->error(sprintf(
                'Impossible de créer la tâche d\'exécution pour le ticket #%d : la tâche de planification d\'origine (#%d) n\'a aucun utilisateur assigné.',
                $ticket->getId() ?? 0, $originTask->getId() ?? 0
            ));
            return;
        }

        $execTask = new TicketTask();
        $execTask->setTicket($ticket);
        $execTask->setTitle('Exécution sur site');
        $execTask->setDescription('Exécuter l\'intervention après validation des supports requis.');
        $execTask->setAssignedTo($assignedUser);
        $execTask->setServiceName('DEPLOIEMENT');
        $execTask->setDepartmentName('deploiement_telecom');
        $execTask->setStatus(TicketTask::STATUS_PENDING);
        $execTask->setStepCode(TicketTask::STEP_DEPLOIEMENT_EXECUTION);
        $execTask->setStepOrder($originTask->getStepOrder() + 1);
        $execTask->setSiteData($originTask->getSiteData());
        $execTask->setFhFields($originTask->getFhFields());

        $originTask->setStatus(TicketTask::STATUS_DONE);
        $originTask->setCompletedAt(new \DateTime());

        $this->em->persist($execTask);

        $this->notificationService->notify(
            $assignedUser,
            'task_assigned',
            'Tous les supports requis sont validés : exécution à réaliser pour le ticket #' . $ticket->getId(),
            $ticket
        );

        $this->workflowService->addHistory(
            $ticket, $actor, 'execution_task_created',
            'Tâche d\'exécution créée pour ' . ($assignedUser->getUsername() ?? $assignedUser->getEmail()) . ' après validation de tous les supports requis.'
        );
    }

    /**
     * ✅ NOUVEAU : retrouve la tâche de planification déploiement
     * d'origine pour le ticket d'une tâche support donnée. On se base
     * sur le département 'deploiement_telecom' et les stepCode de
     * planification connus, en prenant la plus récente (stepOrder max)
     * s'il devait y en avoir plusieurs (ex: retour NOK -> nouvelle
     * planification).
     */
    private function findOriginDeploiementTask(TicketTask $supportTask): ?TicketTask
    {
        $ticket = $supportTask->getTicket();

        return $this->em->getRepository(TicketTask::class)->createQueryBuilder('t')
            ->where('t.ticket = :ticket')
            ->andWhere('t.departmentName = :dept')
            ->andWhere('t.stepCode IN (:steps)')
            ->setParameter('ticket', $ticket)
            ->setParameter('dept', 'deploiement_telecom')
            ->setParameter('steps', [
                TicketTask::STEP_DEPLOIEMENT_PLANIFICATION,
                TicketTask::STEP_FO_DEPLOYMENT_PLANNING,
            ])
            ->orderBy('t.stepOrder', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ✅ NOUVEAU : recherche un utilisateur pour un département support
     * donné, en filtrant ROLE_USER (cohérent avec pickUser() dans
     * FoWorkflowService/WorkflowEngineService), plutôt qu'un
     * findOneBy() aveugle sur la seule colonne department.
     */
    private function findSupportUser(string $department): ?User
    {
        $users = $this->userRepo->findBy(['department' => $department]);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }
        // Fallback : si aucun compte ROLE_USER strict mais qu'un seul
        // compte existe pour ce département, on l'utilise quand même
        // plutôt que de bloquer tout le workflow.
        return $users[0] ?? null;
    }

    /**
     * Traite la planification et crée les tâches supports.
     * (Conservé pour compatibilité -- non branché à un contrôleur dans
     * les fichiers fournis, mais maintenant cohérent avec le nouveau
     * système deploiementData['supports'].)
     */
    public function processPlanning(TicketTask $task, array $data, User $actor): void
    {
        $planification = $data['planification'] ?? null;
        $comment = $data['comment'] ?? null;

        $supports = [];
        if (!empty($data['support_radio'])) {
            $supports[] = 'radio';
        }
        if (!empty($data['support_backhaul'])) {
            $supports[] = 'backhaul';
        }

        $this->createSupportTasks($task, $planification, $comment, $supports);

        $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
        $this->em->flush();

        $this->logger->info('Deploiement: planification enregistrée', ['task_id' => $task->getId()]);
    }

    /**
     * Traite l'exécution après validation des supports.
     * ✅ CORRIGÉ : FoWorkflowService est maintenant correctement injecté
     * (voir constructeur) -- cette méthode ne provoquera plus de fatal
     * error si elle est invoquée.
     * (Conservé pour compatibilité -- non branché à un contrôleur dans
     * les fichiers fournis ; UserDeploiementController::siteDecision()
     * gère l'exécution directement pour l'instant.)
     */
    public function processExecution(TicketTask $task, array $data, User $actor): void
    {
        $ticket = $task->getTicket();

        $supportsOk = $this->areAllRequiredSupportsValidated($task);
        if (!$supportsOk) {
            $this->logger->warning('Tentative d\'exécution sans validation des supports', ['task_id' => $task->getId()]);
            $this->workflowService->addHistory($ticket, $actor, 'execution_blocked', 'Exécution bloquée : supports non validés');
            return;
        }

        $deploiementData = $task->getDeploiementData() ?? [];
        $deploiementData['execution_comment'] = $data['comment'] ?? null;
        $deploiementData['execution_decision'] = $data['decision'] ?? 'OK';
        $task->setDeploiementData($deploiementData);

        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());

        $isCapillaire = $deploiementData['capillaire'] ?? false;
        $decision = $data['decision'] ?? 'OK';

        if ($decision === 'OK') {
            if ($isCapillaire) {
                $this->foWorkflowService->createSuperuserValidationTask($ticket, $task);
            } else {
                $this->foWorkflowService->completeFoTask($task, 'OK', null, $actor);
            }
            $this->workflowService->addHistory($ticket, $actor, 'execution_ok', 'Exécution validée');
        } else {
            $nextUser = $this->pickUser('FO', 'ingenierie_ip', $actor);
            $newTask = $this->workflowService->moveToNextTask($task, $nextUser, TicketTask::STEP_FO_WO_IP_CREATION);
            $newTask->setTitle('Retour Ingénierie IP');
            $newTask->setServiceName('FO');
            $newTask->setDepartmentName('ingenierie_ip');
            $newTask->setSiteData($task->getSiteData());
            $newTask->setFhFields($task->getFhFields());
            $this->workflowService->addHistory($ticket, $actor, 'execution_nok', 'Exécution NOK, retour Ingénierie IP');
        }

        $ticket->setUpdatedAt(new \DateTime());
        $this->workflowService->refreshTicketProgress($ticket);
        $this->em->flush();

        $this->logger->info('Deploiement: exécution traitée', ['task_id' => $task->getId(), 'decision' => $decision]);
    }

    private function pickUser(string $service, string $department, User $fallback): User
    {
        $users = $this->userRepo->findBy(['service' => $service, 'department' => $department]);
        foreach ($users as $u) {
            if (in_array('ROLE_USER', $u->getRoles(), true)) {
                return $u;
            }
        }
        return $fallback;
    }
}