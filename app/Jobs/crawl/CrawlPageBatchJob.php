<?php

namespace App\Jobs\crawl;

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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// CrawlPageBatchJob.php
class CrawlPageBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    protected string $siteId;
    protected array $urls; // tableau d'URLs

    public function __construct(string $siteId, array $urls)
    {
        $this->siteId = $siteId;
        $this->urls = $urls;
    }

    public function handle(CrawlService $crawlService, IndexService $indexService, VectorIndexService $vectorIndexService)
    {
        $site = Site::findOrFail($this->siteId);

        foreach ($this->urls as $url) {
            $crawlJob = CrawlJob::where('site_id', $site->id)
                ->where('page_url', $url)
                ->first();

            if (!$crawlJob) continue;

            if ($crawlService->isExcluded($crawlJob->page_url, $site)) {
                $crawlJob->update(['status' => 'done']);
                continue;
            }

            $crawlJob->update(['status' => 'processing']);

            try {
                // 🔥 RECRAWL SAFE — suppression ancienne page + chunks
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

                $page = $crawlService->crawlSinglePage($site, $url, 0, $crawlJob->id);

                if ($page) {
                    // Index uniquement les pages
                    $indexService->indexPage($page, [
                        'source' => 'crawl',
                        'site_id' => $site->id,
                    ]);
                }

                $crawlJob->update(['status' => 'done']);
            } catch (\Throwable $e) {
                $crawlJob->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
                Log::error("Erreur crawl page {$url}", ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        // Vérifier si le site est terminé
        CheckCrawlCompletionJob::dispatch($site->id);

        Log::info("Batch dispatché pour site {$this->siteId}, pages: " . count($this->urls));
    }

    public function failed(Throwable $e)
    {
        Log::error("CrawlPageBatchJob échoué pour site {$this->siteId}", [
            'error' => $e->getMessage(),
            'urls' => $this->urls,
        ]);
    }
}


