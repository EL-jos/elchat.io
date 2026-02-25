<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Services\ia\ChatService;
use App\Services\MercureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function ask(Request $request, ChatService $chatService, MercureService $mercure)
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
        }

        // Sauvegarder la question
        Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
            'role' => 'user',
            'content' => $data['question'],
        ]);

        Log::info("Avant mercure");

        $topic = "/sites/{$site->id}/conversations/{$conversation->id}";

        $mercure->post($topic, [
            'type' => 'user_message',
            'conversation_id' => $conversation->id,
            'content' => $data['question'],
            'created_at' => now()->toISOString(),
        ]);


        // Générer la réponse (🧠 avec mémoire)
        $answer = $chatService->answer(
            site: $site,
            question: $data['question'],
            conversation: $conversation
        );

        // Sauvegarder la réponse
        Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => auth()->id(),
            'role' => 'bot',
            'content' => $answer,
        ]);

        $mercure->post($topic, [
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
