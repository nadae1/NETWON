<?php

namespace App\Chatbot;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatbotService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ToolExecutorService $toolExecutor,
        private string $ollamaUrl = 'http://localhost:11434',
        private string $ollamaModel = 'llama3.1:8b',
    ) {}

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
Tu es NetBot, l'assistant expert du back-office NetWON chez Orange Tunisie. Tu réponds à des administrateurs sur des données réseau (sites, workflows/tickets, congestion, capacité).

RÈGLES ABSOLUES :
1. Réponds TOUJOURS dans la même langue que la question (français ou anglais).
2. Pour TOUTE question sur des données réelles, tu DOIS appeler l'outil approprié AVANT de répondre. Ne réponds JAMAIS de mémoire, ne devine JAMAIS un chiffre.
3. N'appelle QUE les outils listés dans ta liste d'outils disponibles. N'invente JAMAIS le nom d'un outil qui n'y figure pas.
4. Si la question demande plusieurs informations, appelle TOUS les outils nécessaires avant de répondre.
5. Dans les résultats des outils, certains champs sont GLOBAUX (tout le réseau, non filtrés) et d'autres sont FILTRÉS selon ce que tu as demandé. Lis bien les noms de champs pour ne pas les confondre (un champ nommé "..._global" concerne TOUT le réseau, un champ nommé "..._pour_ce_filtre" concerne uniquement ta requête).
6. Si un terme ou un filtre demandé (ex: une abréviation) ne correspond à AUCUNE valeur connue du glossaire ci-dessous ni des champs retournés par les outils, demande une clarification courte à l'utilisateur au lieu de deviner ou de réutiliser un ancien résultat d'une question précédente.
7. Ne réutilise JAMAIS le résultat d'une question précédente pour répondre à une nouvelle question : si la nouvelle question porte sur d'autres données, rappelle l'outil.

GLOSSAIRE MÉTIER :
- FH = Faisceaux Hertziens (liaison radio, valeur du champ "service")
- FO = Fibre Optique (valeur du champ "service")
- SHARED = infrastructure partagée entre opérateurs
- TF = [À COMPLÉTER PAR NADA — actuellement non défini, le modèle ne doit pas deviner]
- COTRANS = [À COMPLÉTER PAR NADA]
- NON-COTRANS = [À COMPLÉTER PAR NADA]
- MLO = [À COMPLÉTER PAR NADA]
- LLD = [À COMPLÉTER PAR NADA]
- DropCong = [À COMPLÉTER PAR NADA]
- "critique"/"critical" = champ is_critical = true
- "workflow" et "ticket" = la même chose
PROMPT;
    }

    public function ask(string $question, array $conversationHistory = []): array
    {
        $fullHistory = $conversationHistory;
        if (empty($fullHistory)) {
            $fullHistory[] = ['role' => 'system', 'content' => $this->getSystemPrompt()];
        }
        $fullHistory[] = ['role' => 'user', 'content' => $question];

        // FENÊTRE GLISSANTE : le modèle ne voit que le system prompt + les ~5 derniers échanges,
        // ce qui évite la dérive et la confusion observées sur les longues conversations.
        $workingMessages = $this->buildTrimmedContext($fullHistory);

        $maxIterations = 8;
        $collectedData = [];
        $newMessages = [];

        for ($i = 0; $i < $maxIterations; $i++) {
            $response = $this->callOllama($workingMessages);
            $message = $response['message'];
            $toolCalls = $message['tool_calls'] ?? [];

            if (!empty($toolCalls)) {
                $workingMessages[] = $message;
                $newMessages[] = $message;

                foreach ($toolCalls as $call) {
                    $fnName = $call['function']['name'];
                    $args = $call['function']['arguments'];
                    $result = $this->toolExecutor->execute($fnName, $args);

                    $key = isset($collectedData[$fnName]) ? $fnName . '_' . count($collectedData) : $fnName;
                    $collectedData[$key] = $result;

                    $toolMsg = ['role' => 'tool', 'name' => $fnName, 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                    $workingMessages[] = $toolMsg;
                    $newMessages[] = $toolMsg;
                }
                continue;
            }

            $fullHistory = array_merge($fullHistory, $newMessages, [$message]);

            return [
                'answer' => $message['content'],
                'raw_data' => $collectedData,
                'messages' => $fullHistory,
            ];
        }

        $fullHistory = array_merge($fullHistory, $newMessages);
        return ['answer' => 'La requête est trop complexe, décompose-la en questions plus simples.', 'raw_data' => $collectedData, 'messages' => $fullHistory];
    }

    private function buildTrimmedContext(array $fullHistory, int $keepLastTurns = 5): array
    {
        $system = null;
        $rest = [];
        foreach ($fullHistory as $m) {
            if (($m['role'] ?? null) === 'system' && $system === null) { $system = $m; continue; }
            $rest[] = $m;
        }
        $maxMessages = $keepLastTurns * 4;
        if (count($rest) > $maxMessages) {
            $rest = array_slice($rest, -$maxMessages);
        }
        return $system ? array_merge([$system], $rest) : $rest;
    }

    private function callOllama(array $messages): array
    {
        $response = $this->httpClient->request('POST', $this->ollamaUrl . '/api/chat', [
            'json' => [
                'model' => $this->ollamaModel,
                'messages' => $messages,
                'tools' => ToolDefinitions::getTools(),
                'stream' => false,
                'options' => ['temperature' => 0.0, 'num_ctx' => 8192],
            ],
            'timeout' => 150,
        ]);

        return $response->toArray();
    }
}