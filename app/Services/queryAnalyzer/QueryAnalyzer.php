<?php

namespace App\Services\queryAnalyzer;

use App\Models\Conversation;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueryAnalyzer
{
    public function __construct(

    ) {}

    public function analyze(string $question, Conversation $conversation): QueryPlan
    {
        $prompt = $this->buildPrompt($question, $conversation);

        $response = $this->callLLMForQueryPlan($prompt, $question);

        //$data = json_decode($response, true);

        $data = $this->extractJson($response);

        if (!is_array($data)) {

            Log::warning("QueryAnalyzer JSON invalid", [
                "response" => $response
            ]);

            $data = [
                "clean_query" => $question,
                "search_queries" => [$question],
                "sub_queries" => [],
                "entities" => [],
                "intent" => "information",
                "query_type" => "factual",
                "needs_conversation_context" => false,
                "filters" => [],
                "top_k" => 8,
                "search_strategy" => "single"
            ];
        }

        return $this->mapToQueryPlan($data);
    }

    private function buildPrompt(string $question, Conversation $conversation): string
    {
        $summary = $conversation->summary ?? "";

        return <<<PROMPT
        You are a Query Analyzer for an enterprise AI search engine.

        Your job is to transform a user question into a structured search plan
        that will be used for vector retrieval.

        You must analyze:

        - intent
        - entities
        - search queries
        - sub-queries if the question contains multiple information needs
        - filters if needed
        - retrieval strategy

        Conversation summary:
        {$summary}

        User question:
        {$question}

        Return ONLY valid JSON without any explanation, notes, or markdown code blocks.
        Do NOT include text before or after the JSON. The response must start with { and end with }.

        JSON schema:

        {
         "clean_query": "normalized search query",

         "search_queries": [
          "query1",
          "query2"
         ],

         "sub_queries": [
          "query1",
          "query2"
         ],

         "entities": [],

         "intent": "information | pricing | support | navigation | comparison",

         "query_type": "factual | exploratory | transactional",

         "needs_conversation_context": true | false,

         "filters": {},

         "top_k": 8,

         "search_strategy": "single | multi_query | decomposition"
        }

        Rules:

        - search_queries should improve semantic retrieval
        - use multiple queries if useful
        - decompose complex questions
        - extract entities
        - never hallucinate information
        - keep queries concise
        PROMPT;
    }

    private function mapToQueryPlan(array $data): QueryPlan
    {
        $plan = new QueryPlan();

        $plan->cleanQuery = $data['clean_query'] ?? '';

        $plan->searchQueries = is_array($data['search_queries'] ?? null)
            ? $data['search_queries']
            : [];

        $plan->subQueries = is_array($data['sub_queries'] ?? null)
            ? $data['sub_queries']
            : [];

        $plan->entities = is_array($data['entities'] ?? null)
            ? $data['entities']
            : [];

        $plan->intent = $data['intent'] ?? 'information';

        $plan->queryType = $data['query_type'] ?? 'factual';

        $plan->needsConversationContext = $data['needs_conversation_context'] ?? false;

        $plan->filters = is_array($data['filters'] ?? null)
            ? $data['filters']
            : [];

        $plan->topK = intval($data['top_k'] ?? 8);

        $plan->searchStrategy = $data['search_strategy'] ?? 'single';

        return $plan;
    }

    private function callLLMForQueryPlan(string $prompt, string $question): string
    {
        $maxRetries = 4;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            try {

                Log::info("QueryAnalyzer LLM call (attempt {$attempt})", [
                    "question" => substr($question, 0, 120)
                ]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->post('https://openrouter.ai/api/v1/chat/completions', [

                    "model" => "meta-llama/llama-3.1-8b-instruct",

                    "messages" => [
                        [
                            "role" => "system",
                            "content" => "You are a query planning engine for a semantic search system.
                            Return ONLY valid JSON."
                        ],
                        [
                            "role" => "user",
                            "content" => $prompt
                        ]
                    ],

                    "temperature" => 0.2,
                    "max_tokens" => 400
                ]);

                if (!$response->successful()) {

                    Log::warning("QueryAnalyzer HTTP error", [
                        "status" => $response->status(),
                        "body" => $response->body()
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }

                    break;
                }

                $data = $response->json();

                if (
                    isset($data['choices'][0]['message']['content'])
                ) {

                    $content = $data['choices'][0]['message']['content'];

                    Log::info("QueryAnalyzer response received", [
                        "length" => strlen($content)
                    ]);

                    return $content;
                }

            } catch (Exception $e) {

                Log::warning("QueryAnalyzer exception", [
                    "error" => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }
        }

        Log::error("QueryAnalyzer failed after retries");

        return json_encode([
            "clean_query" => $question,
            "search_queries" => [$question],
            "sub_queries" => [],
            "entities" => [],
            "intent" => "information",
            "query_type" => "factual",
            "needs_conversation_context" => false,
            "filters" => [],
            "top_k" => 8,
            "search_strategy" => "single"
        ]);
    }

    private function extractJson(string $response): ?array
    {
        // Cherche la première accolade ouvrante et la dernière fermante
        $start = strpos($response, '{');
        $end = strrpos($response, '}');

        if ($start === false || $end === false) {
            return null;
        }

        $json = substr($response, $start, $end - $start + 1);

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
