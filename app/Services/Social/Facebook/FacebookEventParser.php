<?php

namespace App\Services\Social\Facebook;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class FacebookEventParser
{
    public function handle(SocialEvent $event): void
    {
        $payload = $event->payload;

        Log::info('[Facebook] RAW_PAYLOAD', $payload);

        if (isset($payload['sample'])) {
            Log::info('[Facebook] Payload de test Meta ignoré', [
                'field' => $payload['sample']['field'] ?? 'unknown'
            ]);
            return;
        }

        if (!isset($payload['object'], $payload['entry']) || $payload['object'] !== 'page') {
            Log::warning('[Facebook] Payload non reconnu', $payload);
            return;
        }

        foreach ($payload['entry'] as $entry) {
            $pageId = $entry['id'] ?? null;

            if (!$pageId) {
                continue;
            }

            $account = $this->resolveAccount($pageId);

            if (!$account) {
                Log::warning('[Facebook] Aucun SocialAccount trouvé pour la page', [
                    'page_id' => $pageId
                ]);
                continue;
            }

            // 📨 Cas 1 : Messages inbox
            if (!empty($entry['messaging'])) {
                foreach ($entry['messaging'] as $messagingEvent) {
                    $this->handleMessage($account, $messagingEvent);
                }
                continue;
            }

            // 📰 Cas 2 : Feed
            if (!empty($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (($change['field'] ?? null) !== 'feed') {
                        continue;
                    }
                    $this->handleFeed($account, $change['value'] ?? []);
                }
                continue;
            }

            Log::info('[Facebook] Entry sans changes ni messaging ignorée', [
                'entry_id' => $pageId
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE ACCOUNT
    // ─────────────────────────────────────────────────────────

    private function resolveAccount(string $pageId): ?SocialAccount
    {
        $account = SocialAccount::where('provider', 'facebook')
            ->where('provider_account_id', $pageId)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('[Facebook] SocialAccount introuvable ou inactif', [
                'page_id' => $pageId
            ]);
        }

        return $account;
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE FEED
    // ─────────────────────────────────────────────────────────

    private function handleFeed(SocialAccount $account, array $value): void
    {
        $item = $value['item'] ?? null;
        $verb = $value['verb'] ?? null;

        if (!in_array($item, ['comment', 'status', 'post'], true)) {
            Log::info('[Facebook][Feed] Item ignoré', ['item' => $item]);
            return;
        }

        if ($verb !== 'add') {
            Log::info('[Facebook][Feed] Verb ignoré', ['verb' => $verb]);
            return;
        }

        $from      = $value['from']       ?? null;
        $postId    = $value['post_id']    ?? null;
        $commentId = $value['comment_id'] ?? null;
        $parentId  = $value['parent_id']  ?? null;
        $content   = $value['message']    ?? $value['story'] ?? null;

        if (!$from || !isset($from['id'])) {
            Log::warning('[Facebook][Feed] "from" manquant', $value);
            return;
        }

        if (!$postId) {
            Log::warning('[Facebook][Feed] "post_id" manquant', $value);
            return;
        }

        // ✅ Réponse = parent_id existe ET diffère du post_id
        $isReply = $parentId && $parentId !== $postId;

        // ✅ Echo = la page répond via Graph API → from.id === page.id
        $isPageEcho = $from['id'] === $account->provider_account_id;

        if ($isPageEcho) {
            $this->handleFeedEcho(
                $account, $value, $postId, $commentId, $parentId, $isReply, $content
            );
            return;
        }

        // ✅ Toujours la même conv (user + post), qu'il soit reply ou non
        $conversation = $this->resolveConversation($account, $from, $postId);

        // ✅ Résoudre le message parent logique dans le thread
        $parentMessage = $this->resolveParentMessage(
            $parentId,
            $postId,
            $value['created_time'] ?? null,
            $isReply
        );

        $externalMessageId = $commentId
            ?? ('fb_feed_' . ($value['created_time'] ?? uniqid()));

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'facebook',
                'external_message_id' => $externalMessageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $content ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata'               => [
                    'raw'               => $value,
                    'post_id'           => $postId,
                    'comment_id'        => $commentId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $isReply,
                    'post'              => $value['post']                  ?? null,
                    'permalink'         => $value['post']['permalink_url'] ?? null,
                ],
                'published_at' => isset($value['created_time'])
                    ? \Carbon\Carbon::createFromTimestamp($value['created_time'])
                    : now(),
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Facebook][Feed] Nouveau message entrant créé', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'type'              => $item,
                'is_reply'          => $isReply,
                'parent_message_id' => $parentMessage?->id,
                'from'              => $from['name'] ?? $from['id'],
            ]);
            SocialMessageReceivedJob::dispatch($message->id);
        }

