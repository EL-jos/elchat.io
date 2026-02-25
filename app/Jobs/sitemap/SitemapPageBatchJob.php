<?php

namespace App\Jobs\sitemap;

use App\Jobs\crawl\CheckCrawlCompletionJob;
use App\Models\Chunk;
use App\Models\CrawlJob;
use App\Models\Page;
use App\Models\Site;
use App\Services\CrawlService;
use App\Services\IndexService;
use App\Services\vector\VectorIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SitemapPageBatchJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $siteId,
        public array $crawlJobIds
    ) {}

    public function handle(
        CrawlService $crawlService,
        IndexService $indexService,
        VectorIndexService $vectorIndexService
    ) {
        $site = Site::findOrFail($this->siteId);

        $jobs = CrawlJob::whereIn('id', $this->crawlJobIds)->get();

        foreach ($jobs as $crawlJob) {

            if ($crawlService->isExcluded($crawlJob->page_url, $site)) {
                $crawlJob->update(['status' => 'done']);
                continue;
            }


            if ($crawlJob->status !== 'pending') {
                continue;
            }

            $crawlJob->update(['status' => 'processing']);

            try {

                $existingPage = Page::where('site_id', $site->id)
                    ->where('url', $crawlJob->page_url)
                    ->first();

                if ($existingPage) {

                    $chunkIds = Chunk::where('page_id', $existingPage->id)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($chunkIds)) {
                        $vectorIndexService->deleteChunksBatch($chunkIds);
                        Chunk::whereIn('id', $chunkIds)->delete();
                    }

                    $existingPage->delete();
                }

                // 🔥 MÊME moteur que le crawl URL
                $page = $crawlService->crawlSinglePage(
                    $site,
                    $crawlJob->page_url,
                    0,
                    $crawlJob->id
                );

                if ($page) {
                    $indexService->indexPage($page, [
                        'source' => 'sitemap',
                        'site_id' => $site->id,
                    ]);
                }

                $crawlJob->update(['status' => 'done']);

            } catch (\Throwable $e) {
                $crawlJob->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);

                Log::error("Erreur crawl sitemap {$crawlJob->page_url}", [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ✅ même sortie que crawl URL
        CheckCrawlCompletionJob::dispatch($site->id);
    }
}


