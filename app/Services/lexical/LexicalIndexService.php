<?php

namespace App\Services\lexical;

use Exception;
use Meilisearch\Client;
use Throwable;

class LexicalIndexService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('meilisearch.url'),
            config('meilisearch.key')
        );
    }

    public function createIndexIfNotExists(string $siteId): void{

        $indexName = "chunks_{$siteId}";

        try {

            // Vérifie si l’index existe déjà
            $this->client->getIndex($indexName);
            return; // existe déjà → rien à faire

        } catch (Throwable $e) {
            // index n'existe pas → on le crée
            $this->client->createIndex($indexName, [
                'primaryKey' => 'id',
            ]);
        }

        $this->applySettings($siteId);

    }
    protected function applySettings(string $siteId): void{

        $this->index($siteId)->updateSettings([
            'searchableAttributes' => [
                'title',
                'content',
                'text',
                'aliases', // 🔥 MAIS en dernier
            ],
            'filterableAttributes' => [
                'site_id',
                'source_type',
            ],
            'sortableAttributes' => [
                'priority',
            ],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
                'desc(priority)', // 🔥 business ranking
            ],
            'typoTolerance' => [
                'enabled' => true,
            ],
            'attributesToHighlight' => [
                'text'
            ],
            /*'attributesToSearchOn' => [
                'title',
                'text',
                'content'
            ],*/
        ]);

    }
    protected function index(string $siteId)
    {
        return $this->client->index("chunks_$siteId");
    }

    /**
     * 🔥 Initialise l'index (CRITIQUE)
     */
    public function ensureIndex(string $siteId): void
    {

        if (cache()->get("meili_index_ready_{$siteId}")) {
            return;
        }

        $this->createIndexIfNotExists($siteId);

        cache()->put("meili_index_ready_{$siteId}", true, 86400);
    }

    /**
     * Upsert chunk
     */
    public function upsertChunk(array $doc, string $siteId): void
    {
        //$this->ensureIndex($siteId); // 🔥 sécurité

        $this->index($siteId)->addDocuments([$doc], 'id'); // 🔥 PRIMARY KEY
    }

    public function deleteChunk(string $chunkId, string $siteId): void
    {
        $this->index($siteId)->deleteDocument($chunkId);
    }

    public function search(string $query, string $siteId, int $limit = 20): array
    {
        $results = $this->index($siteId)->search($query, [
            'limit' => $limit,
        ]);

        return collect($results['hits'])
            ->map(function ($hit) {
                return [
                    'id' => $hit['id'],
                    'score' => $hit['_rankingScore'] ?? 0,
                    'source' => 'keyword',
                    'payload' => $hit,
                ];
            })
            ->toArray();
    }
}
