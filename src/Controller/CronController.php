<?php

namespace App\Controller;

use App\Service\DeadlineAlertService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class CronController extends AbstractController
{
    #[Route('/cron/check-deadlines', name: 'cron_check_deadlines')]
    public function checkDeadlines(Request $request, DeadlineAlertService $alertService): JsonResponse
    {
        // Sécurisation par token (défini dans .env)
        $token = $this->getParameter('cron_token');
        if ($request->query->get('token') !== $token) {
            throw $this->createAccessDeniedException('Token invalide');
        }

        $created = $alertService->checkAndNotify();
        return $this->json(['created' => $created]);
    }
}