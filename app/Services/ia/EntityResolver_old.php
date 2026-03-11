<?php

namespace App\Services\ia;

use App\Models\Chunk;
use Illuminate\Support\Collection;

class EntityResolver
{
    public function resolve(Collection $rankedChunks): array
    {
        $resolved = collect();
        $processedEntities = [];

        foreach ($rankedChunks as $chunk) {

            $metadata = $chunk['metadata'] ?? [];
            $sourceType = $chunk['source_type'] ?? null;

            // --- PRODUITS WOOCOMMERCE ---
            if ($sourceType === 'woocommerce' && isset($metadata['product_index'])) {

                $entityKey = 'product_' . $metadata['product_index'];
                if (in_array($entityKey, $processedEntities)) {
                    continue;
                }
                $processedEntities[] = $entityKey;

                $originalChunk = Chunk::find($chunk['id']);
                if (!$originalChunk) {
                    $resolved->push($chunk);
                    continue;
                }

                $documentId = $originalChunk->document_id;

                $allChunks = Chunk::where('document_id', $documentId)
                    ->where('source_type', 'woocommerce')
                    ->where('metadata->product_index', $metadata['product_index'])
                    ->get();

                $globalChunk = $allChunks->first(fn($c) => ($c->metadata['type'] ?? null) === 'global');

                if ($globalChunk) {
                    $resolved->push([
                        'id' => $globalChunk->id,
                        'text' => $globalChunk->text,
                        'source_type' => $globalChunk->source_type,
                        'metadata' => $globalChunk->metadata,
                        'priority' => $globalChunk->priority,
                        'vector_score' => $chunk['vector_score'] ?? null,
                        'final_score' => $chunk['final_score'] ?? null,
                    ]);
                } else {
                    $combinedText = $allChunks->pluck('text')->implode('. ');
                    $chunk['text'] = $combinedText;
                    $resolved->push($chunk);
                }

                continue;
            }

            // --- AUTRES TYPES À RECONSTRUIRE ---
            if (in_array($sourceType, ['crawl', 'sitemap', 'manual', 'document'])) {

                // Déterminer la clé unique de l'entité
                $entityKey = $sourceType . '_' . ($chunk['document_id'] ?? $chunk['page_id'] ?? $chunk['id']);
                if (in_array($entityKey, $processedEntities)) {
                    continue;
                }
                $processedEntities[] = $entityKey;

                // Récupération de tous les chunks associés à cette entité
                $query = Chunk::query()->where('source_type', $sourceType);

                if (isset($chunk['document_id'])) {
                    $query->where('document_id', $chunk['document_id']);
                } elseif (isset($chunk['page_id'])) {
                    $query->where('page_id', $chunk['page_id']);
                }

                $allChunks = $query->get();

                // Reconstitution du texte global
                $combinedText = $allChunks->pluck('text')->implode('. ');
                $chunk['text'] = $combinedText;

                $resolved->push($chunk);
                continue;
            }

            // --- CHUNKS NON-TRAITÉS ---
            $resolved->push($chunk);
        }

        return $resolved->toArray();
    }
}
