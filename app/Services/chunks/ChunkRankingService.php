<?php

namespace App\Services\chunks;

class ChunkRankingService
{
    protected array $sourceWeights = [
        'manuel'      => 1.0,
        'woocommerce' => 0.95,
        'page'        => 0.9,
        'document'    => 0.85,
        'sitemap'     => 0.7,
        'unknown'     => 0.6,
    ];

    public function rank(array $chunks, int $limit = 5): array
    {
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

        usort($ranked, fn ($a, $b) =>
            $b['final_score'] <=> $a['final_score']
        );

        return array_slice($ranked, 0, $limit);
    }
}
