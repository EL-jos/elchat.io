<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Page;
use App\Services\CrawlService;
use App\Services\IndexService;
use App\Services\vector\VectorIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecrawlSinglePageJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        protected string $pageId
    ) {}

    public function handle(
        CrawlService $crawlService,
        IndexService $indexService,
        VectorIndexService $vectorIndexService
    ) {

        $oldPage = Page::findOrFail($this->pageId);
        $site = $oldPage->site;
        $url = $oldPage->url;

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ Supprimer anciens chunks
            |--------------------------------------------------------------------------
            */
            $chunkIds = Chunk::where('page_id', $oldPage->id)
                ->pluck('id')
                ->toArray();

            if (!empty($chunkIds)) {
                $vectorIndexService->deleteChunksBatch($chunkIds);
                Chunk::whereIn('id', $chunkIds)->delete();
            }

            /*
            |--------------------------------------------------------------------------
            | 2️⃣ Supprimer ancienne page
            |--------------------------------------------------------------------------
            */
            $oldPage->delete();

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Re-crawl (même moteur que sitemap / crawl URL)
        |--------------------------------------------------------------------------
        */
        $newPage = $crawlService->crawlSinglePage(
            $site,
            $url,
            0,
            null
        );

        if (!$newPage) {
            Log::warning("Recrawl failed — page not recreated", [
                'url' => $url,
                'site_id' => $site->id
            ]);
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ Réindexation normale
        |--------------------------------------------------------------------------
        */
        $indexService->indexPage($newPage, [
            'source'  => 'recrawl',
            'site_id' => $site->id,
        ]);

        Log::info("Recrawl completed", [
            'site_id' => $site->id,
            'url' => $url,
            'page_id' => $newPage->id,
        ]);

        // 🔄 On passe en processing immédiatement
        $site->update([
            'status' => 'ready'
        ]);
    }
}
