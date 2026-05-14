<?php
namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\SubWorkflow;
use App\Entity\SiteUpdateRequest;
use App\Entity\User;
use App\Repository\TicketRepository;
use App\Repository\TicketTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\NotificationService;

#[Route('/user/ip')]
class UserIpController extends AbstractController
{
    #[Route('/tickets', name: 'user_ip_tickets')]
    public function index(TicketRepository $ticketRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $tickets = $ticketRepo->createQueryBuilder('t')
            ->leftJoin('t.assignedUsers', 'u')
            ->where('u.id = :userId')
            ->orWhere('t.workflowType = :type')
            ->setParameter('userId', $user->getId())
            ->setParameter('type', 'FO')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        return $this->render('dashboard/user/ip/index.html.twig', ['tickets' => $tickets]);
    }

    #[Route('/ticket/{id}', name: 'user_ip_ticket_show')]
    public function show(Ticket $ticket, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        if (!$ticket->getAssignedUsers()->contains($user) && !in_array('ROLE_SUPERUSER', $user->getRoles())) {
            throw $this->createAccessDeniedException();
        }
        $tasks = $ticket->getTasks()->toArray();
        usort($tasks, fn($a, $b) => $a->getStepOrder() <=> $b->getStepOrder());
        return $this->render('dashboard/user/ip/show.html.twig', [
            'ticket' => $ticket,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/ticket/{id}/task/{taskId}/update', name: 'user_ip_task_update', methods: ['POST'])]
    public function updateTask(Ticket $ticket, TicketTask $task, Request $request, EntityManagerInterface $em, NotificationService $notif): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        if ($task->getAssignedTo() !== $user && !in_array('ROLE_SUPERUSER', $user->getRoles())) {
            throw $this->createAccessDeniedException();
        }
        $completed = $request->request->getBoolean('completed');
        $comment = $request->request->get('comment');
        $capture = $request->files->get('capture');
        if ($task->isRequiresCapture() && $capture && $capture->isValid()) {
            $newFilename = uniqid() . '.' . $capture->guessExtension();
            $capture->move($this->getParameter('kernel.project_dir') . '/public/uploads/captures', $newFilename);
            $task->setCapturePath('/uploads/captures/' . $newFilename);
        }
        $task->setStatus($completed ? 'completed' : 'pending');
        $task->setComment($comment);
        $task->setUpdatedAt(new \DateTime());
        $allCompleted = true;
        foreach ($ticket->getTasks() as $t) {
            if ($t->getStatus() !== 'completed') {
                $allCompleted = false;
                break;
            }
        }
        if ($allCompleted) {
            $ticket->setStatus('completed');
        } else {
            $ticket->setStatus('in_progress');
        }
        $em->flush();
        $this->addFlash('success', 'Tâche mise à jour.');
        return $this->redirectToRoute('user_ip_ticket_show', ['id' => $ticket->getId()]);
    }

    #[Route('/ticket/{id}/subworkflow', name: 'user_ip_subworkflow', methods: ['POST'])]
    public function createSubworkflow(Ticket $parentTicket, Request $request, EntityManagerInterface $em, NotificationService $notif): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $targetService = $request->request->get('target_service');
        $reason = $request->request->get('reason');
        $childTicket = new Ticket();
        $childTicket->setTitle('[Sous-workflow] ' . $parentTicket->getTitle());
        $childTicket->setDescription($reason);
        $childTicket->setWorkflowType($parentTicket->getWorkflowType());
        $childTicket->setStatus('open');
        $childTicket->setCreatedBy($this->getUser());
        $childTicket->setCreatedAt(new \DateTime());
        $em->persist($childTicket);
        foreach ($parentTicket->getTicketSites() as $ts) {
            $newTs = clone $ts;
            $newTs->setTicket($childTicket);
            $em->persist($newTs);
        }
        $sub = new SubWorkflow();
        $sub->setParentTicket($parentTicket);
        $sub->setChildTicket($childTicket);
        $sub->setCreatedBy($this->getUser());
        $sub->setReason($reason);
        $em->persist($sub);
        $targetUsers = $em->getRepository(User::class)->findBy(['service' => $targetService]);
        if (!empty($targetUsers)) {
            $childTicket->addAssignedUser($targetUsers[0]);
            $notif->notify($targetUsers[0], 'subworkflow_created', "Nouveau sous-workflow pour site {$parentTicket->getTicketSites()->first()->getSiteName()}", $childTicket);
        }
        $parentTicket->setStatus('waiting_other_service');
        $em->flush();
        $this->addFlash('success', 'Sous-workflow créé.');
        return $this->redirectToRoute('user_ip_ticket_show', ['id' => $parentTicket->getId()]);
    }

    #[Route('/ticket/{id}/propose-update', name: 'user_ip_propose_update', methods: ['POST'])]
    public function proposeUpdate(Ticket $ticket, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $newCapacity = $request->request->get('new_capacity');
        $newSupportType = $request->request->get('new_support_type');
        $newStatus = $request->request->get('new_status');
        $updateRequest = new SiteUpdateRequest();
        $updateRequest->setTicket($ticket);
        $updateRequest->setSiteName($ticket->getTicketSites()->first()->getSiteName());
        $updateRequest->setNewCapacity((float) $newCapacity);
        $updateRequest->setNewSupportType($newSupportType);
        $updateRequest->setStatus(SiteUpdateRequest::STATUS_PENDING);
        $updateRequest->setRequestedBy($this->getUser());
        $em->persist($updateRequest);
        $em->flush();
        $this->addFlash('success', 'Demande de mise à jour envoyée au SuperUser.');
        return $this->redirectToRoute('user_ip_ticket_show', ['id' => $ticket->getId()]);
    }

    #[Route('/', name: 'user_ip_dashboard')]
    public function dashboard(TicketTaskRepository $taskRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        $tasks = $taskRepo->findBy(['assignedTo' => $user]);
        $tickets = [];
        foreach ($tasks as $task) {
            $ticket = $task->getTicket();
            if (!in_array($ticket, $tickets, true)) {
                $tickets[] = $ticket;
            }
        }
        $extraTickets = $this->getDoctrine()->getRepository(Ticket::class)->findBy(['workflowType' => 'FO']);
        foreach ($extraTickets as $ticket) {
            if (!in_array($ticket, $tickets, true)) {
                $tickets[] = $ticket;
            }
        }
        return $this->render('dashboard/user/ip/dashboard.html.twig', [
            'tickets' => $tickets,
            'user' => $user,
        ]);
    }
}