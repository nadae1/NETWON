<?php
// src/Controller/SupportTransController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/support-trans')]
class UserSupportTransController  extends AbstractController
{
    #[Route('/task/{id}', name: 'support_trans_task_show')]
    public function show(int $id): Response
    {
        return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $id]);
    }

    #[Route('/tasks', name: 'support_trans_tasks')]
    public function index(): Response
    {
        return $this->redirectToRoute('dashboard_fo_index');
    }
}