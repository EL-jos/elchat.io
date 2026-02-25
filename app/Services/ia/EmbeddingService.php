<?php
namespace App\Services\ia;

use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    /**
     * Retourne un embedding pour un texte
     * MVP dev : OpenRouter (gratuit)
     * Production : OpenAI
     */
    public function getEmbedding(string $text): array
    {
        $attempts = 0;
        $maxAttempts = 5;

        do {
            $attempts++;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY')
            ])->post('https://openrouter.ai/api/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => $text
            ]);

            if (!$response->successful()) {
                usleep(500_000);
                continue;
            }

            $json = $response->json();

            if (isset($json['data'][0]['embedding'])) {
                return $json['data'][0]['embedding'];
            }

            // petite pause pour éviter le spam API
            usleep(500_000); // 500ms

        } while ($attempts < $maxAttempts);

        throw new \RuntimeException(
            'Embedding failed after retries: ' . json_encode($json ?? null)
        );
    }


}
