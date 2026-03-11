<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Services\ia\ChatService;
use App\Services\ia\EmbeddingService;
use App\Services\MercureService;
use App\Services\vector\VectorCreationService;
use App\Services\vector\VectorIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{

    public function __construct(
        private ChatService $chatService,
        private MercureService $mercureService,
        private VectorCreationService $vectorCreationService,
        private VectorIndexService $vectorIndexService,
        private EmbeddingService $embeddingService,

    ){}
    public function ask(Request $request)
    {

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|exists:conversations,id',
        ]);

        //dd(auth()->user()->ownedAccount);
        $site = Site::where('id', $data['site_id'])
            //->where('account_id', auth()->user()->ownedAccount->id)
            /*->whereHas('users', function($q) {
                $q->where('id', auth()->id());
            })*/
            ->firstOrFail();
        //dd($site);

        // 🔑 Continuité OU nouvelle conversation
        if (!empty($data['conversation_id'])) {
            $conversation = Conversation::where('id', $data['conversation_id'])
                ->where('site_id', $site->id) // ✅ sécurité supplémentaire
                ->where('user_id', auth()->id())
                ->firstOrFail();
        } else {
            $conversation = Conversation::create([
                'site_id' => $site->id,
                'user_id' => auth()->id(),
            ]);

            $isCreated = $this->vectorCreationService->createSiteCollection(
                siteId: $site->id,
                collection: "conversations_{$conversation->id}"
            );

            if ($isCreated) {
                Log::info("Création de la collection réussit", [
                    'collection' => "conversations_{$conversation->id}",
                ]);
            }
        }

        // Sauvegarder la question
        $userMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
            'role' => 'user',
            'content' => $data['question'],
        ]);

        $questionEmbedding = $this->embeddingService->getEmbedding($data['question']);

        $this->vectorIndexService->upsertMessage(
            conversationId: $conversation->id,
            messageId: $userMessage->id,
            embedding: $questionEmbedding,
            payload: [
                'site_id'  => $site->id,
                'conversation_id' => $conversation->id,
                'role'  => "user",
                'content' => $data['question'],
            ]
        );

        Log::info("Avant mercure");

        $topic = "/sites/{$site->id}/conversations/{$conversation->id}";

        $this->mercureService->post($topic, [
            'type' => 'user_message',
            'conversation_id' => $conversation->id,
            'content' => $data['question'],
            'created_at' => now()->toISOString(),
        ]);


        // Générer la réponse (🧠 avec mémoire)
        $answer = $this->chatService->answer(
            site: $site,
            question: $data['question'],
            conversation: $conversation
        );

        // Sauvegarder la réponse
        $botMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
            'role' => 'bot',
            'content' => $answer,
        ]);

        $answerEmbedding = $this->embeddingService->getEmbedding($answer);

        $this->vectorIndexService->upsertMessage(
            conversationId: $conversation->id,
            messageId: $botMessage->id,
            embedding: $answerEmbedding,
            payload: [
                'site_id'  => $site->id,
                'conversation_id' => $conversation->id,
                'role'  => "bot",
                'content' => $botMessage->content,
            ]
        );

        $this->mercureService->post($topic, [
            'type' => 'bot_message',
            'conversation_id' => $conversation->id,
            'content' => $answer,
            'created_at' => now()->toISOString(),
        ]);


        return response()->json([
            'answer' => $answer,
            'conversation_id' => $conversation->id,
        ]);
    }
}
