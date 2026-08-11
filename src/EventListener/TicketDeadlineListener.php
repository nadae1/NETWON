<?php

namespace App\EventListener;

use App\Entity\Ticket;
use App\Service\DeadlineAlertService;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;

class TicketDeadlineListener
{
    public function __construct(private DeadlineAlertService $alertService) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Ticket) {
            $this->alertService->checkTicket($entity);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Ticket) {
            $this->alertService->checkTicket($entity);
        }
    }
}