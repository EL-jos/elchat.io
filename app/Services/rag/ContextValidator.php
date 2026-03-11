<?php

namespace App\Services\rag;

use App\Services\queryAnalyzer\QueryPlan;

class ContextValidator
{
    public function validate(
        array $chunks,
        QueryPlan $queryPlan
    ): bool {

        if (empty($chunks)) {
            return false;
        }

        $queryTokens = $this->tokenize($queryPlan->cleanQuery);
        $entities = $this->normalizeEntities($queryPlan->entities ?? []);

        // Si pas d'entité, on ajoute la query entière
        if (empty($entities)) {
            $entities[] = $queryPlan->cleanQuery;
        }

        $relevantChunks = 0;

        foreach ($chunks as $chunk) {

            $text = strtolower($chunk['text'] ?? '');
            $tokenMatches = 0;
            
            foreach ($queryTokens as $token) {

               /* if (strlen($token) < 3) {
                    continue;
                }*/

                if (stripos($text, $token) !== false) {
                    $tokenMatches++;
                }
            }

            $entityMatch = false;
            foreach ($entities as $entity) {
                if (stripos($text, $entity) !== false) {
                    $entityMatch = true;
                    break;
                }
            }

            if ($tokenMatches >= 1 /*2*/ || $entityMatch) {
                $relevantChunks++;
            }
        }

        return $relevantChunks > 0;
    }

    private function tokenize(string $query): array
    {
        $query = strtolower($query);

        return array_filter(
            preg_split('/[\s,.;:!?()]+/', $query)
        );
    }

    private function normalizeEntities(array $entities): array
    {
        $normalized = [];

        foreach ($entities as $entity) {

            if (is_string($entity)) {
                $normalized[] = $entity;
            }

            if (is_array($entity)) {
                foreach ($entity as $value) {
                    if (is_string($value)) {
                        $normalized[] = $value;
                    }
                }
            }
        }

        return $normalized;
    }
}
