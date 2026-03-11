<?php

namespace App\Services\product;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\IndexService;
use App\Services\vector\VectorIndexService;
use App\Services\vector\VectorSearchService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductReindexService
{
    public function __construct(
        protected IndexService $indexService,
        protected VectorIndexService $vectorIndexService
    ) {}
    /**
     * Liste paginée des produits (chunks globaux uniquement)
     */
    public function listProducts(
        string $siteId,
        int $page = 1,
        int $perPage = 20,
        ?string $search = null
    ): LengthAwarePaginator {
        
        Log::info('[PRODUCT LIST] Début listing produits', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search
        ]);

        $query = Chunk::select(
            'document_id',
            DB::raw("metadata->>'$.product_index' as product_index"),
            DB::raw("MAX(metadata->>'$.identifier') as identifier"),
            DB::raw("MAX(text) as text"),
            DB::raw("MAX(metadata->>'$.raw') as raw")
        )
            ->where('site_id', $siteId)
            ->where('source_type', 'woocommerce')
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.type'))) = 'global'")
            ->whereRaw("JSON_EXTRACT(metadata, '$.product_index') IS NOT NULL");

        // 🔎 Recherche simplifiée sur identifier
        if (!empty($search)) {
            $search = strtolower(trim($search));
            $query->whereRaw(
                "LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.identifier'))) LIKE ?",
                ["%{$search}%"]
            );
        }

        $query->groupBy('document_id', DB::raw("metadata->>'$.product_index'"));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function ($chunk) {
            return [
                'document_id'   => $chunk->document_id,
                'product_index' => (int) $chunk->product_index,
                'identifier'    => $chunk->identifier,
                'global_text'   => $chunk->text,
                'fields'        => json_decode($chunk->raw, true) ?? [],
            ];
        });

        Log::info('[PRODUCT LIST] Fin listing', [
            'total' => $paginator->total()
        ]);

        return $paginator;
    }
    /**
     * Réindexe un produit spécifique
     */
    public function reindexProduct(Document $document, int $productIndex, array $productData): array
    {
        Log::info('[PRODUCT REINDEX] Démarrage', [
            'document_id'   => $document->id,
            'product_index' => $productIndex
        ]);

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ Récupération anciens chunks
            |--------------------------------------------------------------------------
            */

            $oldChunks = Chunk::where('document_id', $document->id)
                ->where('source_type', 'woocommerce')
                ->where('metadata->product_index', $productIndex)
                ->get();

            Log::info('[PRODUCT REINDEX] Anciens chunks trouvés', [
                'count' => $oldChunks->count()
            ]);

            /*
            |--------------------------------------------------------------------------
            | 2️⃣ Suppression Vector DB
            |--------------------------------------------------------------------------
            */

            foreach ($oldChunks as $chunk) {
                $this->vectorIndexService->deleteChunk($chunk->id);
            }

            Log::info('[PRODUCT REINDEX] Suppression Qdrant OK');

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Suppression MySQL
            |--------------------------------------------------------------------------
            */

            Chunk::where('document_id', $document->id)
                ->where('source_type', 'woocommerce')
                ->where('metadata->product_index', $productIndex)
                ->delete();

            Log::info('[PRODUCT REINDEX] Suppression MySQL OK');

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ Reconstruction produit
            |--------------------------------------------------------------------------
            */

            $this->indexService->indexStandardProduct(
                $productData,
                $document,
                $productIndex - 1
            );

            Log::info('[PRODUCT REINDEX] Reconstruction terminée');

            /*
            |--------------------------------------------------------------------------
            | 5️⃣ Vérification du chunk global
            |--------------------------------------------------------------------------
            */

            $globalChunk = Chunk::where('document_id', $document->id)
                ->where('source_type', 'woocommerce')
                ->where('metadata->product_index', $productIndex)
                ->where('metadata->type', 'global')
                ->first();

            if (!$globalChunk) {
                throw new \Exception("Global chunk non recréé après réindexation");
            }

            DB::commit();

            Log::info('[PRODUCT REINDEX] Succès', [
                'document_id' => $document->id,
                'product_index' => $productIndex
            ]);

            return [
                'status' => 'success',
                'message' => 'Produit réindexé avec succès',
                'data' => [
                    'document_id'   => $document->id,
                    'product_index' => $productIndex,
                    'identifier'    => $globalChunk->metadata['identifier'] ?? null,
                    'global_text'   => $globalChunk->text,
                    'fields'        => $globalChunk->metadata['raw'] ?? [],
                ]
            ];

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[PRODUCT REINDEX] ÉCHEC', [
                'document_id' => $document->id,
                'product_index' => $productIndex,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => 'Échec de la réindexation',
                'error' => $e->getMessage()
            ];
        }
    }
    /**
     * Réindexe un ou plusieurs produits
     *
     * @param Document $document
     * @param array|int $productIndices  Ex: 3 ou [1,2,3]
     * @param array $productsData        Ex: [1 => [...], 2 => [...]]
     */
    public function reindexProducts(Document $document, array|int $productIndices, array $productsData = []): array
    {
        $productIndices = is_array($productIndices) ? $productIndices : [$productIndices];
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($productIndices as $productIndex) {

                Log::info('[PRODUCT REINDEX] Démarrage', [
                    'document_id'   => $document->id,
                    'product_index' => $productIndex
                ]);

                // 1️⃣ Récupération anciens chunks
                $oldChunks = Chunk::where('document_id', $document->id)
                    ->where('source_type', 'woocommerce')
                    ->where('metadata->product_index', $productIndex)
                    ->get();

                Log::info('[PRODUCT REINDEX] Chunks trouvés', [
                    'product_index' => $productIndex,
                    'count' => $oldChunks->count()
                ]);

                // 2️⃣ Suppression Qdrant en batch
                $chunkIds = $oldChunks->pluck('id')->all();
                if (!empty($chunkIds)) {
                    $this->vectorIndexService->deleteChunksBatch($chunkIds);
                }

                // 3️⃣ Suppression MySQL
                Chunk::whereIn('id', $chunkIds)->delete();
                Log::info('[PRODUCT REINDEX] Suppression MySQL OK', [
                    'deleted_count' => count($chunkIds)
                ]);

                // 4️⃣ Reconstruction produit
                $productData = $productsData[$productIndex] ?? [];
                $this->indexService->indexStandardProduct($productData, $document, $productIndex - 1);

                // 5️⃣ Vérification chunk global
                $globalChunk = Chunk::where('document_id', $document->id)
                    ->where('source_type', 'woocommerce')
                    ->where('metadata->product_index', $productIndex)
                    ->where('metadata->type', 'global')
                    ->first();

                if (!$globalChunk) {
                    throw new \Exception("Global chunk non recréé pour product_index {$productIndex}");
                }

                $results[$productIndex] = [
                    'status' => 'success',
                    'document_id' => $document->id,
                    'product_index' => $productIndex,
                    'identifier' => $globalChunk->metadata['identifier'] ?? null,
                    'global_text' => $globalChunk->text,
                    'fields' => $globalChunk->metadata['raw'] ?? [],
                ];

                Log::info('[PRODUCT REINDEX] Succès', [
                    'document_id' => $document->id,
                    'product_index' => $productIndex
                ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[PRODUCT REINDEX] ÉCHEC', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            foreach ($productIndices as $productIndex) {
                $results[$productIndex] = [
                    'status' => 'error',
                    'message' => 'Échec de la réindexation',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
