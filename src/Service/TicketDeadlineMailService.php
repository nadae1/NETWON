<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class TicketDeadlineMailService
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int, string> $responsibles
     */
    public function sendOverdueTicketEmail(User $recipient, Ticket $ticket, int $daysOverdue, array $responsibles): bool
    {
        $emailAddress = $recipient->getEmail();
        if (!$emailAddress) {
            $this->logger->warning(sprintf(
                'Overdue ticket email skipped: recipient %s has no email for ticket #%d',
                $recipient->getUsername() ?? 'unknown',
                (int) $ticket->getId()
            ));

            return false;
        }

        try {
            $message = (new TemplatedEmail())
                ->from(new Address('no-reply@yourdomain.com', 'NetWON'))
                ->to(new Address($emailAddress, $recipient->getUsername() ?? $emailAddress))
                ->subject('⚠️ Ticket en retard')
                ->htmlTemplate('email/ticket_overdue_notification.html.twig')
                ->context([
                    'recipient' => $recipient,
                    'ticket' => $ticket,
                    'daysOverdue' => $daysOverdue,
                    'responsibles' => $responsibles,
                    'siteName' => $ticket->getSiteName(),
                    'service' => $ticket->getService(),
                    'createdAt' => $ticket->getCreatedAt(),
                    'deadline' => $this->getEffectiveDeadline($ticket),
                    'priority' => $ticket->getPriority(),
                    'status' => $ticket->getStatus(),
                ]);

            $this->mailer->send($message);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error(sprintf(
                'Failed to send overdue ticket email to %s for ticket #%d: %s',
                $emailAddress,
                (int) $ticket->getId(),
                $e->getMessage()
            ));

            return false;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Unexpected error while sending overdue ticket email to %s for ticket #%d: %s',
                $emailAddress,
                (int) $ticket->getId(),
                $e->getMessage()
            ));

            return false;
        }
    }

    private function getEffectiveDeadline(Ticket $ticket): ?\DateTimeInterface
    {
        return $ticket->getDeadlineAt() ?? $ticket->getDeadline();
    }
}