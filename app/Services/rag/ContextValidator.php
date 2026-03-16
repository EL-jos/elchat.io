<?php

namespace App\Services\rag;

use App\Services\ia\EmbeddingService;
use App\Services\queryAnalyzer\QueryPlan;

class ContextValidator
{
    /*public function validate(
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

            if ($tokenMatches >= 1  || $entityMatch) {
                $relevantChunks++;
            }
        }

        return $relevantChunks > 0;
    }*/

    public function validate(array $chunks, QueryPlan $queryPlan): bool
    {
        if (empty($chunks)) return false;

        $entities = $this->normalizeEntities($queryPlan->entities ?? []);
        if (empty($entities)) $entities[] = $queryPlan->cleanQuery;

        $relevantChunks = 0;
        $threshold = min(0.5, max(0.35, strlen($queryPlan->cleanQuery)/200));

        foreach ($chunks as $chunk) {
            $sim = $this->semanticSimilarity($chunk['text'], $queryPlan->cleanQuery);

            // bonus si chunk contient une entité de la query
            $entityBonus = 0;
            foreach ($entities as $e) {
                if (stripos($chunk['text'], $e) !== false) {
                    $entityBonus = 0.15;
                    break;
                }
            }

            if (($sim + $entityBonus) >= $threshold) {
                $relevantChunks++;
            }
        }

        // au moins 3 chunks pertinents ou la moitié des chunks disponibles
        return $relevantChunks >= min(3, ceil(count($chunks)/2));
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

    /**
     * Calcule la similarité cosinus entre le texte du chunk et la question
     */
    public function semanticSimilarity(string $text, string $query): float
    {
        // Récupérer les embeddings
        $textEmbedding = app()->make(EmbeddingService::class)->getEmbedding($text);
        $queryEmbedding = app()->make(EmbeddingService::class)->getEmbedding($query);

        // Calcul cosinus
        $dot = 0.0;
        $normText = 0.0;
        $normQuery = 0.0;

        foreach ($textEmbedding as $i => $v) {
            $dot += $v * $queryEmbedding[$i];
            $normText += $v * $v;
            $normQuery += $queryEmbedding[$i] * $queryEmbedding[$i];
        }

        return $dot / (sqrt($normText) * sqrt($normQuery) + 1e-10); // +epsilon pour éviter div0
    }
}
