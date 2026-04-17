<?php

namespace App\Services\queryAnalyzer;

use App\Models\Site;
use App\Services\chunks\ChunkHydrationService;
use App\Services\hybrid\HybridSearchService;
use App\Services\ia\EmbeddingService;
use App\Services\rag\LLMReRankerService;
use App\Services\rag\RetrievalOptimizer;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MultiHopPipelineService
{
    protected int $maxRetries = 3;
    protected QueryPlan $queryPlan;
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected HybridSearchService $hybridSearchService,
        protected ChunkHydrationService $chunkHydrationService,
        protected LLMReRankerService $LLMReRankerService,
        protected RetrievalOptimizer $retrievalOptimizer,
    ){}
    public function handle(
        string $question,
        QueryPlan $queryPlan,
        Site $site
    ): array {

        $state = $this->initState();
        $allResults = [];

        $currentQuery = $this->queryPlan->cleanQuery;

        for ($i = 0; $i < 3; $i++) {

            $results = $this->executeHop(
                query: $currentQuery,
                site: $site,
                state: $state,
                queryPlan: $queryPlan,
            );

            if (empty($results)) {
                break;
            }

            $allResults[] = $results;

            $state = $this->updateState($state, $results);

            if ($this->shouldStop($state, $results)) {
                break;
            }

            $currentQuery = $this->generateNextQuery($question, $state);

            Log::info("Multi-hop next query", [
                'hop' => $i,
                'query' => $currentQuery
            ]);
        }

        return $this->mergeResults($allResults);
    }
    protected function initState(): array
    {
        return [
            'visited_ids' => [],
            'entities' => [],
            'keywords' => [],
            'context_fragments' => [],
            'hop' => 0,
        ];
    }

    protected function updateState(array $state, array $results): array
    {
        foreach ($results as $chunk) {

            $state['visited_ids'] = array_unique(array_merge(
                $state['visited_ids'],
                [$chunk['id']]
            ));

            // 🔥 entities (si déjà extraites)
            if (!empty($chunk['entities'] ?? null)) {
                $state['entities'] = array_unique(array_merge(
                    $state['entities'],
                    $chunk['entities']
                ));
            }

            // 🔥 keywords depuis texte
            if (!empty($chunk['text'])) {
                $tokens = $this->extractKeywords($chunk['text']);
                $state['keywords'] = array_unique(array_merge($state['keywords'], $tokens));
            }

            // 🔥 garder morceaux utiles
            $state['context_fragments'][] = substr($chunk['text'] ?? '', 0, 200);
        }

        $state['hop']++;

        return $state;
    }
    protected function extractKeywords(string $text): array
    {
        $text = strtolower($text);

        $tokens = preg_split('/[\s,.;:!?()]+/', $text);

        return array_values(array_filter($tokens, fn($t) => strlen($t) > 4));
    }
    protected function buildContextFromState(array $state): string
    {
        $fragments = array_slice($state['context_fragments'], 0, 5);

        return implode("\n", $fragments);
    }
    protected function executeHop(string $query, Site $site, array $state, QueryPlan $queryPlan): array
    {
        $embedding = $this->embeddingService->getEmbedding($query);

        $results = $this->hybridSearchService->search(
            query: $query,
            embedding: $embedding,
            siteId: $site->id,
            limit: 30,
            scoreThreshold: floatval($site->settings->min_similarity_score),
        );

        $resultsMap = [];

        foreach ($results as $item) {

            $id = $item['id'];

            if (!isset($resultsMap[$id])) {
                $resultsMap[$id] = $item;

                $resultsMap[$id]['hit_count'] = 0;
                $resultsMap[$id]['multi_query_score'] = 0;
            }

            // 🔥 count occurrences
            $resultsMap[$id]['hit_count']++;

            // 🔥 léger bonus par présence multi-query
            $resultsMap[$id]['multi_query_score'] += 1;
        }

        $resultsHybridSearch = collect($resultsMap)
            ->map(function ($item) {

                $hits = $item['hit_count'] ?? 1;

                $item['multi_query_bonus'] = min(0.15, log(1 + $hits) * 0.05);

                // 🔥 UPDATE GLOBAL SCORE
                $item['score'] = ($item['rrf_score'] ?? 0) + $item['multi_query_bonus'];

                return $item;
            })
            ->sortByDesc('score')
            ->values()
            ->toArray();

        $hydrated = $this->chunkHydrationService->hydrate($resultsHybridSearch);

        return $hydrated;
    }
    protected function generateNextQuery(string $question, array $state): string
    {
        $context = $this->buildContextFromState($state);

        $prompt = [
            "role" => "system",
            "content" => "Tu es un moteur de recherche avancé. Génère UNE requête de recherche complémentaire, plus précise, différente de la question initiale."
        ];

        $userPrompt = [
            "role" => "user",
            "content" => "
Question initiale:
{$question}

Contexte déjà trouvé:
{$context}

Mots-clés importants:
" . implode(', ', array_slice($state['keywords'], 0, 10)) . "

Entités:
" . implode(', ', array_slice($state['entities'], 0, 10)) . "

Objectif:
Trouver une information complémentaire NON couverte.

Contraintes:
- Pas de répétition
- Plus spécifique ou angle différent
- Ajouter précision métier si possible
- Format: une seule phrase courte
"
        ];
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'openai/gpt-4o-mini',
                    'messages' => [$prompt, $userPrompt],
                    'temperature' => 0.3,
                    'max_tokens' => 50,
                ]);

                if ($response->successful()) {
                    return trim($response->json()['choices'][0]['message']['content'] ?? '');
                }

            } catch (Exception $e) {
                Log::warning("NextQuery generation failed", ['error' => $e->getMessage()]);
            }
            sleep(pow(2, $attempt)); // backoff
        }

        // 🔥 fallback intelligent
        return $question . " details";
    }
    protected function mergeResults(array $hops): array
    {
        return collect($hops)
            ->flatten(1) // 🔥 supprime le niveau hop
            ->groupBy('id')
            ->map(function ($items) {

                // prend le meilleur chunk
                $best = collect($items)
                    ->sortByDesc('score')
                    ->first();

                return [
                    'id' => $best['id'],

                    // 🔥 SIGNALS
                    'score' => $best['score'] ?? 0,
                    'rrf_score' => $best['rrf_score'] ?? 0,
                    'vector_score' => $best['vector_score'] ?? 0,
                    'keyword_score' => $best['keyword_score'] ?? 0,
                    'multi_query_bonus' => $best['multi_query_bonus'] ?? 0,

                    // meta
                    'text' => $best['text'] ?? '',
                    'priority' => $best['priority'] ?? 100,
                    'source_type' => $best['source_type'] ?? 'unknown',
                    'metadata' => $best['metadata'] ?? null,
                    'payload' => $best['payload'] ?? null,
                    'source' => $best['source'] ?? null,
                    'length' => $best['length'] ?? null,
                    'embedding' => $best['embedding'] ?? null,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->toArray();
    }
    protected function shouldStop(array $state, array $lastResults): bool
    {
        if ($state['hop'] >= 3) {
            return true;
        }

        $lastIds = collect($lastResults)->pluck('id');

        $newIds = $lastIds->filter(fn($id) => !in_array($id, $state['visited_ids']));

        if ($newIds->count() < 3) {
            return true;
        }

        return false;
    }
    public function shouldUseMultiHop(QueryPlan $queryPlan): bool
    {
        $this->queryPlan = $queryPlan;
        // 1️⃣ Heuristique rapide
        $heuristic = $this->heuristicDecision($queryPlan);

        if ($heuristic !== null) {
            return $heuristic;
        }

        // 2️⃣ LLM fallback (cas ambigus)
        return $this->llmDecision($queryPlan);
    }
    protected function heuristicDecision(): ?bool
    {
        $intent = $this->queryPlan->intent;

        // 🔥 sûr → multi-hop
        if ($intent === 'comparison') {
            return true;
        }

        // 🔥 sûr → pas multi-hop
        if (in_array($intent, ['pricing', 'navigation', 'lead', 'booking', 'download'])) {
            return false;
        }

        // 🔥 complexité évidente
        if (!empty($this->queryPlan->subQueries) && count($this->queryPlan->subQueries) > 1) {
            return true;
        }

        // 🔥 stratégie déjà détectée
        if ($this->queryPlan->searchStrategy === 'decomposition') {
            return true;
        }

        // ❓ incertain → laisser LLM décider
        return null;
    }
    protected function llmDecision(): bool
    {
        $prompt = [
            [
                "role" => "system",
                "content" => "Tu es un classificateur. Réponds uniquement par YES ou NO."
            ],
            [
                "role" => "user",
                "content" => "
Question: {$this->queryPlan->cleanQuery}

Doit-on utiliser une recherche multi-hop (plusieurs étapes de recherche) ?

Réponds uniquement:
YES ou NO
"
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            ])->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'openai/gpt-4o-mini',
                'messages' => $prompt,
                'temperature' => 0,
                'max_tokens' => 5,
            ]);

            if ($response->successful()) {
                $answer = strtoupper(trim($response->json()['choices'][0]['message']['content'] ?? ''));

                return str_contains($answer, 'YES');
            }

        } catch (\Exception $e) {
            Log::warning("LLM decision failed", ['error' => $e->getMessage()]);
        }

        // 🔥 fallback sécurisé
        return false;
    }
}
