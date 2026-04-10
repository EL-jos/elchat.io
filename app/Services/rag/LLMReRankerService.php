<?php

namespace App\Services\rag;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LLMReRankerService
{
    protected int $maxRetries = 5;
    protected int $delaySeconds = 1;

    public function rerank(string $query, array $chunks, int $topK = 10): array
    {
        if (empty($chunks)) {
            return [];
        }

        // 🔥 Limite pour coût/perf
        $chunks = array_slice($chunks, 0, 20);

        $documents = array_map(function ($chunk) {
            return $chunk['text'] ?? $chunk['payload']['text'] ?? '';
        }, $chunks);

        $scores = $this->callRerankAPI($query, $documents);

        if (empty($scores)) {
            // 🔥 fallback → retourne ranking initial
            return array_slice($chunks, 0, $topK);
        }

        // 🔥 Merge scores
        $reranked = collect($chunks)
            ->map(function ($chunk, $index) use ($scores, $query) {

                $llmScore = $scores[$index] ?? 0;
                $rrfScore = $chunk['score'] ?? 0;

                $chunk['llm_score'] = $llmScore;

                // 🔥 SCORE FINAL (le cœur du système)
                [$rrfWeight, $llmWeight] = $this->getFusionWeights($query);

                $chunk['final_score'] =
                    ($rrfWeight * $rrfScore) +
                    ($llmWeight * $llmScore);

                return $chunk;
            })
            ->sortByDesc('final_score') // 🔥 TRÈS IMPORTANT
            ->take($topK)
            ->values()
            ->toArray();

        return $reranked;
    }
    protected function callRerankAPI(string $query, array $documents): array
    {
        $delay = $this->delaySeconds;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {

                Log::info("Rerank API call (attempt {$attempt})");

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->post('https://openrouter.ai/api/v1/rerank', [
                    'model' => 'cohere/rerank-v3.5',
                    'query' => $query,
                    'documents' => $documents,
                ]);

                if (!$response->successful()) {

                    Log::warning("Rerank HTTP error {$response->status()}", [
                        'body' => $response->body()
                    ]);

                    if ($attempt < $this->maxRetries) {
                        sleep($delay);
                        $delay *= 2;
                        continue;
                    }

                    break;
                }

                $data = $response->json();

                if (!isset($data['results'])) {
                    Log::warning("Rerank invalid response structure", $data);
                    continue;
                }

                /**
                 * OpenRouter / Cohere format:
                 * results = [
                 *   { index: 0, relevance_score: 0.98 },
                 *   ...
                 * ]
                 */

                $scores = array_fill(0, count($documents), 0);

                foreach ($data['results'] as $item) {
                    $scores[$item['index']] = $item['relevance_score'] ?? 0;
                }

                return $scores;

            } catch (\Exception $e) {

                Log::error("Rerank exception (attempt {$attempt})", [
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $this->maxRetries) {
                    sleep($delay);
                    $delay *= 2;
                    continue;
                }
            }
        }

        Log::error("Rerank failed after {$this->maxRetries} attempts");

        return [];
    }
    protected function getFusionWeights(string $query): array
    {
        if ($this->isExactQuery($query)) {
            return [0.5, 0.5]; // keyword + LLM important
        }

        return [0.6, 0.4]; // RRF dominant
    }
    protected function isExactQuery(string $query): bool
    {
        return preg_match('/\b[A-Z0-9\-]{4,}\b/', $query)
            && strlen($query) < 40;
    }
}