        $conversation->update(['last_message_at' => now()]);
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE FEED ECHO (réponse IA via Graph API)
    // ─────────────────────────────────────────────────────────

    private function handleFeedEcho(
        SocialAccount $account,
        array         $value,
        string        $postId,
        ?string       $commentId,
        ?string       $parentId,
        bool          $isReply,
        ?string       $content,
    ): void {

        // ✅ Retrouver la conv du post la plus récente
        // On prend latest('last_message_at') car plusieurs users
        // peuvent avoir des convs sur le même post
        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'facebook',
            'context_type'      => 'feed_comment',
            'context_id'        => $postId,
        ])->latest('last_message_at')->first();

        if (!$conversation) {
            Log::warning('[Facebook][Feed] Echo IA sans conversation parente trouvée', [
                'post_id'    => $postId,
                'comment_id' => $commentId,
                'parent_id'  => $parentId,
            ]);
            return;
        }

        // ✅ Résoudre le message parent logique dans le thread
        // = le dernier message posté AVANT l'echo de l'IA
        $parentMessage = $this->resolveParentMessage(
            $parentId,
            $postId,
            $value['created_time'] ?? null,
            $isReply
        );

        $externalMessageId = $commentId
            ?? ('fb_echo_' . ($value['created_time'] ?? uniqid()));

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'facebook',
                'external_message_id' => $externalMessageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $content ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => [
                    'raw'               => $value,
                    'post_id'           => $postId,
                    'comment_id'        => $commentId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $isReply,
                    'is_echo'           => true,
                ],
                'published_at' => isset($value['created_time'])
                    ? \Carbon\Carbon::createFromTimestamp($value['created_time'])
                    : now(),
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Facebook][Feed] Echo IA enregistré comme message sortant', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'parent_message_id' => $parentMessage?->id,
                'parent_fb_id'      => $parentId,
            ]);
        }

        $conversation->update(['last_message_at' => now()]);
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATION
    // ─────────────────────────────────────────────────────────

    private function resolveConversation(
        SocialAccount $account,
        array         $from,
        string        $postId,
    ): SocialConversation {

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'facebook',
                'external_user_id'  => $from['id'],
                'context_type'      => 'feed_comment',
                'context_id'        => $postId,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $from['name'] ?? null,
                'external_display_name' => $from['name'] ?? null,
                'context_type'          => 'feed_comment',
                'context_id'            => $postId,
                'source_object_id'      => $postId,
                'metadata'              => [
                    'from'    => $from,
                    'post_id' => $postId,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE PARENT MESSAGE
    //
    // Limitation Facebook : parent_id pointe toujours vers le
    // commentaire RACINE du thread, pas vers le commentaire
    // directement ciblé (Facebook aplatit les réponses imbriquées).
    //
    // Stratégie : le parent logique = le dernier message du thread
    // posté AVANT le message courant, incoming ou outgoing.
    // C'est l'approche utilisée par Hootsuite, Sprout Social, etc.
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(
        ?string    $parentId,
        string     $postId,
        int|null   $currentCreatedTime,
        bool       $isReply,
    ): ?SocialMessage {

        // Pas une réponse → pas de parent
        if (!$isReply || !$parentId || !$currentCreatedTime) {
            return null;
        }

        $currentPublishedAt = \Carbon\Carbon::createFromTimestamp($currentCreatedTime);

        // ✅ Étape 1 — Trouver le commentaire racine (parent_id Facebook)
        // pour ancrer la recherche dans le bon thread
        $rootMessage = SocialMessage::where('provider', 'facebook')
            ->where('external_message_id', $parentId)
            ->first();

        if (!$rootMessage) {
            Log::warning('[Facebook] Message racine introuvable', [
                'parent_id' => $parentId,
                'post_id'   => $postId,
            ]);
            return null;
        }

        // ✅ Étape 2 — Dans ce thread, trouver le dernier message
        // posté AVANT le message courant (incoming ou outgoing)
        // Le thread = tous les messages qui partagent le même post_id
        // ET dont le parent_id ou comment_id est dans ce thread
        $lastMessageInThread = SocialMessage::where('provider', 'facebook')
            ->where(function ($q) use ($parentId, $postId) {
                $q
                    // Le commentaire racine lui-même
                    ->where('external_message_id', $parentId)
                    // Ou tout message qui répond à ce parent
                    ->orWhereJsonContains('metadata->parent_id', $parentId)
                    // Ou tout message du même post (filet de sécurité)
                    ->orWhereJsonContains('metadata->post_id', $postId);
            })
            ->where('published_at', '<', $currentPublishedAt)
            ->orderByDesc('published_at')
            ->first();

        if (!$lastMessageInThread) {
            // Fallback : le commentaire racine est le parent
            return $rootMessage;
        }

        return $lastMessageInThread;
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE MESSAGE (inbox)
    // ─────────────────────────────────────────────────────────

    private function handleMessage(SocialAccount $account, array $messagingEvent): void
    {
        $senderId    = $messagingEvent['sender']['id']    ?? null;
        $recipientId = $messagingEvent['recipient']['id'] ?? null;
        $msg         = $messagingEvent['message']         ?? null;
        $timestamp   = $messagingEvent['timestamp']       ?? null;

        if (!$senderId || !$msg) {
            Log::warning('[Facebook][Message] Event incomplet', $messagingEvent);
            return;
        }

        $text   = $msg['text'] ?? null;
        $mid    = $msg['mid']  ?? null;
        $isEcho = ($msg['is_echo'] ?? false) || $senderId === $recipientId;

        // ✅ Echo = message envoyé par la page (IA) → traitement séparé
        if ($isEcho) {
            $this->handleInboxEcho(
                $account, $messagingEvent, $senderId, $recipientId, $text, $mid, $timestamp
            );
            return;
        }

        if (!$text) {
            Log::info('[Facebook][Message] Message non-texte reçu', [
                'type' => $this->resolveAttachmentType($msg),
                'mid'  => $mid,
            ]);
            return;
        }

        if (!$mid) {
            Log::warning('[Facebook][Message] "mid" manquant', $messagingEvent);
            return;
        }

        $publishedAt = $timestamp
            ? \Carbon\Carbon::createFromTimestampMs($timestamp)
            : now();

        // ✅ 1 conv inbox par (user + page), séparée du feed
        $conversation = SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'facebook',
                'external_user_id'  => $senderId,
                'context_type'      => 'inbox',
                'context_id'        => null,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => null,
                'external_display_name' => null,
                'context_type'          => 'inbox',
                'context_id'            => null,
                'source_object_id'      => null,
                'metadata'              => [
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                ],
                'last_message_at' => $publishedAt,
            ]
        );

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'facebook',
                'external_message_id' => $mid,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata'               => [
                    'raw'          => $messagingEvent,
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                    'mid'          => $mid,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Facebook][Message] Nouveau message inbox entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'sender_id'       => $senderId,
                'mid'             => $mid,
            ]);
            SocialMessageReceivedJob::dispatch($message->id);
        }

        $conversation->update(['last_message_at' => $publishedAt]);
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE INBOX ECHO (réponse IA via Graph API Send Message)
    // ─────────────────────────────────────────────────────────

    private function handleInboxEcho(
        SocialAccount $account,
        array         $messagingEvent,
        ?string       $senderId,
        ?string       $recipientId,
        ?string       $text,
        ?string       $mid,
        ?int          $timestamp,
    ): void {

        if (!$text || !$mid) {
            Log::info('[Facebook][Message] Echo non-texte ignoré', ['mid' => $mid]);
            return;
        }

        // ✅ Dans un echo inbox : sender = page, recipient = vrai user
        $realUserId = $recipientId;

        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'facebook',
            'external_user_id'  => $realUserId,
            'context_type'      => 'inbox',
        ])->whereNull('context_id')->first();

        if (!$conversation) {
            Log::warning('[Facebook][Message] Echo IA sans conversation inbox trouvée', [
                'real_user_id' => $realUserId,
                'mid'          => $mid,
            ]);
            return;
        }

        $publishedAt = $timestamp
            ? \Carbon\Carbon::createFromTimestampMs($timestamp)
            : now();

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'facebook',
                'external_message_id' => $mid,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => [
                    'raw'          => $messagingEvent,
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                    'mid'          => $mid,
                    'is_echo'      => true,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Facebook][Message] Echo IA inbox enregistré comme message sortant', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'real_user_id'    => $realUserId,
                'mid'             => $mid,
            ]);
        }

        $conversation->update(['last_message_at' => $publishedAt]);
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    private function resolveAttachmentType(array $msg): string
    {
        if (!empty($msg['attachments'])) {
            return $msg['attachments'][0]['type'] ?? 'attachment';
        }
        if (!empty($msg['sticker_id'])) {
            return 'sticker';
        }
        return 'unknown';
    }
}
