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
use App\Services\SimilarityService;
use App\Services\vector\VectorSearchService;
use App\Traits\TextNormalizer;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatService
{

    use TextNormalizer;

    public function __construct(
        protected EmbeddingService $embeddingService,
        protected SimilarityService $similarityService,
        protected PromptBuilder $promptBuilder,
        protected VectorSearchService $vectorSearchService,
        protected ChunkHydrationService $chunkHydrationService,
        protected ChunkRankingService $chunkRankingService,
        protected ContextBuilder $contextBuilder,
        protected ProductEntityResolver $productEntityResolver,
        protected FollowUpDetector $followUpDetector,
        protected ConversationRewriterService $rewriter,
        protected EntityResolver $entityResolver,
        protected IntentClassifier $intentClassifier,
        protected ConversationStateManager $conversationStateManager,
        protected ResponseGuard $responseGuard,
    )
    {}

    /**
     * Traite la question et retourne la réponse factuelle
     */
    public function ask(string $question, Site $site, ?int $topK = 5, float $similarityThreshold = 0.45)
    {
        // 1. Générer embedding de la question
        $queryEmbedding = $this->embeddingService->getEmbedding($question);

        // 2. Récupérer les chunks du site
        $chunks = Chunk::whereHas('page', fn($q) => $q->where('site_id', $site->id))->get();

        // 3. Calculer similarité cosine (PHP)
        $chunksWithScore = $chunks->map(fn($chunk) => [
            'chunk' => $chunk,
            'score' => $this->similarityService->cosine(
                $queryEmbedding,
                $chunk->embedding
            )
        ]);

        // 4. Filtrer par score minimum
        $filtered = $chunksWithScore->filter(fn($c) => $c['score'] >= $similarityThreshold);

        // 5. Top K
        $topChunks = $filtered->sortByDesc('score')->take($topK)->pluck('chunk')->toArray();

        // 6. Construire prompt strict pour LLM
        $context = implode("\n", array_map(fn($c) => $c->text, $topChunks));

        // 7. Appel LLM (ici placeholder pour MVP)
        $answer = $this->callLLM($question, $context);

        return $answer;
    }

    /**
     * Réponse commerciale incarnée (mode production)
     */
    public function answer(Site $site, string $question, Conversation $conversation): string
    {

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
            ->orderBy('created_at', 'desc')
            ->skip(1)
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

        $query = $this->prepareQuestion($question, $conversation);

        // ─────────────────────────────
        // 2️⃣ Embedding
        // ─────────────────────────────
        $questionEmbedding = $this->embeddingService->getEmbedding($query);

        // ─────────────────────────────
        // 3️⃣ Recherche historique vectorielle
        // ─────────────────────────────
        $historyMessagesResults = $this->vectorSearchService->searchMessages(
            embedding: $questionEmbedding,
            conversationId: $conversation->id,
            limit: 3,
            scoreThreshold: 0.45 // seuil plus bas pour récupérer un contexte large
        );

        // ─────────────────────────────
        // 4️⃣ Recherche Qdrant
        // ─────────────────────────────
        $qdrantResults = $this->vectorSearchService->search(
            embedding: $questionEmbedding,
            siteId: $site->id,
            limit: 10,
            scoreThreshold: floatval($site->settings->min_similarity_score)
        );

        // 3️⃣ Fallback si rien trouvé
        if (empty($qdrantResults)) {
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
        $hydrated = $this->chunkHydrationService->hydrate($qdrantResults);
        $hydratedMessages = $this->chunkHydrationService->hydrateMessages($historyMessagesResults);

        // ─────────────────────────────
        // 6️⃣ Ranking métier
        // ─────────────────────────────
        $ragContextChunks = $this->chunkRankingService->rank($hydrated, 5);
        $ragContextChunks = $this->entityResolver->resolve(collect($ragContextChunks));
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
        $context = $this->contextBuilder->build($allContextChunks);

        if (trim($context) === '') {
            return "Je n’ai pas d’information fiable à ce sujet pour le moment.";
        }

        // ─────────────────────────────
        // 8️⃣ Construction Prompt
        // ─────────────────────────────
        $promptPayload = $this->promptBuilder->build(
            site: $site,
            question: $query,
            context: $context,
            history: $history
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

}
