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
use Illuminate\Support\Facades\DB;
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
            'visitor_id' => 'nullable|exists:visitors,id',
        ]);

        $userId = auth()->id();
        $visitorId = $data['visitor_id'] ?? null;

        if (!$userId && !$visitorId) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        $site = Site::where('id', $data['site_id'])
            ->firstOrFail();

        // 🔑 Continuité OU nouvelle conversation
        if (!empty($data['conversation_id'])) {
            $conversation = Conversation::where('id', $data['conversation_id'])
                ->where('site_id', $site->id) // ✅ sécurité supplémentaire
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->when(!$userId && $visitorId, fn ($q) => $q->where('visitor_id', $visitorId))
                ->firstOrFail();
        } else {
            $conversation = Conversation::create([
                'site_id' => $site->id,
                'user_id' => $userId,
                'visitor_id' => $visitorId,
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
            'user_id' => $userId,
            'role' => 'user',
            'content' => $data['question'],
        ]);

        // ────────────────
        // 1️⃣ Mémoire structurée
        // ────────────────
        $messageCount = Message::where('conversation_id', $conversation->id)->count();
        if ($messageCount === 1) {
            // Premier message => extraction immédiate
            $memory = $this->chatService->extractStructuredMemoryFromMessage($userMessage);
            if (!empty($memory)) {
                DB::table('conversation_memories')->updateOrInsert(
                    ['conversation_id' => $conversation->id],
                    [
                        'memory' => json_encode($memory),
                        'updated_at' => now(),
                        'id' => (string) Str::uuid(),
                    ]
                );
            }
        } elseif ($conversation->messages->count() % 6 === 0) {
            // ✅ Ici, après l’indexation et avant d’envoyer la réponse
            // Tous les 6 messages => mise à jour de la mémoire avec résumé
            $this->chatService->updateConversationSummary($conversation);
            $this->chatService->updateConversationMemory($conversation); // ✅ mémoire structurée
        }



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
            'user_id' => $userId,
            'role' => 'bot',
            'content' => $answer,
        ]);

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
