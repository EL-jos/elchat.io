<?php

namespace App\Services\rag;

use App\Services\queryAnalyzer\QueryPlan;

class RetrievalOptimizer
{
    public function optimize(array $results, QueryPlan $queryPlan): array
    {
        if (empty($results)) {
            return $results;
        }

        $tokens = $this->tokenize($queryPlan->cleanQuery);
        $entities = $queryPlan->entities ?? [];

        foreach ($results as &$chunk) {

            $text = strtolower($chunk['text'] ?? '');
            $score = $chunk['score'] ?? 0;

            $boost = 0;

            // 1️⃣ Boost entités
            foreach ($entities as $entity) {

                if (is_array($entity)) {
                    $entity = implode(' ', $entity);
                }

                if (!is_string($entity) || $entity === '') {
                    continue;
                }

                if (stripos($text, $entity) !== false) {
                    $boost += 0.15;
                }
            }

            // 2️⃣ Boost tokens query
            foreach ($tokens as $token) {
                if (strlen($token) < 3) {
                    continue;
                }

                if (stripos($text, $token) !== false) {
                    $boost += 0.03;
                }
            }

            // 3️⃣ Boost nombres / IDs
            foreach ($tokens as $token) {
                if (is_numeric($token) && stripos($text, $token) !== false) {
                    $boost += 0.20;
                }
            }

            // 4️⃣ Exact query match
            if (stripos($text, strtolower($queryPlan->cleanQuery)) !== false) {
                $boost += 0.25;
            }

            $chunk['score'] = $score + $boost;
        }

        // resort
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    private function tokenize(string $query): array
    {
        $query = strtolower($query);

        $tokens = preg_split('/[\s,.;:!?()]+/', $query);

        return array_filter($tokens);
    }
}
