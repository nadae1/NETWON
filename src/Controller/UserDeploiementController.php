<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Repository\TicketRepository;
use App\Repository\TicketTaskRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\TicketWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\FhWorkflowService;



#[Route('/user/deploiement')]
class UserDeploiementController extends AbstractController
{
    use WorkflowControllerTrait;

    private string $projectDir;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

      #[Route('/tickets', name: 'user_deploiement_tickets', methods: ['GET'])]
    public function index(
        Request $request,
        TicketRepository $ticketRepository,
        TicketTaskRepository $taskRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $service = $request->query->get('service'); // FO ou FH

        // Récupérer les tickets assignés au déploiement, filtrés par service si demandé
        $qb = $ticketRepository->createQueryBuilder('t')
            ->innerJoin('t.tasks', 'task')
            ->where('task.serviceName = :deploiement')
            ->setParameter('deploiement', 'DEPLOIEMENT')
            ->orderBy('t.createdAt', 'DESC');

        if ($service) {
            $qb->andWhere('t.workflowType = :service')
               ->setParameter('service', strtoupper($service));
        }

        $tickets = $qb->getQuery()->getResult();

        $tasks = $taskRepository->findAllWithTickets('DEPLOIEMENT');

        return $this->render('dashboard/user/deploiement/index.html.twig', [
            'tickets' => $tickets,
            'tasks' => $tasks,
            'currentService' => $service,
        ]);
    }


    
    #[Route('/ticket/{id}', name: 'user_deploiement_ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $tasks = $ticket->getTasks()->toArray();
        usort($tasks, fn($a, $b) => $a->getStepOrder() <=> $b->getStepOrder());
        $task = $tasks[0] ?? null;
        return $this->render('dashboard/user/deploiement/show.html.twig', [
            'ticket' => $ticket,
            'task' => $task,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/ticket/{id}/comment', name: 'user_deploiement_ticket_comment', methods: ['POST'])]
    public function addComment(Ticket $ticket, Request $request, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->saveCommentFromRequest($ticket, $request, $em, $notificationService);
        $this->addFlash('success', 'Commentaire ajouté.');
        return $this->redirectToRoute('user_deploiement_ticket_show', ['id' => $ticket->getId()]);
    }

    
    #[Route('/task/{taskId}/site/{siteId}/data', name: 'user_deploiement_site_data', methods: ['POST'])]
    public function saveDeploiementSiteData(
        int $taskId,
        int $siteId,
        Request $request,
        EntityManagerInterface $em,
        TicketTaskRepository $taskRepo
    ): JsonResponse {
        try {
            $this->denyAccessUnlessGranted('ROLE_USER');
            $task = $taskRepo->find($taskId);
            if (!$task || $task->getAssignedTo() !== $this->getUser()) {
                return $this->json(['status' => 'error', 'message' => 'Accès refusé'], 403);
            }

            $siteData = $task->getSiteData() ?? [];
            $key = (string)$siteId;

            if ($request->request->has('planification')) {
                $siteData[$key]['planification'] = $request->request->get('planification');
            }
            if ($request->request->has('description')) {
                $siteData[$key]['description'] = $request->request->get('description');
            }
            if ($request->request->has('radio_ok')) {
                $siteData[$key]['radio_ok'] = (bool)$request->request->get('radio_ok');
            }
            if ($request->request->has('backhaul_ok')) {
                $siteData[$key]['backhaul_ok'] = (bool)$request->request->get('backhaul_ok');
            }
            if ($request->request->has('terrain_ok')) {
                $siteData[$key]['terrain_ok'] = (bool)$request->request->get('terrain_ok');
            }

            if ($request->request->has('action_realisee')) {
                $siteData[$key]['action_realisee'] = true;
                $siteData[$key]['comment_proof'] = $request->request->get('comment_proof', '');
                $file = $request->files->get('proof_file');
                if ($file && $file->isValid()) {
                    $uploadDir = $this->projectDir . '/public/uploads/proofs';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    $filename = uniqid('proof_') . '.' . $file->guessExtension();
                    $file->move($uploadDir, $filename);
                    $siteData[$key]['proof_path'] = '/uploads/proofs/' . $filename;
                }
            }

            $task->setSiteData($siteData);
            $em->flush();
            return $this->json(['status' => 'ok']);
        } catch (\Exception $e) {
            error_log('Erreur saveDeploiementSiteData: ' . $e->getMessage());
            return $this->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }



    #[Route('/ticket/{id}/task/{taskId}', name: 'user_deploiement_ticket_task', methods: ['POST'])]
public function updateTask(
    Ticket $ticket,
    int $taskId,
    Request $request,
    TicketTaskRepository $taskRepository,
    EntityManagerInterface $em,
    NotificationService $notificationService,
    TicketWorkflowService $ticketWorkflowService,
    UserRepository $userRepository,
    FhWorkflowService $fhWorkflowService // Ajout du service FH
): Response {
    $this->denyAccessUnlessGranted('ROLE_USER');
    $task = $taskRepository->find($taskId);
    if (!$task || $task->getTicket()->getId() !== $ticket->getId()) {
        throw $this->createNotFoundException('Tâche introuvable.');
    }

    $action = $request->request->get('action');
    $comment = trim((string) $request->request->get('comment', ''));

    // Détection du stepCode pour rediriger vers le workflow FH si nécessaire
    $stepCode = $task->getStepCode();

    if ($stepCode === FhWorkflowService::STEP_HARD_MLO) {
        // C'est une tâche FH MLO → utiliser le service FH
        $mloDecision = $request->request->get('mlo_decision'); // 'MLO OK' ou 'MLO NOK'
        if (!$mloDecision) {
            $this->addFlash('error', 'Veuillez choisir une décision MLO.');
            return $this->redirectToRoute('user_deploiement_ticket_show', ['id' => $ticket->getId()]);
        }
        $fhWorkflowService->processMlo($task, $mloDecision, $comment, $this->getUser());
        $this->addFlash('success', 'MLO traité avec succès.');
        return $this->redirectToRoute('user_deploiement_ticket_show', ['id' => $ticket->getId()]);
    }

    // Sinon, logique existante pour FO ou autre
    if ($action === 'complete') {
        $task->setStatus('done');
        $task->setComment($comment ?: $task->getComment());
        $task->setCompletedAt(new \DateTime());
        $ticket->setStatus('in_progress');

        $allDone = true;
        foreach ($ticket->getTasks() as $taskItem) {
            if ($taskItem->getStatus() !== 'done') {
                $allDone = false;
                break;
            }
        }
        if ($allDone) {
            $superuser = $userRepository->findOneBy(['roles' => '["ROLE_SUPERUSER"]']);
            if ($superuser) {
                $validationTask = new TicketTask();
                $validationTask->setTicket($ticket);
                $validationTask->setTitle('Validation KPI et mise à jour capacité');
                $validationTask->setDescription('Vérifier les indicateurs et mettre à jour la capacité du site.');
                $validationTask->setAssignedTo($superuser);
                $validationTask->setStatus('pending');
                $validationTask->setStepCode(TicketTask::STEP_SUPERUSER_VALIDATION);
                $validationTask->setStepOrder($task->getStepOrder() + 1);
                $em->persist($validationTask);
                $notificationService->notify($superuser, 'task_assigned', 'Nouvelle tâche de validation KPI', $ticket);
                $ticket->setStatus('in_progress');
            } else {
                $ticket->setStatus('waiting_superuser');
            }
        } else {
            $notificationService->notify(
                $ticket->getCreatedBy(),
                Notification::TYPE_TICKET_STATUS_CHANGED,
                sprintf('Intervention déploiement terminée pour le ticket #%d.', $ticket->getId()),
                $ticket
            );
        }
    }

    $em->flush();
    $ticketWorkflowService->refreshTicketProgress($ticket);
    return $this->redirectToRoute('user_deploiement_ticket_show', ['id' => $ticket->getId()]);
}
}