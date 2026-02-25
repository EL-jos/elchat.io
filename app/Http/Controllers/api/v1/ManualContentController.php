<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Site;
use App\Services\IndexService;
use Illuminate\Http\Request;

class ManualContentController extends Controller
{
    public function store(Request $request, Site $site)
    {

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|min:20',
        ]);

        $page = Page::create([
            'site_id' => $site->id,
            'crawl_job_id' => null,
            'source' => 'manual',
            'title' => $validated['title'],
            'url' => null,
            'content' => $validated['content'],
        ]);

        app(IndexService::class)->indexPage($page, [
            'source' => $page->source,
            'site_id' => $site->id,
        ]);

        return response()->json([
            'message' => 'Manual content indexed successfully',
            'page_id' => $page->id
        ]);
    }

    private function authorizeSite(Site $site)
    {
        if ($site->account_id !== auth()->user()->ownedAccount->id) {
            abort(403);
        }
    }
}

