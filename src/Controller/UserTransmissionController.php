<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\SubWorkflow;
use App\Entity\Ticket;
use App\Form\SubWorkflowRequestType;
use App\Repository\ServiceRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/transmission')]
class UserTransmissionController extends AbstractController
{
    use WorkflowControllerTrait;

    #[Route('/tickets', name: 'user_transmission_tickets', methods: ['GET'])]
    public function index(
        TicketRepository $ticketRepository,
        TicketTaskRepository $taskRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $tickets = $ticketRepository->findAllByService('TRANSMISSION');
        $tasks = $taskRepository->findAllWithTickets('TRANSMISSION');

        return $this->render('dashboard/user/transmission/index.html.twig', [
            'tickets' => $tickets,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/ticket/{id}', name: 'user_transmission_ticket_show', methods: ['GET'])]
    public function show(
        Ticket $ticket,
        ServiceRepository $serviceRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $subworkflowForm = $this->createForm(SubWorkflowRequestType::class, null, [
            'service_choices' => $this->getServiceChoices($serviceRepository),
        ]);

        return $this->render('dashboard/user/transmission/show.html.twig', [
            'ticket' => $ticket,
            'subworkflowForm' => $subworkflowForm->createView(),
        ]);
    }

    #[Route('/ticket/{id}/subworkflow', name: 'user_transmission_subworkflow', methods: ['POST'])]
    public function subworkflow(
        Ticket $ticket,
        Request $request,
        ServiceRepository $serviceRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $form = $this->createForm(SubWorkflowRequestType::class, null, [
            'service_choices' => $this->getServiceChoices($serviceRepository),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $serviceName = $data['service'] ?? 'Autre';
            $reason = $data['reason'] ?? '';

            $child = new Ticket();
            $child->setTitle(sprintf('Sous-workflow %s pour ticket FH #%d', $serviceName, $ticket->getId()));
            $child->setDescription($reason ?: 'Demande de sous-workflow transmissionl.');
            $child->setActionType('subworkflow');
            $child->setStatus('open');
            $child->setPriority('medium');
            $child->setProgress(0);
            $child->setCreatedAt(new \DateTime());
            $child->setUpdatedAt(new \DateTime());
            $child->setCreatedBy($this->getUser());
            $child->setWorkflowType(strtoupper($serviceName));
            $em->persist($child);

            $sub = new SubWorkflow();
            $sub->setParentTicket($ticket);
            $sub->setChildTicket($child);
            $sub->setCreatedBy($this->getUser());
            $sub->setReason($reason ?: 'Demande interne.');
            $em->persist($sub);

            $ticket->setStatus('waiting_' . strtolower(str_replace(' ', '_', $serviceName)));
            $em->flush();

            $recipients = array_filter($userRepository->findAll(), fn($u) => $u->getService() === $serviceName || in_array('ROLE_SUPERUSER', $u->getRoles(), true));
            foreach ($recipients as $recipient) {
                $notificationService->notify(
                    $recipient,
                    Notification::TYPE_WORKFLOW_ASSIGNED,
                    sprintf('Un sous-workflow vers %s a été créé pour le ticket FH #%d.', $serviceName, $ticket->getId()),
                    $child
                );
            }

            $em->flush();
            $this->addFlash('success', 'Sous-workflow créé et notification envoyée.');
        }

        return $this->redirectToRoute('user_transmission_ticket_show', ['id' => $ticket->getId()]);
    }
}
