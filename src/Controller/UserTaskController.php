<?php
namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\SubWorkflow;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/tasks')]
class UserTaskController extends AbstractController
{
    #[Route('/', name: 'user_tasks_dashboard')]
    public function dashboard(TicketTaskRepository $taskRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        $tasks = $taskRepo->createQueryBuilder('t')
            ->where('t.assignedTo = :user')
            ->andWhere('t.status != :completed')
            ->setParameter('user', $user)
            ->setParameter('completed', 'completed')
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
        return $this->render('dashboard/user/tasks/dashboard.html.twig', [
            'tasks' => $tasks,
            'user' => $user,
        ]);
    }

    #[Route('/{id}', name: 'user_task_show')]
    public function show(TicketTask $task): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($task->getAssignedTo() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Cette tâche ne vous est pas assignée.');
        }
        return $this->render('dashboard/user/tasks/show.html.twig', ['task' => $task]);
    }
#[Route('/{id}/complete', name: 'user_task_complete', methods: ['POST'])]
public function complete(
    TicketTask $task,
    Request $request,
    EntityManagerInterface $em,
    NotificationService $notificationService,
    UserRepository $userRepository
): Response {
    $this->denyAccessUnlessGranted('ROLE_USER');
    if ($task->getAssignedTo() !== $this->getUser()) {
        throw $this->createAccessDeniedException();
    }

    $comment = $request->request->get('comment');
    $cardType = $request->request->get('card_type');
    $capacity = $request->request->get('capacity');
    $decision = $request->request->get('decision');
    $woIpRef = $request->request->get('wo_ip_reference');

    if ($cardType) $task->setCardType($cardType);
    if ($capacity !== null && $capacity !== '') $task->setMeasuredCapacity((float)$capacity);
    if ($decision) $task->setIpDecision($decision);
    if ($woIpRef) $task->setWoIpReference($woIpRef);
    if ($comment) $task->setComment($comment);

    $ticket = $task->getTicket();

    // --- Traitement selon la décision ---
    if ($decision === 'OK') {
        $ticket->setStatus('in_progress');
        $deployUser = $userRepository->findOneBy(['service' => 'DEPLOIEMENT']);
        if (!$deployUser) {
            $this->addFlash('error', 'Aucun utilisateur Déploiement trouvé.');
            return $this->redirectToRoute('user_tasks_dashboard');
        }
        $deployTask = new TicketTask();
        $deployTask->setTicket($ticket);
        $deployTask->setTitle('Exécution WO IP');
        $deployTask->setDescription('Planifier et exécuter l\'intervention pour WO IP : ' . ($woIpRef ?: 'N/A'));
        $deployTask->setAssignedTo($deployUser);
        $deployTask->setStatus('pending');
        $deployTask->setStepOrder($task->getStepOrder() + 1);
        $deployTask->setServiceName($deployUser->getService());
        $deployTask->setDepartmentName($deployUser->getDepartment());
        $deployTask->setStepCode(TicketTask::STEP_DEPLOIEMENT_FO);
        $em->persist($deployTask);
        $notificationService->notify($deployUser, 'task_assigned', 'Nouvelle tâche de déploiement pour le ticket #'.$ticket->getId(), $ticket);
        $task->setStatus('completed');
    } elseif ($decision === 'NOK_2EME_PAIRE') {
        $ticket->setStatus('waiting_capillaire');
        $capUser = $userRepository->findOneBy(['service' => 'INGENIERIE_CAPILLAIRE']);
        if (!$capUser) {
            $this->addFlash('error', 'Aucun utilisateur Ingénierie Capillaire trouvé.');
            return $this->redirectToRoute('user_tasks_dashboard');
        }
        $childTicket = new Ticket();
        $childTicket->setTitle('[Sous-workflow] 2ème paire FO - ' . $ticket->getTitle());
        $childTicket->setDescription($comment ?: 'Demande de 2ème paire FO');
        $childTicket->setWorkflowType($ticket->getWorkflowType());
        $childTicket->setStatus('open');
        $childTicket->setCreatedBy($this->getUser());
        $childTicket->setCreatedAt(new \DateTime());
        $em->persist($childTicket);
        foreach ($ticket->getTicketSites() as $ts) {
            $newTs = new TicketSite();
            $newTs->setTicket($childTicket);
            $newTs->setSiteName($ts->getSiteName());
            $newTs->setTypeTrans($ts->getTypeTrans());
            $newTs->setServiceName($ts->getServiceName());
            $em->persist($newTs);
        }
        $capTask = new TicketTask();
        $capTask->setTicket($childTicket);
        $capTask->setTitle('Étude et réalisation 2ème paire FO');
        $capTask->setDescription('Étudier faisabilité, concevoir, demander raccordement, valider.');
        $capTask->setAssignedTo($capUser);
        $capTask->setStatus('pending');
        $capTask->setStepOrder(1);
        $capTask->setServiceName($capUser->getService());
        $capTask->setDepartmentName($capUser->getDepartment());
        $capTask->setStepCode(TicketTask::STEP_CAPILLAIRE_FO);
        $em->persist($capTask);
        $sub = new SubWorkflow();
        $sub->setParentTicket($ticket);
        $sub->setChildTicket($childTicket);
        $sub->setCreatedBy($this->getUser());
        $sub->setReason($comment ?: 'Demande 2ème paire FO');
        $em->persist($sub);
        $notificationService->notify($capUser, 'subworkflow_created', 'Sous-workflow pour 2ème paire FO', $childTicket);
        $task->setStatus('completed');
    } elseif ($decision === 'NOK_SWAP') {
        $ticket->setStatus('waiting_swap');
        $swapUser = $userRepository->findOneBy(['service' => 'IP']);
        if (!$swapUser) {
            $this->addFlash('error', 'Aucun utilisateur pour analyse swap trouvé.');
            return $this->redirectToRoute('user_tasks_dashboard');
        }
        $childTicket = new Ticket();
        $childTicket->setTitle('[Sous-workflow] Swap routeur - ' . $ticket->getTitle());
        $childTicket->setDescription($comment ?: 'Demande de swap routeur');
        $childTicket->setWorkflowType($ticket->getWorkflowType());
        $childTicket->setStatus('open');
        $childTicket->setCreatedBy($this->getUser());
        $childTicket->setCreatedAt(new \DateTime());
        $em->persist($childTicket);
        foreach ($ticket->getTicketSites() as $ts) {
            $newTs = new TicketSite();
            $newTs->setTicket($childTicket);
            $newTs->setSiteName($ts->getSiteName());
            $newTs->setTypeTrans($ts->getTypeTrans());
            $newTs->setServiceName($ts->getServiceName());
            $em->persist($newTs);
        }
        $swapTask = new TicketTask();
        $swapTask->setTicket($childTicket);
        $swapTask->setTitle('Analyse swap routeur');
        $swapTask->setDescription('Étudier la nécessité et faisabilité du swap routeur.');
        $swapTask->setAssignedTo($swapUser);
        $swapTask->setStatus('pending');
        $swapTask->setStepOrder(1);
        $swapTask->setServiceName($swapUser->getService());
        $swapTask->setDepartmentName($swapUser->getDepartment());
        $swapTask->setStepCode(TicketTask::STEP_ENGINEERING_IP);
        $em->persist($swapTask);
        $sub = new SubWorkflow();
        $sub->setParentTicket($ticket);
        $sub->setChildTicket($childTicket);
        $sub->setCreatedBy($this->getUser());
        $sub->setReason($comment ?: 'Demande swap routeur');
        $em->persist($sub);
        $notificationService->notify($swapUser, 'subworkflow_created', 'Sous-workflow swap routeur', $childTicket);
        $task->setStatus('completed');
    } else {
        $this->addFlash('error', 'Décision non reconnue.');
        return $this->redirectToRoute('user_task_show', ['id' => $task->getId()]);
    }

    $task->setCompletedAt(new \DateTime());
    $ticket->setUpdatedAt(new \DateTime());
    $em->flush();

    // --- Vérifier si toutes les tâches du ticket sont terminées ---
    $allCompleted = true;
    foreach ($ticket->getTasks() as $t) {
        if ($t->getStatus() !== 'completed') {
            $allCompleted = false;
            break;
        }
    }
    if ($allCompleted && $ticket->getStatus() !== 'completed') {
        $ticket->setStatus('completed');
        // Notifier les SuperUsers
        $superUsers = $userRepository->findBy(['roles' => '["ROLE_SUPERUSER"]']);
        foreach ($superUsers as $su) {
            $notificationService->notify($su, 'ticket_completed', 'Le ticket #'.$ticket->getId().' est terminé.', $ticket);
        }
        $em->flush();
    }

    $this->addFlash('success', 'Tâche traitée avec succès.');
    return $this->redirectToRoute('user_tasks_dashboard');
}
}
