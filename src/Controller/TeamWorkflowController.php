<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Form\TaskCompletionType;
use App\Form\WorkflowDecisionType;
use App\Repository\TicketTaskRepository;
use App\Service\TicketWorkflowService;
use App\Service\WorkflowEngineService;
use App\Service\IaRecommendationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/workflow/team')]
class TeamWorkflowController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private IaRecommendationService $iaService
    ) {}

    #[Route('/', name: 'team_workflow_index', methods: ['GET'])]
    public function index(TicketTaskRepository $taskRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $activeTasks = $taskRepository->findActiveByAssignedUser($user);
        $doneTasks = $taskRepository->findByAssignedUser($user);
        
        // Ajouter les infos de blocage pour chaque tâche
        foreach ($activeTasks as $task) {
            $task->ticketBlockedInfo = $this->iaService->getWorkflowBlocker($task->getTicket());
        }

        return $this->render('dashboard/workflow/team/index.html.twig', [
            'tasks' => $activeTasks,
            'doneTasks' => $doneTasks,
            'serviceName' => $user->getService(),
        ]);
    }

    #[Route('/task/{id}', name: 'team_workflow_task_show', methods: ['GET', 'POST'])]
    public function task(
        TicketTask $task,
        Request $request,
        TicketWorkflowService $accessService,
        WorkflowEngineService $engine
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        if (!$accessService->canActOnTask($task, $user)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas traiter cette étape.');
        }

        if ($task->getStatus() === TicketTask::STATUS_PENDING) {
            $engine->startTask($task, $user);
        }

        // Extraire les sites du ticket pour le formulaire
        $ticket = $task->getTicket();
        $sites = $ticket->getTicketSites()->map(fn($ts) => $ts->getSiteName())->toArray();

        $form = $this->createForm(WorkflowDecisionType::class, null, [
            'choices' => $engine->choicesFor($task),
            'sites' => $sites,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $uploadedFile = $form->get('proofFile')->getData();
            $filename = null;
            $siteValidated = null;

            if ($uploadedFile) {
                $filename = uniqid('workflow_proof_', true) . '.' . $uploadedFile->guessExtension();
                try {
                    $uploadedFile->move($this->getParameter('ticket_proofs_directory'), $filename);
                } catch (FileException) {
                    $this->addFlash('error', 'Erreur lors de l\'upload du fichier.');
                    return $this->redirectToRoute('team_workflow_task_show', ['id' => $task->getId()]);
                }
            }

            // Récupérer le site validé si la forme l'a fourni
            if ($form->has('siteValidated') && $form->get('siteValidated')->getData()) {
                $siteValidated = $form->get('siteValidated')->getData();
            }

            // Utiliser la nouvelle méthode qui gère le workflow complet
            $engine->completeTaskWithSiteValidation(
                $task,
                $user,
                $data['decision'],
                $siteValidated,
                $data['comment'] ?? null,
                $filename
            );
            $this->addFlash('success', 'Tâche traitée avec succès. L\'étape suivante a été assignée.');
            return $this->redirectToRoute('team_workflow_index');
        }

        return $this->render('dashboard/workflow/team/task.html.twig', [
            'task' => $task,
            'ticket' => $task->getTicket(),
            'form' => $form->createView(),
            'blockerInfo' => $this->iaService->getWorkflowBlocker($task->getTicket()),
        ]);
    }

    #[Route('/task/{id}/sub-workflow', name: 'team_workflow_create_sub', methods: ['POST'])]
    public function createSubWorkflow(
        TicketTask $parentTask,
        Request $request,
        WorkflowEngineService $engine
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $targetService = $request->request->get('target_service');
        $targetUserId = $request->request->get('target_user');
        $description = $request->request->get('sub_description');

        if (!$targetService || !$targetUserId || !$description) {
            $this->addFlash('error', 'Tous les champs sont obligatoires');
            return $this->redirectToRoute('team_workflow_task_show', ['id' => $parentTask->getId()]);
        }

        $targetUser = $this->em->getRepository(User::class)->find($targetUserId);
        
        if (!$targetUser) {
            $this->addFlash('error', 'Utilisateur cible introuvable');
            return $this->redirectToRoute('team_workflow_task_show', ['id' => $parentTask->getId()]);
        }

        $parentTicket = $parentTask->getTicket();

        // Créer un sous-ticket (workflow enfant)
        $subTicket = new Ticket();
        $subTicket->setTitle('[Sous-demande] ' . $parentTicket->getTitle());
        $subTicket->setDescription($description);
        $subTicket->setActionType('SUB_WORKFLOW');
        $subTicket->setStatus('open');
        $subTicket->setProgress(0);
        $subTicket->setCreatedBy($user);
        $subTicket->setCreatedAt(new \DateTime());
        
        $this->em->persist($subTicket);

        // Copier les sites du ticket parent
        foreach ($parentTicket->getTicketSites() as $parentSite) {
            $subSite = new TicketSite();
            $subSite->setTicket($subTicket);
            $subSite->setSiteName($parentSite->getSiteName());
            $subSite->setTypeTrans($parentSite->getTypeTrans());
            $subSite->setServiceName($parentSite->getServiceName());
            $this->em->persist($subSite);
        }

        // Créer la tâche pour l'utilisateur cible
        $subTask = new TicketTask();
        $subTask->setTicket($subTicket);
        $subTask->setAssignedTo($targetUser);
        $subTask->setTitle('Traitement sous-demande: ' . $description);
        $subTask->setDescription($description);
        $subTask->setServiceName($targetUser->getService());
        $subTask->setDepartmentName($targetUser->getDepartment());
        $subTask->setStepCode('sub_workflow');
        $subTask->setStatus(TicketTask::STATUS_PENDING);
        $subTask->setCreatedAt(new \DateTime());
        $subTask->setUpdatedAt(new \DateTime());
        
        $this->em->persist($subTask);

        // Ajouter un commentaire dans le ticket parent
        $comment = new TicketComment();
        $comment->setTicket($parentTicket);
        $comment->setUser($user);
        $comment->setMessage(sprintf(
            'Sous-demande créée pour le service %s (User: %s) : %s',
            $targetService,
            $targetUser->getUsername(),
            $description
        ));
        $comment->setCreatedAt(new \DateTime());
        $this->em->persist($comment);

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Sous-demande créée avec succès. Ticket #%d assigné à %s',
            $subTicket->getId(),
            $targetUser->getUsername()
        ));

        return $this->redirectToRoute('team_workflow_task_show', ['id' => $parentTask->getId()]);
    }

    #[Route('/task/{id}/comment', name: 'team_workflow_add_comment', methods: ['POST'])]
    public function addComment(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $message = $request->request->get('comment_message');
        
        if (!$message) {
            $this->addFlash('error', 'Le commentaire ne peut pas être vide');
            return $this->redirectToRoute('team_workflow_task_show', ['id' => $task->getId()]);
        }

        $comment = new TicketComment();
        $comment->setTicket($task->getTicket());
        $comment->setUser($user);
        $comment->setMessage($message);
        $comment->setCreatedAt(new \DateTime());
        
        $this->em->persist($comment);
        $this->em->flush();

        $this->addFlash('success', 'Commentaire ajouté');

        return $this->redirectToRoute('team_workflow_task_show', ['id' => $task->getId()]);
    }
}