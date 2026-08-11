<?php
// src/Service/WorkflowAutoAssigner.php

namespace App\Service;

use App\Entity\ProcessedSite;
use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WorkflowAutoAssigner
{
    private EntityManagerInterface $em;
    private UserRepository $userRepo;
    private LoggerInterface $logger;
    private NotificationService $notificationService;

    public function __construct(
        EntityManagerInterface $em,
        UserRepository $userRepo,
        LoggerInterface $logger,
        NotificationService $notificationService
    ) {
        $this->em = $em;
        $this->userRepo = $userRepo;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
    }

    public function assignUsersForSites(array $sites, Ticket $ticket, User $currentUser): array
    {
        $assignedUsers = [];

        $sitesByService = [];
        foreach ($sites as $site) {
            $service = $site->getService();
            if (empty($service)) {
                $service = 'SHARED';
            }
            $service = strtoupper($service);
            $sitesByService[$service][] = $site;
        }

        foreach ($sitesByService as $service => $serviceSites) {
            $user = $this->findUserForService($service);

            if (!$user) {
                $this->logger->warning('Aucun utilisateur pour le service {service}.', ['service' => $service]);
                $user = $this->findUserForService('SHARED');
                if (!$user) {
                    $user = $currentUser;
                    $this->logger->warning('Assignation à {user} (fallback).', ['user' => $user->getUserIdentifier()]);
                }
            }

            // ✅ CORRIGÉ (bug principal) : le departmentName de la tâche
            // était auparavant déduit UNIQUEMENT du service du site via
            // une table de correspondance figée qui ne couvrait ni
            // 'support_backhaul' ni 'support_radio'. Résultat : si
            // l'utilisateur assigné avait justement pour vrai
            // département 'support_backhaul' (ou 'support_radio'), sa
            // tâche recevait quand même un departmentName totalement
            // différent (ex: 'operator') -- la rendant invisible dans
            // son propre tableau de bord (UserSupportBackhaulController /
            // UserSupportRadioController filtrent strictement sur
            // departmentName), alors que DeadlineAlertService le listait
            // bien comme "responsable" du ticket (lui, ne filtrant pas
            // par département).
            //
            // On utilise maintenant en priorité le VRAI département de
            // l'utilisateur assigné ($user->getDepartment()), qui est la
            // source de vérité utilisée par tous les tableaux de bord
            // spécialisés (Backhaul, Radio, Déploiement, FO...). La table
            // de correspondance par service ne sert plus que de repli si
            // l'utilisateur n'a aucun département renseigné en base.
            $defaultDepartmentByService = match ($service) {
                'FO' => 'ingenierie_ip',
                'FH' => 'support_fh',
                'DEPLOIEMENT' => 'deploiement_telecom',
                default => 'operator',
            };
            $resolvedDepartment = $user->getDepartment() ?: $defaultDepartmentByService;

            $task = new TicketTask();
            $task->setTicket($ticket);
            $task->setAssignedTo($user);
            $task->setTitle('Étude initiale ' . $service);
            $task->setDescription('Analyser la demande et décider OK / NOK.');
            $task->setServiceName($service);
            $task->setDepartmentName($resolvedDepartment);
            $task->setStatus(TicketTask::STATUS_PENDING);
            $task->setStepCode('initial_analysis');
            $task->setStepOrder(1);
            $task->setSiteData(array_map(fn($s) => $s->getId(), $serviceSites));

            $this->em->persist($task);

            $this->notificationService->notify(
                $user,
                NotificationService::TYPE_WORKFLOW_ASSIGNED,
                sprintf(
                    'Nouvelle tâche : %s pour le ticket #%d - %s',
                    $task->getTitle(),
                    $ticket->getId() ?? 0,
                    $ticket->getTitle()
                ),
                $ticket
            );

            $assignedUsers[] = $user;
        }

        $this->em->flush();

        return $assignedUsers;
    }

    private function findUserForService(string $service): ?User
    {
        $users = $this->userRepo->findBy(['service' => $service]);
        foreach ($users as $user) {
            if (in_array('ROLE_USER', $user->getRoles(), true)) {
                return $user;
            }
        }
        return null;
    }
}