<?php

namespace App\Services\vector;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorIndexService
{
    protected string $endpoint;
    protected string $collection;

    public function __construct()
    {
        //dd(config('qdrant'));
        $this->endpoint  = config('qdrant.url'); // http://127.0.0.1:6333
        $this->collection = 'chunks';
    }

    /**
     * Upsert d’un chunk dans Qdrant
     */
    public function upsertChunk(
        string $chunkId,
        array $embedding,
        array $payload,
        string $collection = 'chunks'
    ): void {

        $this->collection = $collection;

        try {
            $this->http()->put(
                "{$this->endpoint}/collections/{$this->collection}/points",
                [
                    'points' => [
                        [
                            'id'      => $chunkId,   // UUID MySQL
                            'vector'  => $embedding,
                            'payload' => $payload,
                        ],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // ⚠️ On LOG, mais on ne casse JAMAIS l’indexation
            Log::error('Qdrant upsert failed', [
                'chunk_id' => $chunkId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function deleteChunk(string $chunkId, string $collection = 'chunks'): void
    {
        $this->collection = $collection;

        try {
            $response = $this->http()->post(
                "{$this->endpoint}/collections/{$this->collection}/points/delete",
                [
                    'points' => [$chunkId],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception('Qdrant upsert failed: '.$response->body());
            }

            Log::info('Qdrant delete success', [
                'chunk_id' => $chunkId,
            ]);

        } catch (\Throwable $e) {

            // ⚠️ On log mais on ne casse PAS la transaction
            Log::error('Qdrant delete failed', [
                'chunk_id' => $chunkId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Suppression multiple chunks en batch
     */
    public function deleteChunksBatch(array $chunkIds, string $collection = 'chunks'): void
    {
        if (empty($chunkIds)) return;

        $this->collection = $collection;

        try {
            $this->http()->post(
                "{$this->endpoint}/collections/{$this->collection}/points/delete",
                ['points' => $chunkIds]
            );

            Log::info('Qdrant batch delete success', [
                'deleted_count' => count($chunkIds),
            ]);

        } catch (\Throwable $e) {
            Log::error('Qdrant batch delete failed', [
                'chunk_ids_count' => count($chunkIds),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function upsertMessage(
        string $messageId,
        array $embedding,
        array $payload
    ): void {
        $this->upsertChunk($messageId, $embedding, $payload, 'messages');
    }
    
    protected function http()
    {
        return Http::timeout(10)
            ->withHeaders([
                'api-key' => config('qdrant.api_key'),
                'Content-Type' => 'application/json',
            ]);
    }
}
