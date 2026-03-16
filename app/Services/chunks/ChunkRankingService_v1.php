<?php

namespace App\Services\chunks;

use Illuminate\Support\Facades\Log;

class ChunkRankingService
{
    protected array $sourceWeights = [
        'manuel'      => 0.97,
        'manual'      => 0.97,
        'woocommerce' => 0.95,
        'page'        => 0.95,
        'crawl'       => 0.95,
        'import'      => 0.95,
        'document'    => 0.85,
        'sitemap'     => 0.7,
        'unknown'     => 0.6,
    ];

    public function rank(array $chunks,  float $minScore = 0.45, ?int $limit = null): array
    {
        // 1️⃣ Calcul du score final pour chaque chunk
        $ranked = array_map(function ($chunk) {

            $priorityWeight = 1 / (1 + max(1, (int)$chunk['priority']));
            $sourceWeight = $this->sourceWeights[$chunk['source_type']] ?? 0.6;

            $finalScore =
                ($chunk['vector_score'] * 0.65)
                + ($priorityWeight * 0.20)
                + ($sourceWeight * 0.15);

            return array_merge($chunk, [
                'final_score' => round($finalScore, 4),
            ]);

        }, $chunks);

        // 2️⃣ Tri descendant par final_score
        usort($ranked, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        // 3️⃣ Limite dynamique
        if ($limit === null) {
            // Inclure tous les chunks au-dessus du score minimal
            $ranked = array_filter($ranked, fn($chunk) => $chunk['final_score'] >= $minScore);
        } else {
            // Limite classique si définie
            $ranked = array_slice($ranked, 0, $limit);
        }

        return array_values($ranked); // ré-indexer
    }
}
