<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Site;
use App\Services\crawl\CrawlService;
use App\Services\IndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManualContentController extends Controller
{
    public function __construct(
        protected IndexService $indexService,
        protected CrawlService $crawlService,
    ) {}

    public function store(Request $request, Site $site)
    {
        $this->authorizeSite($site);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|min:20',
        ]);

        // 🧠 Meta enrichie
        $meta = [
            'title' => $validated['title'],
            'description' => null,
            'keywords' => [],
            'published_at' => now()->toDateTimeString(),
        ];

        try {
            // 🔥 Nouveau pipeline AI (avec fallback interne)
            $processed = $this->crawlService->processManualContentWithAI(
                $site,
                $validated['content'],
                $meta,
                null
            );

        } catch (Throwable $e) {
            // ⚠️ Sécurité ultime (ne jamais casser l’expérience)
            Log::error("Manual AI processing failed", [
                'site_id' => $site->id,
                'error' => $e->getMessage()
            ]);

            // fallback classique
            $processed = $this->crawlService->processRawContent(
                $site,
                $validated['content'],
                $meta,
                null
            );
        }

        // 🧱 Création page
        $page = Page::create([
            'site_id' => $site->id,
            'crawl_job_id' => null,
            'source' => 'manual',
            'title' => $validated['title'],
            'url' => null,
            'content' => $processed['content'],
            'plain_text' => $processed['plain_text'],
            'type' => $processed['type'] ?? 'custom',
            'importance' => $processed['importance'] ?? 2.5,
            'is_indexed' => false,
        ]);

        // 🚀 Indexation enrichie (GROS upgrade)
        $this->indexService->indexPage($page, [
            'source' => $page->source,
            'site_id' => $site->id,
            /*'type' => $processed['type'] ?? 'custom',

            // 🔥 clés pour retrieval avancé
            'intents' => $processed['intents'] ?? [],
            'entities' => $processed['entities'] ?? [],*/
        ]);

        return response()->json([
            'message' => 'Manual content indexed successfully',
            'page_id' => $page->id,
        ]);
    }

    private function authorizeSite(Site $site)
    {
        if ($site->account_id !== auth()->user()->ownedAccount->id) {
            abort(403);
        }
    }
}

