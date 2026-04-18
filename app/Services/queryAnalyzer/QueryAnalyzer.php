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
                "top_k" => 30,
                "search_strategy" => "single",
                "constraints" => []
            ];
        }

        return $this->mapToQueryPlan($data);
    }

    private function buildPrompt(string $question, Conversation $conversation): string
    {
        $summary = $conversation->summary ?? "";

        return <<<PROMPT
        You are an expert Query Analyzer for an enterprise-grade AI retrieval system.

        Your role is to convert a user question into a precise, optimized, and structured retrieval plan for a vector search pipeline.

        You must produce highly reliable, deterministic, and minimal-noise outputs.

        =================
        INPUT CONTEXT
        =================

        Conversation summary:
        {$summary}

        User question:
        {$question}

        =================
        OBJECTIVES
        =================

        Analyze and extract:

        1. User intent (strict classification)
        2. Key entities (typed and normalized)
        3. Optimized semantic search queries
        4. Sub-queries if multiple information needs exist
        5. Filters (ONLY if explicitly or implicitly required)
        6. Retrieval strategy (based on query complexity)
        7. Constraints (explicit or implicit limits such as budget, time, region, compliance, performance, etc.)

        =================
        OUTPUT FORMAT
        =================

        Return ONLY valid JSON. No explanations.

        {
          "clean_query": "...",

          "search_queries": [],
          "sub_queries": [],

          "entities": [
            {
              "type": "company | product | feature | plan | location | date | metric | other",
              "value": "normalized entity"
            }
          ],

          "intent": "information | pricing | comparison | navigation | transactional | support | lead | booking | download",

          "query_type": "factual | exploratory | transactional",

          "needs_conversation_context": true | false,

          "filters": {
            "date_range": null,
            "product": null,
            "plan": null,
            "language": null,
            "other": {}
          },

          "top_k": 30,

          "search_strategy": "single | multi_query | decomposition",

          "constraints": []
        }

        =================
        STRICT RULES
        =================

        - Output must be valid JSON ONLY
        - No hallucinated data
        - No explanations or comments
        - Keep queries short and embedding-optimized (5–12 words ideal)
        - Prefer keyword-rich semantic queries over full sentences
        - Avoid redundancy in search_queries
        - Max 5 search_queries
        - Max 5 sub_queries

        =================
        OVERRIDE RULE (CRITICAL)
        =================

        If intent = "comparison":
        - search_strategy MUST be "decomposition"
        - sub_queries MUST NOT be empty

        =================
        IMPLICIT CONSTRAINT DETECTION (CRITICAL)
        =================

        If the query includes:
        - sleeping position (side, back, stomach)
        - comfort preferences
        - usage context

        THEN:
        - MUST add them into "constraints"

        =================
        SUB-QUERY GENERATION RULES (MANDATORY FOR COMPARISON)
        =================

        For comparison queries, generate at least 3 sub_queries:

        1. "'product A' caractéristiques"
        2. "'product B' caractéristiques"
        3. "'constraint' recommandation produit"

        Sub_queries must NOT be empty.

        =================
        QUERY OPTIMIZATION RULES
        =================

        - Expand implicit meaning when helpful
        - Add synonyms when it improves recall
        - Remove stopwords unless necessary
        - Normalize terminology (e.g. "pricing" instead of "how much does it cost")
        - Use domain-relevant keywords

        =================
        SUB-QUERY RULES
        =================

        Use sub_queries ONLY if:
        - multiple intents exist
        - comparison is requested
        - question requires decomposition

        Otherwise return empty array.

        =================
        FILTER RULES
        =================

        Use filters ONLY when:
        - explicitly requested (e.g. "in 2024", "for startups")
        - clearly implied (e.g. "latest", "enterprise plan")

        Otherwise keep filters empty.

        =================
        CONSTRAINTS RULES
        =================

        Extract constraints ONLY when:
        - explicitly stated (e.g. "under $100", "in Europe", "GDPR compliant")
        - clearly implied (e.g. "fastest", "low latency", "high accuracy")

        =================
        SEARCH STRATEGY RULES
        =================

        - single → simple factual query
        - multi_query → when query benefits from semantic variations
        - decomposition → when multiple distinct questions or comparison

        =================
        CONTEXT USAGE
        =================

        Set needs_conversation_context = true ONLY if:
        - the query depends on previous conversation
        - contains references like "it", "that", "this", "again"

        Otherwise false.

        =================
        INTENT DEFINITIONS
        =================

        information → general informational query
        pricing → cost, plans, fees
        comparison → comparing options
        navigation → locating something
        transactional → buying or subscribing
        support → troubleshooting
        lead → request demo/contact
        booking → scheduling
        download → requesting a resource

        =================
        FAILSAFE
        =================

        If the query is vague or underspecified:
        - infer best possible clean_query
        - use multi_query strategy
        - avoid filters unless certain
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

        $plan->topK = intval($data['top_k'] ?? 30);

        $plan->searchStrategy = $data['search_strategy'] ?? 'single';

        $plan->constraints = is_array($data['constraints'] ?? null)
            ? $data['constraints']
            : [];

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
            "top_k" => 30,
            "search_strategy" => "single",
            "constraints" => []
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
