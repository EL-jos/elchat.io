<?php

namespace App\Services\ia;

/*class FollowUpDetector
{
    public function isFollowUp(string $question): bool
    {
        $short = str_word_count($question) <= 7;

        $containsPronoun = preg_match(
            '/\b(il|elle|ils|elles|ce|cela|ça|celui|celle|ceux|celles|le|la|les|lui)\b/i',
            $question
        );

        $startsWithConnector = preg_match(
            '/^(et|ou|mais|donc|alors|aussi)/i',
            trim($question)
        );

        return $short || $containsPronoun || $startsWithConnector;
    }
}*/

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;

class FollowUpDetector
{
    public function isFollowUp(string $question, Conversation $conversation): bool
    {
        $lastMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(4)
            ->get()
            ->reverse()
            ->map(fn($m) => $m->content)
            ->implode("\n");

        $systemPrompt = "
        Tu es un classificateur.
        Ta mission est de déterminer si une question dépend
        du contexte précédent.

        Réponds uniquement par :
        YES ou NO
        ";

        $userPrompt = "
        Historique :
        {$lastMessages}

        Nouvelle question :
        {$question}

        Dépend du contexte ?
        ";

        try {
            $response = Http::timeout(10)
                ->retry(2, 300)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                ])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'meta-llama/llama-3.1-8b-instruct',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0,
                    'max_tokens' => 5,
                ]);

            $answer = strtoupper(trim(
                $response->json()['choices'][0]['message']['content'] ?? 'NO'
            ));

            return $answer === 'YES';

        } catch (\Throwable $e) {
            return false; // fallback safe
        }
    }
}
