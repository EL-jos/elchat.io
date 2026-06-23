<?php

namespace App\Services\Social;

use App\Enums\Social\MessageDirection;
use App\Enums\Social\ReplyStatus;
use App\Models\Message;
use App\Models\MessageCTA;
use App\Models\Social\SocialMessage;
use App\Models\Social\SocialReplyQueue;
use App\Services\ia\ChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocialReplyEngine
{
    public function __construct(
        protected ChatService $chatService,
        protected SocialConversationMapper $mapper
    ) {}

    public function process(string $messageId): void
    {
        Log::info("DANS SocialReplyEngine::process", [
            "messageId" => $messageId
        ]);
        $incoming = SocialMessage::findOrFail($messageId);

        Log::info("DANS PROCESS", [
            "incoming" => $incoming ?? "rien",
            "Enum direction" => MessageDirection::INCOMING->value ?? "rien",
            "direction" => $incoming->direction->value ?? "rien",
            //"comparaison" => $incoming->direction !== MessageDirection::INCOMING->value,
        ]);

        if ($incoming->direction->value !== MessageDirection::INCOMING->value) {
            return;
        }
        Log::info("INCOMING SOCIAL REPLY", $incoming->toArray());

        $socialConversation = $incoming->conversation;

        /**
         * Bridge vers ELChat
         */
        $conversation = $this->mapper
            ->resolveConversation(
                $socialConversation
            );

        Log::info("RESULTAT DE CONVERSATION MAPPING", $conversation->toArray());

        $site = $conversation->site;


        /**
         * Sauvegarde historique ELChat
         */

        $userMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $incoming->content,
        ]);
        // ────────────────
        // 1️⃣ Mémoire structurée
        // ────────────────
        $messageCount = $conversation->messages()->count();
        Log::info("Nombre de message", [
            "MessageCount" => $messageCount,
            "Conversation Message Count" => $conversation->messages->count()
        ]);

        if ($messageCount === 1) {
            // Premier message => extraction immédiate
            $memory = $this->chatService->extractStructuredMemoryFromMessage($userMessage);

            //dd($memory);
            if (!empty($memory)) {
                DB::table('conversation_memories')->updateOrInsert(
                    ['conversation_id' => $conversation->id],
                    [
                        'id' => (string) Str::uuid(),
                        'memory' => json_encode($memory),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        /**
         * Appel RAG
         */
        $chatResponse = $this->chatService->answer(
            site: $site,
            question: $incoming->content,
            conversation: $conversation
        );

        Log::info("RESULTAT CHAT", [
            "chat" => $chatResponse,
        ]);

        /**
         * Sauvegarde historique ELChat
         */

        $botMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'bot',
            'content' => $chatResponse->message,
            'entities' => $chatResponse->entities,
        ]);

        Log::info("LES CTA'S", [
            "ctas" => $chatResponse->ctas
        ]);

        Log::info("LES ENTITIES", [
            "entities" => $chatResponse->entities
        ]);

        foreach ($chatResponse->ctas as $index => $cta) {

            MessageCta::create([
                'id' => (string) Str::uuid(),
                'message_id' => $botMessage->id,
                'cta_id' => $cta['id'],
                'position' => $index,

                // snapshot
                'label' => $cta['label'],
                'action' => $cta['action'],
                'value' => $cta['value'] ?? null,
                'style' => $cta['style'] ?? null,
            ]);

        }

        $messageCount = $conversation->messages()->count(); // Je recalcule

        if ($messageCount % 5 === 0) {
            // ✅ Ici, après l’indexation et avant d’envoyer la réponse
            $this->chatService->updateConversationMemory($conversation); // ✅ mémoire structurée
        }

        if ($messageCount % 8 === 0){
            $this->chatService->updateConversationSummary($conversation);
        }

        /**
         * Message social sortant
         */

        $outgoing = SocialMessage::create([

            'social_conversation_id'
            => $socialConversation->id,

            'provider'
            => $incoming->provider,

            'direction'
            => MessageDirection::OUTGOING->value,

            'content'
            => $chatResponse->message,

            'message_type'
            => 'text',

            'generated_by_ai'
            => true,

            'confidence_score'
            => 100,

            'metadata' => array_merge(
                $incoming->metadata ?? [],
                [
                    'entities' => $chatResponse->entities,
                    'ctas' => $chatResponse->ctas,
                ]
            ),
        ]);

        SocialReplyQueue::create([

            'social_message_id'
            => $outgoing->id,

            'status'
            => ReplyStatus::PENDING->value,
        ]);
    }
}
