<?php

namespace App\Services\ia;

use App\Models\Chunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EntityResolver
{
    public function resolve(Collection $rankedChunks): array
    {
        $resolved = collect();
        $processedEntities = [];

        foreach ($rankedChunks as $chunk) {

            /*Log::info("EXEMPLE CHUNK", [
                "chunk" => $chunk,
            ]);*/

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
                    $raw = $globalChunk->metadata['raw'] ?? [];

                    // 🔄 Fusion des alias chunks, uniquement pour certains champs
                    $importantFields = ['image_url', 'product_url', 'price', 'discount_price', 'product_name', 'product_reference'];

                    // 🔄 Fusion des alias chunks
                    foreach ($allChunks as $c) {
                        $meta = $c->metadata ?? [];
                        if (($meta['type'] ?? null) === 'statistical_alias') {
                            $field = $meta['field'] ?? null;
                            $value = $meta['value'] ?? null;

                            /*Log::info("ENTITY RESOLVER", [
                                "field" => $field,
                                "value" => $value,
                                "raw" => $raw[$field],
                                "raw global" => $raw,
                            ]);*/

                            if ($field && $value && empty($raw[$field])) {
                                $raw[$field] = $value;
                            }
                        }
                    }

                    $resolved->push([
                        'id' => $globalChunk->id,
                        'text' => $globalChunk->text,
                        'source_type' => $globalChunk->source_type,
                        'metadata' => [
                            ...$globalChunk->metadata,
                            'raw' => $raw, // 🔥 raw enrichi avec les champs essentiels seulement
                        ],
                        'priority' => $globalChunk->priority,
                        'vector_score' => $chunk['vector_score'] ?? null,
                        'final_score' => $chunk['final_score'] ?? null,
                    ]);
                } else {
                    $combinedText = $allChunks->pluck('text')->implode('. ');
                    $chunk['text'] = $combinedText;
                    $resolved->push($chunk);
                }

                /*if ($globalChunk) {
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
                }*/

                continue;
            }

            // --- AUTRES TYPES À RECONSTRUIRE ---
            if (in_array($sourceType, ['crawl', 'sitemap', 'manual', 'document', 'import'])) {

                $originalChunk = Chunk::find($chunk['id']);

                if (!$originalChunk) {
                    $resolved->push($chunk);
                    continue;
                }

                // 🔎 Déterminer la clé unique selon le type
                if (in_array($sourceType, ['document'])) {
                    $entityId = $originalChunk->document_id;
                } else {
                    $entityId = $originalChunk->page_id;
                }

                if (!$entityId) {
                    $resolved->push($chunk);
                    continue;
                }

                $entityKey = $sourceType . '_' . $entityId;

                if (in_array($entityKey, $processedEntities)) {
                    continue;
                }

                $processedEntities[] = $entityKey;

                // 🔄 Récupération propre des chunks liés
                $query = Chunk::where('source_type', $sourceType);

                if ($sourceType === 'document') {
                    $query->where('document_id', $entityId);
                } else {
                    $query->where('page_id', $entityId);
                }

                $allChunks = $query->orderBy('created_at')->get();

                if ($allChunks->isEmpty()) {
                    $resolved->push($chunk);
                    continue;
                }

                $combinedText = $allChunks->pluck('text')->implode('. ');

                $chunk['text'] = $combinedText;

                // 🔹 Ajoute les métadonnées importantes pour les pages
                $metadata = $chunk['metadata'] ?? [];
                $metadata['title'] = $chunk['title'] ?? $originalChunk->title ?? 'Page';
                $metadata['url'] = $chunk['url'] ?? $originalChunk->url ?? null;

                $chunk['metadata'] = $metadata;

                $resolved->push($chunk);

                continue;
            }

            // --- CHUNKS NON-TRAITÉS ---
            $resolved->push($chunk);
        }

        return $resolved->toArray();
    }
}
