<?php

namespace App\Services\chunks;

use App\Models\Chunk;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ChunkHydrationService
{
    /**
     * Hydrate les résultats Qdrant avec MySQL
     */
    public function hydrate(array $qdrantResults): array
    {
        //Log::info("resultat QDRANT", $qdrantResults);
        if (empty($qdrantResults)) {
            return [];
        }

        // 1️⃣ Extraire les IDs Qdrant
        $ids = collect($qdrantResults)
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return [];
        }

        // 2️⃣ Charger les chunks MySQL
        $chunks = Chunk::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // 3️⃣ Fusion Qdrant + MySQL
        $hydrated = [];

        foreach ($qdrantResults as $result) {
            $chunk = $chunks->get($result['id']);
            if (!$chunk) continue;

            $textContent = '';

            // Décoder JSON
            $decoded = json_decode($chunk->text, true);
            if (is_array($decoded)) {
                // Extraire les contenus existants
                $contents = array_filter(array_map(fn($c) => $c['content'] ?? null, $decoded));
                $textContent = implode('. ', $contents);
            }

            // Fallback texte brut si JSON invalide
            if (empty($textContent)) {
                $textContent = $chunk->text;
            }

            $hydrated[] = [
                'id' => $chunk->id,
                'text' => $textContent,
                'vector_score' => $result['score'] ?? 0.0,
                'priority' => $chunk->priority ?? 100,
                'source_type' => $chunk->source_type ?? 'unknown',
                'metadata' => $chunk->metadata,
            ];

            /*Log::info('Hydrated chunk text', [
                'id' => $chunk->id,
                'text_length' => strlen($textContent),
                'text_preview' => substr($textContent, 0, 50),
                'text' => $textContent,
            ]);*/ 
        }

        return $hydrated;
    }

    public function hydrateMessages(array $qdrantMessageResults): array
    {
        //Log::info("resultat QDRANT Message", $qdrantMessageResults);
        if (empty($qdrantMessageResults)) {
            return [];
        }

        // 1️⃣ Extraire les IDs
        $ids = collect($qdrantMessageResults)
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return [];
        }

        // 2️⃣ Charger les messages MySQL
        $messages = Message::whereIn('id', $ids)->get()->keyBy('id');

        // 3️⃣ Fusion Qdrant + MySQL
        $hydrated = [];

        foreach ($qdrantMessageResults as $result) {
            $message = $messages->get($result['id']);
            if (!$message) continue;

            $hydrated[] = [
                'id'           => $message->id,
                'text'         => $message->content,
                'vector_score' => $result['score'] ?? 0.0,
                'role'         => $message->role,
                'metadata'     => [
                    'created_at' => $message->created_at,
                    'conversation_id' => $message->conversation_id,
                ],
            ];
        }

        return $hydrated;
    }
}
