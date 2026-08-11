<?php
// src/Controller/UserSupportRadioController.php

namespace App\Controller;

use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use App\Service\DeploiementWorkflowService;
use App\Service\TicketWorkflowService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/support-radio')]
class UserSupportRadioController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketTaskRepository $taskRepo,
        private TicketWorkflowService $ticketWorkflowService,
        private NotificationService $notificationService,
        private DeploiementWorkflowService $deploiementWorkflowService,
    ) {}

    #[Route('/', name: 'user_support_radio_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $search = trim((string) $request->query->get('search', ''));
        $status = (string) $request->query->get('status', '');

        $qb = $this->taskRepo->createQueryBuilder('t')
            ->leftJoin('t.ticket', 'tk')->addSelect('tk')
            ->where('t.assignedTo = :user')
            ->andWhere('t.departmentName = :dept')
            ->setParameter('user', $user)
            ->setParameter('dept', 'support_radio')
            ->orderBy('t.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('LOWER(t.title) LIKE :search OR LOWER(tk.title) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }
        if ($status) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        $tasks = $qb->getQuery()->getResult();

        $total = count($tasks);
        $pending = count(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_PENDING));
        $inProgress = count(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_IN_PROGRESS));
        $done = count(array_filter($tasks, fn(TicketTask $t) => $t->isDone()));

        return $this->render('dashboard/user/support_radio/index.html.twig', [
            'tasks' => $tasks,
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'done' => $done,
            'searchQuery' => $search,
            'currentStatus' => $status,
        ]);
    }

    #[Route('/task/{id}', name: 'user_support_radio_task_show', methods: ['GET'])]
    public function show(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToSupport($task);

        $ticket = $task->getTicket();
        $sites = $ticket->getTicketSites()->toArray();

        $selectedSiteId = $request->query->get('site');
        $selectedSite = null;
        if ($selectedSiteId) {
            foreach ($sites as $s) {
                if ((string) $s->getId() === (string) $selectedSiteId) {
                    $selectedSite = $s;
                    break;
                }
            }
        }
        if (!$selectedSite && !empty($sites)) {
            $selectedSite = $sites[0];
        }

        $processedCount = count(array_filter($sites, fn($s) => $s->getStatus() === 'completed'));
        $totalSites = count($sites);

        return $this->render('dashboard/user/support_radio/show.html.twig', [
            'task' => $task,
            'ticket' => $ticket,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'totalSites' => $totalSites,
            'processedSites' => $processedCount,
            'fhFields' => $task->getFhFields() ?? [],
        ]);
    }

    #[Route('/task/{id}/start', name: 'user_support_radio_task_start', methods: ['GET'])]
    public function startTask(TicketTask $task): Response
    {
        $this->denyAccessUnlessTaskBelongsToSupport($task);

        if ($task->getStatus() === TicketTask::STATUS_PENDING) {
            $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
            $this->em->flush();
        }

        return $this->redirectToRoute('user_support_radio_task_show', ['id' => $task->getId()]);
    }

    #[Route('/task/{id}/validate', name: 'user_support_radio_validate', methods: ['POST'])]
    public function validate(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToSupport($task);

        $decision = $request->request->get('decision', 'OK');
        $comment = $request->request->get('comment');
        $ok = ($decision === 'OK');

        $siteDecisions = $task->getSiteDecisions() ?? [];
        foreach ($task->getTicket()->getTicketSites() as $site) {
            $siteDecisions[$site->getId()] = array_merge($siteDecisions[$site->getId()] ?? [], [
                'radio_ok' => $ok,
                'radio_comment' => $comment,
                'radio_validated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
        }
        $task->setSiteDecisions($siteDecisions);
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());
        $this->em->flush();

        // ✅ Délégation centralisée
        $this->deploiementWorkflowService->onSupportTaskValidated($task, 'radio', $ok, $comment, $this->getUser());

        $this->ticketWorkflowService->refreshTicketProgress($task->getTicket());

        if ($ok) {
            $this->addFlash('success', 'Validation Support Radio enregistrée.');
        } else {
            $this->addFlash('warning', 'Support Radio marqué NOK -- le workflow a été bloqué pour ce ticket.');
        }

        return $this->redirectToRoute('user_support_radio_index');
    }

    private function denyAccessUnlessTaskBelongsToSupport(TicketTask $task): void
    {
        if ($task->getDepartmentName() !== 'support_radio') {
            throw $this->createAccessDeniedException('Cette tâche n\'appartient pas au Support Radio.');
        }
        if ($task->getAssignedTo()?->getId() !== $this->getUser()->getId()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }
    }
}