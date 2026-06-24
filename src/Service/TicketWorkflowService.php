<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\TicketTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class TicketWorkflowService
{
    public function __construct(private EntityManagerInterface $em) {}

   
   
    public function refreshTicketProgress(Ticket $ticket): void
    {
        $tasks = $ticket->getTasks();
        $total = count($tasks);
        if ($total === 0) {
            $ticket->setProgress(0);
            return;
        }

        $completed = 0;
        foreach ($tasks as $task) {
            if (in_array($task->getStatus(), ['completed', 'done'])) {
                $completed++;
            }
        }

        $progress = (int) round(($completed / $total) * 100);
        $ticket->setProgress($progress);
    }

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

    public function addHistory(Ticket $ticket, ?User $user, string $action, ?string $details = null): TicketHistory
    {
        $history = new TicketHistory();
        $history->setTicket($ticket);
        $history->setUser($user);
        $history->setAction($action);
        $history->setDetails($details);

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
}