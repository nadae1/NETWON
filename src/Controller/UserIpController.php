<?php
// src/Controller/UserIpController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/ip')]
class UserIpController extends AbstractController
{
    #[Route('/task/{id}', name: 'user_ip_task_show')]
    public function show(int $id): Response
    {
        return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $id]);
    }

    #[Route('/tasks', name: 'user_ip_tasks')]
    public function index(): Response
    {
        return $this->redirectToRoute('dashboard_fo_index');
    }
}