<?php
// src/Service/TicketWorkflowService.php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TicketWorkflowService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notificationService,
        private UserRepository $userRepository
    ) {}

  
    public function canAccessTicket(Ticket $ticket, User $user): bool
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPERUSER', $roles, true)) {
            return true;
        }
        if ($ticket->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }
        foreach ($ticket->getTasks() as $task) {
            if ($task->getAssignedTo()?->getId() === $user->getId()) {
                return true;
            }
            if ($user->getService() && $task->getServiceName() === $user->getService()) {
                return true;
            }
        }
        return false;
    }

    public function canActOnTask(TicketTask $task, User $user): bool
    {
        if ($task->getStatus() === TicketTask::STATUS_DONE) {
            return false;
        }
        if ($task->getAssignedTo()?->getId() === $user->getId()) {
            return true;
        }
        return $user->getService() !== null && $task->getServiceName() === $user->getService();
    }

    public function addHistory(Ticket $ticket, ?User $user, string $action, ?string $details = null, ?string $site = null): TicketHistory
    {
        $history = new TicketHistory();
        $history->setTicket($ticket);
        $history->setUser($user);
        $history->setAction($action);
        $history->setDetails($details);
        $history->setSite($site ?? $ticket->getSiteName() ?? null);
        $history->setDateJour(new \DateTime());
        $this->em->persist($history);
        $this->em->flush();
        return $history;
    }

    public function moveToNextTask(TicketTask $currentTask, User $nextUser, string $nextStepCode): TicketTask
    {
        $nextTask = new TicketTask();
        $nextTask->setTicket($currentTask->getTicket());
        $nextTask->setTitle('Suite du workflow');
        $nextTask->setDescription($currentTask->getDescription());
        $nextTask->setAssignedTo($nextUser);
        $nextTask->setStatus('pending');
        $nextTask->setStepOrder($currentTask->getStepOrder() + 1);
        $nextTask->setStepCode($nextStepCode);
        $nextTask->setServiceName($nextUser->getService());
        $this->em->persist($nextTask);
        return $nextTask;
    }

    private function requestSuperuserValidation(Ticket $ticket): void
    {
        $notifiedLevels = $ticket->getNotifiedLevels() ?? [];
        if (!empty($notifiedLevels['superuser_validation_requested'])) {
            return;
        }
        $ticket->setNotifiedLevels(array_merge($notifiedLevels, [
            'superuser_validation_requested' => (new \DateTime())->format(DATE_ATOM),
        ]));
        $this->notificationService->notifyWorkflowReadyForSuperuser($ticket);
        $this->addHistory($ticket, null, 'workflow_ready_for_validation', 'Workflow terminé à 100% en attente de validation superuser.');
    }


    // src/Service/TicketWorkflowService.php (extrait de refreshTicketProgress)
public function refreshTicketProgress(Ticket $ticket): void
{
    $currentStep = $ticket->getCurrentStep() ?: 1;
    $totalSteps = $ticket->getTotalSteps() ?: 5;
    $progress = (int) round(($currentStep / $totalSteps) * 100);
    $progress = min(100, $progress);
    $ticket->setProgress($progress);

    if ($progress >= 100) {
        if (!in_array($ticket->getStatus(), ['closed', 'waiting_superuser', 'completed'])) {
            $ticket->setStatus('waiting_superuser');
            $ticket->setUpdatedAt(new \DateTime());
            $this->requestSuperuserValidation($ticket);
        }
        return;
    }

    if ($progress > 0 && !in_array($ticket->getStatus(), ['closed', 'waiting_superuser', 'completed'])) {
        $ticket->setStatus('in_progress');
        $ticket->setUpdatedAt(new \DateTime());
    }
}
}