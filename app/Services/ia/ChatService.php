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
use App\Services\cta\ChatResponse;
use App\Services\cta\ContextRuleMatcher;
use App\Services\cta\CTAEngine;
use App\Services\cta\IntentRuleMatcher;
use App\Services\cta\KeywordRuleMatcher;
use App\Services\queryAnalyzer\IntentRouter;
use App\Services\queryAnalyzer\LeadService;
use App\Services\queryAnalyzer\NavigationService;
use App\Services\queryAnalyzer\QueryAnalyzer;
use App\Services\queryAnalyzer\TransactionService;
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

    protected array $handlers = [

        'lead_capture' => LeadService::class,
        'navigation' => NavigationService::class,
        'transaction_flow' => TransactionService::class,
    ];

    private array $entityLabels = [
        'product' => [
            'singular' => 'produit',
            'plural' => 'produits',
            'priority' => 1,
        ],
        'page' => [
            'singular' => 'page',
            'plural' => 'pages',
            'priority' => 2,
        ],
        'document' => [
            'singular' => 'document',
            'plural' => 'documents',
            'priority' => 3,
        ],
    ];

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
        protected ContextCompressor $contextCompressor,

        protected IntentRouter $intentRouter,
        protected CTAEngine $CTAEngine,

        protected EntityExtractor $entityExtractor,
    )
    {}

    /**
     * Réponse commerciale incarnée (mode production)
     */
    public function answer(Site $site, string $question, Conversation $conversation): ChatResponse
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
            return new ChatResponse(
                message: $earlyResponse,
                ctas: []
            );
        }

        // ─────────────────────────────
        // 1️⃣ Récupération historique court
        // ─────────────────────────────
        $history = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at',)
            //->skip(1)
            ->take(6)
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
        /*Log::info("Query Plan Prepare", [
            "original_question" => $question,
            "prepared_question" => $preparedQuestion,
            "queryPlan" => $queryPlan,
        ]);*/

        /*$route = $this->intentRouter->route($queryPlan, $site);
        if (isset($this->handlers[$route])) {

            $handler = app($this->handlers[$route]);

            return $handler->handle($question, $site, $conversation);
        }

        $topK = match($queryPlan->intent) {
            'pricing' => 5,
            'comparison' => 12,
            'information' => 8,
            default => 6
        };*/

        $query = $queryPlan->cleanQuery;
        /*Log::info("QueryPlan", [
            "clean_query" => $queryPlan->cleanQuery,
            "strategy" => $queryPlan->searchStrategy,
            "queries" => $queryPlan->searchQueries,
            "sub_queries" => $queryPlan->subQueries,
            "top_k" => $queryPlan->topK
        ]);*/
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
        /*Log::info("Queries", [
            "queries" => $queries,
        ]);*/

        // ─────────────────────────────
        // 2️⃣ Embedding
        // ─────────────────────────────
        $results = [];

        foreach ($queries as $q) {

            $embedding = $this->embeddingService->getEmbedding($q);

            $partial = $this->vectorSearchService->search(
                embedding: $embedding,
                siteId: $site->id,
                limit: $topK ?? $queryPlan->topK,
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
            return new ChatResponse(
                message: "Je n’ai pas trouvé cette information dans les données de notre entreprise.
            N’hésitez pas à nous préciser votre besoin ou à nous contacter directement.",
                ctas: []
            );
        }

        // ─────────────────────────────
        // 5️⃣ Hydratation
        // ─────────────────────────────
        //$hydrated = $this->chunkHydrationService->hydrate($qdrantResults);
        $hydrated = $this->chunkHydrationService->hydrate($results);
        Log::info("Hydrated Chunks :", $hydrated);
        $hydratedMessages = $this->chunkHydrationService->hydrateMessages($historyMessagesResults);
        //Log::info("Hydrated Messages :", $hydratedMessages);
        // ─────────────────────────────
        // 6️⃣ Ranking métier
        // ─────────────────────────────
        //$ragContextChunks = $this->diversifyChunks($hydrated, 10);
        //$ragContextChunks = $this->chunkRankingService->rank($ragContextChunks, floatval($site->settings->min_similarity_score));
        $ragContextChunks = $this->chunkRankingService->rank($hydrated, floatval($site->settings->min_similarity_score));
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

            return new ChatResponse(
                message: "Je ne trouve pas d'information fiable sur ce sujet dans les données disponibles.",
                ctas: []
            );
        }
        //Log::info("RAG Context Chunks :", $ragContextChunks);
        $ragContextChunks = $this->entityResolver->resolve(collect($ragContextChunks));
        $entities = $this->entityExtractor->extract($ragContextChunks);
        Log::info("ENTITIES RECUPERER: ", [
            "entities" => $entities
        ]);
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
            return new ChatResponse(
                message: "Je n’ai pas d’information fiable à ce sujet pour le moment.",
                ctas: []
            );
        }

        // ─────────────────────────────
        // 8️⃣ Construction Prompt
        // ─────────────────────────────
        /*Log::info("DONNES POUR PROMPT BUILDER", [
            'site' => $site->id,
            'question' => $question,
            'context' => $context,
            'history' => $history,
        ]);*/

        $promptPayload = $this->promptBuilder->build(
            site: $site,
            question: $query,
            context: $context,
            history: $history,
            conversation: $conversation
        );

        //Log::info("Prompt Payload:", $promptPayload);

        // ─────────────────────────────
        // 8.5️⃣ Résolution CTA
        // ─────────────────────────────
        $ctas = $this->CTAEngine->resolve($site, $queryPlan, $conversation);
        Log::info("Resolved CTAs:", ['ctas' => $ctas]);


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
        $validatedResponse = $this->responseGuard->validate($response, $conversation);

        Log::info("REPONSE AVEC CTA OU PAS et ENTITIES OU PAS", [
            "content" => $validatedResponse,
            "ctas" => $ctas,
            "entities" => $entities,
        ]);

        // Si réponse vide / non disponible mais entities présentes
        if (
            $validatedResponse ===  "Cette information n’est pas disponible dans nos documents internes."
            &&
            !empty($entities)
        ) {
            // Ajoute un texte introductif pour contextualiser les entities
            $fallbackMessage = $this->buildEntitiesFallbackMessage($entities);

            if ($fallbackMessage) {
                $validatedResponse = $fallbackMessage;
            }
        } elseif (!empty($entities)) {

            $validatedResponse .= "\n\n---\n\n💡 **Ressources utiles :**";
        }

        // Retour final avec CTAs
        return new ChatResponse(
            message: $validatedResponse,
            ctas: $ctas,
            entities: $entities
        );
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
        Tu es un moteur de mémoire conversationnelle utilisé dans un chatbot SaaS multi-domaines
        (e-commerce, support client, blog, SaaS, etc.).

        Ton rôle est de maintenir un résumé court et utile d'une conversation.

        OBJECTIF
        Mettre à jour le résumé existant avec les nouvelles informations pertinentes.

        RÈGLES IMPORTANTES

        - Conserve uniquement les informations durables et utiles au contexte.
        - N'invente jamais d'information.
        - N'interprète pas les intentions non exprimées.
        - Supprime les informations temporaires ou inutiles.
        - Fusionne les informations similaires pour éviter les répétitions.
        - Si une nouvelle information contredit une ancienne, garde la plus récente.

        INFORMATIONS À CONSERVER

        - préférences utilisateur
        - objectifs utilisateur
        - contraintes
        - décisions confirmées
        - informations personnelles utiles

        INFORMATIONS À IGNORER

        - salutations
        - formules de politesse
        - small talk
        - réponses marketing
        - détails temporaires

        FORMAT DU RÉSUMÉ

        - phrases courtes
        - style neutre
        - maximum 12 lignes
        - une information par ligne

        RÉSUMÉ ACTUEL
        {$oldSummary}

        NOUVEAUX MESSAGES
        {$recentMessages}

        INSTRUCTION FINALE

        Génère le nouveau résumé mis à jour en respectant les règles ci-dessus.

        Retourne uniquement le résumé.
        Aucun texte explicatif.
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation, false);

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
    private function callLLMForSummary(string $prompt, ?Conversation $conversation, bool $return_json = true): string
    {
        $maxRetries = 5;
        $delaySeconds = 1; // base backoff
        $conversationId = $conversation?->id ?? 'unknown';

        $fallback = $return_json
            ? json_encode(['preferences'=>[],'objectives'=>[],'constraints'=>[],'decisions'=>[],'user_info'=>[]])
            : ($conversation?->summary ?? 'Résumé indisponible');

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            try {

                Log::info("Appel à l'API LLM pour résumé (tentative {$attempt})", ['conversation_id' => $conversationId]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->timeout(30)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => 'meta-llama/llama-3.1-8b-instruct',
                        'messages' => [
                            ['role' => 'system', 'content' => $prompt]
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 300,
                    ]);

                if (!$response->successful()) {
                    Log::warning("Erreur HTTP API LLM (tentative {$attempt}): {$response->status()}", [
                        'body' => $response->body(),
                        'conversation_id' => $conversationId
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                    break;
                }

                $data = $response->json();
                $content = data_get($data, 'choices.0.message.content', null);

                if (empty($content)) {
                    Log::warning("Réponse vide ou malformée (tentative {$attempt})", [
                        'response_data' => $data,
                        'conversation_id' => $conversationId
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }else {
                    $content = trim($content);

                    if (!$return_json) {
                        // nettoyage markdown ```json ou ``` si string brut
                        $content = preg_replace('/^```[a-z]*|```$/mi', '', $content);
                        $content = trim($content);
                        Log::info("Résumé LLM reçu (string) avec succès (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    }

                    // Cas JSON attendu
                    $decoded = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        Log::info("Résumé LLM reçu (JSON) avec succès (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    }

                    Log::warning("JSON invalide reçu (tentative {$attempt})", ['content' => $content]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }

            } catch (Exception $e) {
                Log::error("Exception lors de l'appel API LLM (tentative {$attempt}): " . $e->getMessage(), ['conversation_id' => $conversationId]);
                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }

        }

        Log::error("Échec de l'appel API LLM après {$maxRetries} tentatives, fallback utilisé", ['conversation_id' => $conversationId]);
        return $fallback;

    }
    public function extractStructuredMemory(Conversation $conversation): array
    {
        $summary = $conversation->summary ?? '';

        if ($summary === '' || $summary === 'Résumé indisponible') {
            return [];
        }

        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée utilisé dans un chatbot SaaS multi-domaines
        (blog, e-commerce, support client, SaaS, etc.).

        Ton rôle :
        Transformer un résumé de conversation en JSON structuré exploitable par un agent conversationnel.

        Règles importantes :

        - Extrais uniquement les informations explicitement présentes dans le résumé.
        - Tu peux reformuler légèrement pour rendre l'information exploitable.
        - N'invente jamais d'information absente.
        - Fusionne les informations identiques pour éviter les doublons.
        - Si une information contredit une autre plus ancienne, garde la plus récente.
        - Les éléments doivent être courts (2 à 8 mots).
        - Maximum 15 éléments par catégorie.
        - Si aucune information pertinente n'est trouvée pour une catégorie, retourne un tableau vide.

        Catégories et exemples :

        preferences : produits, services, choix ou intérêts exprimés.
        Exemples :
        "stylo noir"
        "ordinateur Apple"
        "coffret cadeau"

        objectives : ce que l'utilisateur souhaite faire ou obtenir.
        Exemples :
        "acheter un stylo"
        "contacter le support"
        "obtenir des informations"

        constraints : conditions ou limitations exprimées.
        Exemples :
        "budget limité"
        "livraison rapide"
        "couleur noire"

        decisions : décisions déjà prises ou validées.
        Exemples :
        "choisir offre premium"
        "prendre ce modèle"

        user_info : informations personnelles explicitement mentionnées.
        Exemples :
        "vit à Paris"
        "photographe"
        "email exemple@mail.com"

        Format STRICT :

        {
          "preferences": [],
          "objectives": [],
          "constraints": [],
          "decisions": [],
          "user_info": []
        }

        Résumé à traiter :
        {$summary}

        ⚠️ Réponds uniquement avec un JSON valide.
        Aucun texte avant ou après.

        Exemple 1 :

        Résumé : "l'utilisateur préfère les stylos bleus, souhaite un coffret cadeau pour un ami, a un budget limité, veut contacter le support par email"

        Réponse :
        {
          "preferences": ["stylos bleus", "coffret cadeau"],
          "objectives": ["contacter le support"],
          "constraints": ["budget limité"],
          "decisions": [],
          "user_info": []
        }

        Exemple 2 :

        Résumé : "l'utilisateur veut un ordinateur Apple pas cher"

        Réponse :
        {
          "preferences": ["ordinateur Apple"],
          "objectives": ["acheter un ordinateur"],
          "constraints": ["prix bas"],
          "decisions": [],
          "user_info": []
        }
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation, true);

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
        Tu es un moteur d'extraction de mémoire structurée utilisé dans un chatbot SaaS multi-domaines
        (blog, e-commerce, support client, SaaS, etc.).

        Ton rôle est d'extraire les informations utiles contenues dans le message utilisateur.

        Règles importantes :

        - Extrais uniquement les informations présentes dans le message.
        - Tu peux reformuler légèrement pour rendre l'information exploitable.
        - N'invente jamais d'information absente.
        - Si aucune information pertinente n'est trouvée, retourne des tableaux vides.
        - Les éléments doivent être courts (2 à 8 mots).

        Catégories :

        preferences
        Produits, services, choix ou intérêts exprimés.

        Exemples :
        "stylo noir"
        "ordinateur Apple"
        "coffrets cadeaux"

        objectives
        Ce que l'utilisateur souhaite faire ou obtenir.

        Exemples :
        "acheter un stylo"
        "contacter l'entreprise"
        "obtenir des informations"

        constraints
        Conditions ou limitations.

        Exemples :
        "budget limité"
        "livraison rapide"
        "couleur noir"

        decisions
        Décisions déjà prises.

        Exemples :
        "choisir offre premium"
        "prendre ce modèle"

        user_info
        Informations personnelles explicitement mentionnées.

        Exemples :
        "vit à Paris"
        "photographe"
        "travaille en freelance"

        Format STRICT :

        {
          "preferences": [],
          "objectives": [],
          "constraints": [],
          "decisions": [],
          "user_info": []
        }

        Message utilisateur :
        {$message->content}

        Réponds uniquement avec un JSON valide.
        Aucun texte avant ou après.

        Exemple 1 :

        Message : "Je veux un ordinateur Apple pas cher"

        Réponse :
        {
         "preferences": ["ordinateur Apple"],
         "objectives": ["acheter un ordinateur"],
         "constraints": ["prix bas"],
         "decisions": [],
         "user_info": []
        }

        Exemple 2 :

        Message : "Je suis intéressé par vos coffrets"

        Réponse :
        {
         "preferences": ["coffrets"],
         "objectives": ["obtenir des informations"],
         "constraints": [],
         "decisions": [],
         "user_info": []
        }
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $message->conversation, true);

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

    private function diversifyChunks(array $chunks, int $limit = 5): array
    {
        $selected = [];

        foreach ($chunks as $chunk) {

            $tooSimilar = false;

            foreach ($selected as $s) {

                similar_text(
                    strtolower($chunk['text']),
                    strtolower($s['text']),
                    $percent
                );

                if ($percent > 70) {
                    $tooSimilar = true;
                    break;
                }
            }

            if (!$tooSimilar) {
                $selected[] = $chunk;
            }

            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function buildEntitiesFallbackMessage(array $entities): ?string
    {
        if (empty($entities)) {
            return null;
        }

        // 🔢 Compter les types
        $counts = collect($entities)
            ->groupBy('type')
            ->map(fn($items) => count($items));

        // 🎯 Construire les labels avec count
        $parts = collect($counts)
            ->filter(fn($count, $type) => isset($this->entityLabels[$type]))
            ->sortBy(fn($count, $type) => $this->entityLabels[$type]['priority'])
            ->map(function ($count, $type) {

                $config = $this->entityLabels[$type];

                $label = $count === 1
                    ? $config['singular']
                    : $config['plural'];

                return "{$count} {$label}";
            })
            ->values()
            ->toArray();

        if (empty($parts)) {
            return null;
        }

        // 🧠 Phrase naturelle
        if (count($parts) === 1) {
            $list = $parts[0];
        } elseif (count($parts) === 2) {
            $list = implode(' et ', $parts);
        } else {
            $last = array_pop($parts);
            $list = implode(', ', $parts) . ' et ' . $last;
        }

        // ✨ Markdown propre
        return "Nous n’avons pas cette information exacte.\n\n---\n\n💡 **Voici {$list} qui pourraient vous être utiles :**";
    }
}
