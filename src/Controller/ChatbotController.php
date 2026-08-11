<?php

namespace App\Controller;

use App\Chatbot\ChatbotService;
use App\Chatbot\ReportBuilderService;
use App\Entity\ChatbotConversation;
use App\Repository\ChatbotConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/chatbot')]
class ChatbotController extends AbstractController
{
    #[Route('', name: 'admin_chatbot_index', methods: ['GET'])]
    public function index(ChatbotConversationRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $conversation = $repo->findOneByUser($this->getUser());

        return $this->render('dashboard/admin/chatbot.html.twig', [
            'rawMessagesJson' => json_encode($conversation ? $conversation->getMessages() : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
            'rawExportsJson' => json_encode($conversation ? $conversation->getExports() : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
        ]);
    }

    #[Route('/ask', name: 'admin_chatbot_ask', methods: ['POST'])]
    public function ask(Request $request, ChatbotService $chatbotService, ChatbotConversationRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->getUser();

        $payload = json_decode($request->getContent(), true) ?? [];
        $question = trim($payload['question'] ?? '');
        $history = $payload['history'] ?? [];

        if ($question === '') {
            return $this->json(['error' => 'Question vide.'], 400);
        }

        try {
            $result = $chatbotService->ask($question, $history);

            $conversation = $repo->findOneByUser($user) ?? new ChatbotConversation($user);
            $conversation->setMessages($result['messages']);

            $exports = $conversation->getExports();
            $exports[] = ['question' => $question, 'answer' => $result['answer'], 'raw_data' => $result['raw_data']];
            $conversation->setExports($exports);
            $conversation->setUpdatedAt(new \DateTime());

            $em->persist($conversation);
            $em->flush();

            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur. Vérifie que Ollama tourne.', 'detail' => $e->getMessage()], 500);
        }
    }

    #[Route('/reset', name: 'admin_chatbot_reset', methods: ['POST'])]
    public function reset(ChatbotConversationRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $conversation = $repo->findOneByUser($this->getUser());
        if ($conversation) {
            $em->remove($conversation);
            $em->flush();
        }
        return $this->json(['ok' => true]);
    }

    #[Route('/export/pdf', name: 'admin_chatbot_export_pdf', methods: ['POST'])]
    public function exportPdf(Request $request, ReportBuilderService $reportBuilder): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $payload = json_decode($request->getContent(), true) ?? [];
        return $reportBuilder->buildPdfResponse($payload['question'] ?? '', $payload['answer'] ?? '', $payload['raw_data'] ?? []);
    }

    #[Route('/export/full/pdf', name: 'admin_chatbot_export_full_pdf', methods: ['POST'])]
    public function exportFullPdf(Request $request, ReportBuilderService $reportBuilder): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $payload = json_decode($request->getContent(), true) ?? [];
        return $reportBuilder->buildFullConversationPdfResponse($payload['exports'] ?? []);
    }
}