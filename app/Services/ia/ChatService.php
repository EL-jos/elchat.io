<?php
namespace App\Services\ia;

use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Models\UnansweredQuestion;
use App\Models\WidgetSetting;
use App\Services\chunks\ChunkHydrationService;
use App\Services\chunks\ChunkRankingService;
use App\Services\queryAnalyzer\QueryAnalyzer;
use App\Services\rag\ContextCompressor;
use App\Services\rag\ContextValidator;
use App\Services\rag\RetrievalOptimizer;
use App\Services\SimilarityService;
use App\Services\vector\VectorSearchService;
use App\Traits\TextNormalizer;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatService
{

    use TextNormalizer;

    public function __construct(
        protected EmbeddingService $embeddingService,
        protected PromptBuilder $promptBuilder,
        protected VectorSearchService $vectorSearchService,
        protected ChunkHydrationService $chunkHydrationService,
        protected ChunkRankingService $chunkRankingService,
        protected ContextBuilder $contextBuilder,
        protected FollowUpDetector $followUpDetector,
        protected ConversationRewriterService $rewriter,
        protected EntityResolver $entityResolver,
        protected IntentClassifier $intentClassifier,
        protected ConversationStateManager $conversationStateManager,
        protected ResponseGuard $responseGuard,


        protected QueryAnalyzer $queryAnalyzer,
        protected RetrievalOptimizer $retrievalOptimizer,
        protected ContextValidator $contextValidator,
        protected ContextCompressor $contextCompressor
    )
    {}

    /**
     * Réponse commerciale incarnée (mode production)
     */
    public function answer(Site $site, string $question, Conversation $conversation): string
    {

        /*Log::info('CHAT ANSWER DEBUG', [
            'conversation_id' => $conversation->id,
            'conversation_site_id' => $conversation->site_id ?? null,
            'site_object_id' => $site->id,
        ]);*/

        // ─────────────────────────────
        // 0️⃣ Intent Classification
        // ─────────────────────────────
        $intent = $this->intentClassifier->classify($question);
        $earlyResponse = $this->conversationStateManager
            ->handle($intent, $conversation);

        if ($earlyResponse !== null) {
            return $earlyResponse;
        }

        // ─────────────────────────────
        // 1️⃣ Récupération historique court
        // ─────────────────────────────
        $history = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            //->skip(1)
            ->take(3)
            ->get()
            ->reverse()
            ->map(function ($m) {
                if ($m->role === 'bot') {
                    return [
                        'role' => 'assistant',
                        //'content' => '[Résumé interne: réponse déjà fournie, informations factuelles uniquement, sans nouveaux produits ni promesses]',
                        'content' => $m->content,
                    ];
                }

                return [
                    'role' => 'user',
                    'content' => $m->content,
                ];
            })
            ->toArray();

        // ─────────────────────────────
        // 0.5️⃣ Préparer la question (rewrite si follow-up)
        // ─────────────────────────────
        $preparedQuestion = $this->prepareQuestion($question, $conversation);

        $queryPlan = $this->queryAnalyzer->analyze($preparedQuestion, $conversation);
        Log::info("Query Plan Prepare", [
            "original_question" => $question,
            "prepared_question" => $preparedQuestion,
            "queryPlan" => $queryPlan,
        ]);

        $query = $queryPlan->cleanQuery;
        Log::info("QueryPlan", [
            "clean_query" => $queryPlan->cleanQuery,
            "strategy" => $queryPlan->searchStrategy,
            "queries" => $queryPlan->searchQueries,
            "sub_queries" => $queryPlan->subQueries,
            "top_k" => $queryPlan->topK
        ]);
        $queries = null;
        switch ($queryPlan->searchStrategy) {

            case 'decomposition':
                $queries = $queryPlan->subQueries ?: [$queryPlan->cleanQuery];
                break;

            case 'multi_query':
                $queries = $queryPlan->searchQueries ?: [$queryPlan->cleanQuery];
                break;

            default:
                $queries = [$queryPlan->cleanQuery];
        }
        Log::info("Queries", [
            "queries" => $queries,
        ]);

        // ─────────────────────────────
        // 2️⃣ Embedding
        // ─────────────────────────────
        $results = [];

        foreach ($queries as $q) {

            $embedding = $this->embeddingService->getEmbedding($q);

            $partial = $this->vectorSearchService->search(
                embedding: $embedding,
                siteId: $site->id,
                limit: $queryPlan->topK,
                scoreThreshold: floatval($site->settings->min_similarity_score),
                collection: "chunks_{$site->id}"
            );

            $results = array_merge($results, $partial);
        }

        $results = collect($results)
            ->sortByDesc('score')
            ->unique('id')
            ->values()
            ->toArray();

        $results = $this->retrievalOptimizer->optimize(
            $results,
            $queryPlan
        );

        Log::info("Optimized Results", [
            "results" => $results
        ]);

        // ─────────────────────────────
        // 3️⃣ Recherche historique vectorielle
        // ─────────────────────────────
        /*$historyMessagesResults = $this->vectorSearchService->searchMessages(
            embedding: $questionEmbedding,
            conversationId: $conversation->id,
            limit: 3,
            scoreThreshold: 0.45, // seuil plus bas pour récupérer un contexte large
            collection: "conversations_{$conversation->id}"
        );*/
        $historyMessagesResults = [];

        // ─────────────────────────────
        // 4️⃣ Recherche Qdrant
        // ─────────────────────────────
        /*$questionEmbedding = $this->embeddingService->getEmbedding($query);
        $qdrantResults = $this->vectorSearchService->search(
            embedding: $questionEmbedding,
            siteId: $site->id,
            limit: 10,
            scoreThreshold: floatval($site->settings->min_similarity_score),
            collection: "chunks_{$site->id}"
        );*/

        // 3️⃣ Fallback si rien trouvé
        if (empty($results)) {
            UnansweredQuestion::create([
                'site_id' => $site->id,
                'question' => $question,
            ]);

            //dd(empty($qdrantResults), $qdrantResults, $site->id, floatval($site->settings->min_similarity_score));
            return "Je n’ai pas trouvé cette information dans les données de notre entreprise.
            N’hésitez pas à nous préciser votre besoin ou à nous contacter directement.";
        }

        // ─────────────────────────────
        // 5️⃣ Hydratation
        // ─────────────────────────────
        //$hydrated = $this->chunkHydrationService->hydrate($qdrantResults);
        $hydrated = $this->chunkHydrationService->hydrate($results);
        Log::info("Hydrated Chunks :", $hydrated);
        $hydratedMessages = $this->chunkHydrationService->hydrateMessages($historyMessagesResults);
        Log::info("Hydrated Messages :", $hydratedMessages);
        // ─────────────────────────────
        // 6️⃣ Ranking métier
        // ─────────────────────────────
        $ragContextChunks = $this->chunkRankingService->rank($hydrated, 5);
        $isValidContext = $this->contextValidator->validate(
            $ragContextChunks,
            $queryPlan
        );

        if (!$isValidContext) {

            Log::warning("Context rejected by validator", [
                "question" => $question
            ]);

            UnansweredQuestion::create([
                'site_id' => $site->id,
                'question' => $question,
            ]);

            return "Je ne trouve pas d'information fiable sur ce sujet dans les données disponibles.";
        }
        Log::info("RAG Context Chunks :", $ragContextChunks);
        $ragContextChunks = $this->entityResolver->resolve(collect($ragContextChunks));
        //Log::info("***RAG Context Chunks With RESOLVE: ", $ragContextChunks);
        $ragContextMessages = collect($hydratedMessages)->sortByDesc('vector_score')->take(5)->toArray();

        // Après avoir hydraté et résolu les entités
        $ragContextChunks = collect($ragContextChunks)
            ->map(fn($chunk) => [
                ...$chunk,
                'text' => $this->normalizeText($chunk['text']),
            ])->toArray();
        $ragContextMessages = collect($ragContextMessages)
            ->map(fn($msg) => [
                ...$msg,
                'text' => $this->normalizeText($msg['text']),
            ])->toArray();

        // ─────────────────────────────
        // 7️⃣ Fusion + limite globale
        // ─────────────────────────────
        $allContextChunks = collect(array_merge($ragContextChunks, $ragContextMessages))
            ->sortByDesc(fn($c) => $c['vector_score'] ?? 0)
            ->toArray();
        $maxChunks = 8; // chunks + messages
        $allContextChunks = array_slice($allContextChunks, 0, $maxChunks);

        // Construire le contexte final pour le LLM
        $context = $this->contextCompressor->compress($allContextChunks, $site, $conversation);
        Log::info("Compressed Context:", ['context' => $context]);

        if (trim($context) === '') {
            return "Je n’ai pas d’information fiable à ce sujet pour le moment.";
        }

        // ─────────────────────────────
        // 8️⃣ Construction Prompt
        // ─────────────────────────────
        Log::info("DONNES POUR PROMPT BUILDER", [
            'site' => $site->id,
            'question' => $question,
            'context' => $context,
            'history' => $history,
        ]);

        $promptPayload = $this->promptBuilder->build(
            site: $site,
            question: $query,
            context: $context,
            history: $history,
            conversation: $conversation
        );

        // ─────────────────────────────
        // 9️⃣ Appel LLM
        // ─────────────────────────────
        $response =  $this->callLLM(
            site: $site,
            prompt: $promptPayload,
            question: $question
        );

        // ─────────────────────────────
        // 🔟 Response Guard (anti-boucle)
        // ─────────────────────────────
        return $this->responseGuard->validate($response, $conversation);
    }
    /**
     * Appel LLM avec PERSONA EMPLOYÉ INTERNE
     */
    private function callLLM(Site $site, array $prompt, string $question): string
    {
        $companyName = $site->name ?? parse_url($site->url, PHP_URL_HOST);
        /**
         * @var WidgetSetting $settings
         */
        $settings = $site->settings;

        $messages = [
            ['role' => 'system', 'content' => $prompt['system']],
            ...$prompt['messages'],
        ];

        // --- DÉBUT DE LA LOGIQUE DE RETRY ---
        $maxRetries = 5;
        $delaySeconds = 1; // Délai de base pour le backoff exponentiel
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {

                Log::info("Appel à l'API LLM (tentative {$attempt})", ['site_id' => $site->id, 'question' => substr($question, 0, 100)]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json', // Bonne pratique
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'meta-llama/llama-3.1-8b-instruct',
                    'messages' => $messages,
                    'temperature' => floatval($settings->ai_temperature),
                    'max_tokens' => 350//$settings->ai_max_tokens,
                ]);

                // Vérifier si la requête HTTP a échoué (statut 4xx, 5xx)
                if (!$response->successful()) {
                    $errorMessage = "Erreur HTTP API LLM (tentative {$attempt}): " . $response->status() . " - " . $response->body();
                    Log::warning($errorMessage);
                    // Si ce n'est pas la dernière tentative, attendre avant de réessayer
                    if ($attempt < $maxRetries) {
                        $newAttempt = $attempt + 1;
                        Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                        sleep($delaySeconds);
                        $delaySeconds *= 2; // Backoff exponentiel
                        continue; // Passer à la prochaine itération de la boucle (réessayer)
                    } else {
                        // C'est la dernière tentative, sortir de la boucle pour lever l'exception ou retourner le fallback
                        break; // Sortir de la boucle pour gérer l'échec final
                    }
                }

                // La requête a réussi, vérifier la structure de la réponse
                $responseData = $response->json();

                // Vérifier si la structure attendue est présente
                if (isset($responseData['choices']) && is_array($responseData['choices']) && count($responseData['choices']) > 0) {
                    $choice = $responseData['choices'][0];
                    if (isset($choice['message']) && isset($choice['message']['content'])) {
                        $content = $choice['message']['content'];
                        Log::info("Réponse API LLM reçue (tentative {$attempt})", ['content_length' => strlen($content)]);
                        return $content;
                    } else {
                        $errorMessage = "Structure de réponse API LLM invalide (tentative {$attempt}): 'choices.0.message.content' manquant";
                        Log::warning($errorMessage, ['response_data' => $responseData]);
                    }
                } else {
                    $errorMessage = "Structure de réponse API LLM invalide (tentative {$attempt}): 'choices' manquant ou vide";
                    Log::warning($errorMessage, ['response_data' => $responseData]);
                }

                // Si on arrive ici, c'est que la réponse n'était pas correctement formatée
                // Si ce n'est pas la dernière tentative, attendre avant de réessayer
                if ($attempt < $maxRetries) {
                    $newAttempt = $attempt + 1;
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }

                /*return $response->json()['choices'][0]['message']['content']
                    ?? "N'hésitez pas à nous contacter, nous serons ravis de vous aider.";*/

            }catch (RequestException $e) {
                $errorMessage = "Erreur de requête HTTP (tentative {$attempt}): " . $e->getMessage();
                Log::warning($errorMessage);
                // Si ce n'est pas la dernière tentative
                $newAttempt = $attempt+1;
                if ($attempt < $maxRetries) {
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }
            } catch (Exception $e) { // Capture d'autres exceptions potentielles (JSON invalide, etc.)
                $errorMessage = "Erreur inattendue lors de l'appel API (tentative {$attempt}): " . $e->getMessage();
                Log::error($errorMessage, ['exception' => $e]);
                // Si ce n'est pas la dernière tentative
                if ($attempt < $maxRetries) {
                    $newAttempt = $attempt+1;
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }
            }
        }

        // --- FIN DE LA BOUCLE DE RETRY ---
        // Si on arrive ici, c'est que toutes les tentatives ont échoué
        $finalErrorMessage = "Échec de l'appel API LLM après {$maxRetries} tentatives.";
        Log::error($finalErrorMessage, [
            'site_id' => $site->id,
            'question' => substr($question, 0, 100), // Logguer une partie de la question pour le contexte
        ]);

        // RETOUR MANQUANT AJOUTÉ ICI
        return "Notre équipe chez {$companyName} reste disponible pour vous accompagner.";
        // OU Optionnellement, vous pouvez lever une exception ici si le contrôleur doit la gérer
        // throw new Exception($finalErrorMessage);

    }
    private function enrichQuestionWithHistory(string $question, Conversation $conversation): string
    {
        // Si question courte ou ambiguë
        if (str_word_count($question) <= 6) {

            $lastMessages = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'desc')
                ->take(2)
                ->get()
                ->reverse()
                ->pluck('content')
                ->implode(" ");

            if ($lastMessages) {
                return $lastMessages . " " . $question;
            }
        }

        return $question;
    }
    private function prepareQuestion(string $question, Conversation $conversation): string
    {
        $question = $this->enrichQuestionWithHistory($question, $conversation);
        $normalized = $this->normalizeText($question);
        if ($this->followUpDetector->isFollowUp($normalized, $conversation)) {
            $normalized = $this->rewriter->rewrite($normalized, $conversation);
        }
        return $this->normalizeText($normalized);
    }
    public function updateConversationSummary(Conversation $conversation): void
    {
        $oldSummary = $conversation->summary ?? '{}';

        $recentMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get()
            ->reverse()
            ->map(fn($m) => "{$m->role}: {$m->content}")
            ->implode("\n");

        $prompt = <<<PROMPT
        Tu es un moteur de mémoire conversationnelle.

        Ta tâche :
        Mettre à jour le résumé existant d'une conversation.

        Règles :
        - Garde uniquement les informations persistantes importantes
        - Préférences utilisateur
        - Contraintes
        - Objectifs
        - Décisions validées
        - Informations personnelles utiles au contexte

        Ne garde PAS :
        - Les formules de politesse
        - Les réponses marketing
        - Les détails temporaires
        - Les reformulations

        Résumé actuel :
        {$oldSummary}

        Nouveaux messages :
        {$recentMessages}

        Retourne uniquement le nouveau résumé mis à jour.
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation);

        $conversation->update([
            'summary' => $response,
            'summary_updated_at' => now()
        ]);
    }
    public function updateConversationMemory(Conversation $conversation): void
    {
        $memory = $this->extractStructuredMemory($conversation);

        if (!empty($memory)) {
            DB::table('conversation_memories')->updateOrInsert(
                ['conversation_id' => $conversation->id],
                ['memory' => json_encode($memory), 'updated_at' => now()]
            );
        }
    }
    private function callLLMForSummary(string $prompt, ?Conversation $conversation): string
    {
        $maxRetries = 5;
        $delaySeconds = 1; // base backoff
        $conversationId = $conversation?->id ?? 'unknown';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info("Appel à l'API LLM pour résumé (tentative {$attempt})", ['conversation_id' => $conversationId]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'meta-llama/llama-3.1-8b-instruct',
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 300,
                ]);

                if (!$response->successful()) {
                    $status = $response->status();
                    $body = $response->body();
                    Log::warning("Erreur HTTP API LLM (tentative {$attempt}): {$status}", ['body' => $body]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                    break;
                }

                $data = $response->json();

                if (isset($data['choices'][0]['message']['content'])) {
                    $content = $data['choices'][0]['message']['content'];

                    // Vérifier que c'est un JSON valide
                    $decoded = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        Log::info("Réponse JSON valide reçue (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    } else {
                        Log::warning("JSON invalide reçu (tentative {$attempt})", ['content' => $content]);
                        if ($attempt < $maxRetries) {
                            sleep($delaySeconds);
                            $delaySeconds *= 2;
                            continue;
                        }
                    }
                } else {
                    Log::warning("Structure de réponse API LLM invalide (tentative {$attempt})", ['response_data' => $data]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }

            } catch (\Exception $e) {
                Log::error("Erreur inattendue lors de l'appel API pour résumé (tentative {$attempt}): " . $e->getMessage());
                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }
        }

        Log::error("Échec de l'appel API LLM pour résumé après {$maxRetries} tentatives", ['conversation_id' => $conversationId]);

        // fallback JSON vide pour éviter erreur côté extraction
        return json_encode([
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ]);
    }
    public function extractStructuredMemory(Conversation $conversation): array
    {
        $summary = $conversation->summary ?? '{}';

        if (empty($summary)) {
            return [];
        }

        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée à partir d'un résumé de conversation.

        Ta tâche :
        - Transforme le résumé suivant en JSON structuré avec les clés suivantes :
            - preferences : liste des préférences exprimées par l'utilisateur
            - objectives : liste des objectifs de l'utilisateur
            - constraints : liste des contraintes exprimées
            - decisions : liste des décisions déjà prises ou validées
            - user_info : informations personnelles utiles (nom, localisation, email, etc.)

        Voici le format exact attendu (remplis avec les informations pertinentes du résumé) :
        {
            "preferences": [...],
            "objectives": [...],
            "constraints": [...],
            "decisions": [...],
            "user_info": [...]
        }

        Résumé :
        {$summary}

        ⚠️ Réponds UNIQUEMENT avec un JSON valide.
        Ne mets aucun texte avant ou après.
        Ne mets pas d'explication.
        Respecte exactement la structure et les clés.
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation);

        Log::info("Extract Structure Memory: ", [
            'response' => $response,
        ]);

        $memory = json_decode($response, true);

        return is_array($memory) ? $memory : [
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ];
    }
    public function extractStructuredMemoryFromMessage(Message $message): array
    {
        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée à partir d'un message utilisateur.

        Ta tâche :
        - Transforme le message suivant en JSON structuré avec les clés suivantes :
            - preferences
            - objectives
            - constraints
            - decisions
            - user_info

        Format exact attendu :
        {
            "preferences": [...],
            "objectives": [...],
            "constraints": [...],
            "decisions": [...],
            "user_info": [...]
        }

        Message :
        {$message->content}

        ⚠️ Réponds UNIQUEMENT avec un JSON valide.
        Ne mets aucun texte avant ou après.
        Ne mets pas d'explication.
        Respecte exactement la structure et les clés.
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $message->conversation);

        Log::info("Extract Structure Memory From Message: ", [
            'response' => $response,
        ]);

        $memory = json_decode($response, true);

        return is_array($memory) ? $memory : [
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ];
    }
}
