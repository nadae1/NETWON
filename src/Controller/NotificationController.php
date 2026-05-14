<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/notifications')]
class NotificationController extends AbstractController
{
    #[Route('', name: 'dashboard_notifications', methods: ['GET'])]
    public function index(NotificationRepository $notificationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        return $this->render('dashboard/notifications/index.html.twig', [
            'notifications' => $notificationRepository->findLatestForUser($user, 50),
        ]);
    }

    #[Route('/{id}/read', name: 'dashboard_notification_read', methods: ['POST'])]
    public function markRead(Notification $notification, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();
        if ($notification->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $notification->markRead();
        $em->flush();

        $redirect = $request->headers->get('referer');
        if (is_string($redirect) && $redirect !== '') {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('dashboard_notifications');
    }
}

