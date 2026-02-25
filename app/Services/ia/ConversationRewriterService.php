<?php

namespace App\Services\ia;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class ConversationRewriterService
{
    public function __construct()
    {
    }

    /*public function rewrite(string $question, Conversation $conversation): string
    {
        $lastMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get()
            ->reverse()
            ->map(fn($m) => $m->content)
            ->implode("\n");

        $systemPrompt = "
        Tu es un assistant chargé de reformuler une question utilisateur
        en la rendant autonome et complète.

        Règles :
        - Ne réponds PAS à la question.
        - Reformule uniquement.
        - Intègre le contexte précédent si nécessaire.
        - Sois factuel et précis.
        - Une seule phrase.
        ";

                $userPrompt = "
        Historique :
        {$lastMessages}

        Nouvelle question :
        {$question}

        Reformulation complète :
        ";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
        ])->post('https://openrouter.ai/api/v1/chat/completions', [
            'model' => 'meta-llama/llama-3.1-8b-instruct',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 150,
        ]);

        return $response->json()['choices'][0]['message']['content']
            ?? $question;
    }*/
    public function rewrite(string $question, Conversation $conversation): string
    {
        $lastMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get()
            ->reverse()
            ->map(fn($m) => $m->content)
            ->implode("\n");

        $systemPrompt = "
        Tu es un assistant chargé de reformuler une question utilisateur
        en la rendant autonome et complète.

        Règles :
        - Ne réponds PAS à la question.
        - Reformule uniquement.
        - Intègre le contexte précédent si nécessaire.
        - Sois factuel et précis.
        - Une seule phrase.
        ";

            $userPrompt = "
        Historique :
        {$lastMessages}

        Nouvelle question :
        {$question}

        Reformulation complète :
        ";

        try {
            $response = Http::timeout(15)
                ->retry(3, 500, function ($exception, $request) {
                    if ($exception instanceof RequestException) {
                        $status = optional($exception->response)->status();
                        return in_array($status, [429, 500, 502, 503, 504]);
                    }
                    return true; // retry sur timeout réseau
                })
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                ])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'meta-llama/llama-3.1-8b-instruct',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 150,
                ]);

            return $response->json()['choices'][0]['message']['content']
                ?? $question;

        } catch (\Throwable $e) {
            Log::error('Rewrite failed', [
                'error' => $e->getMessage(),
            ]);

            // fallback intelligent
            return $question;
        }
    }
}
