<?php
// app/Jobs/CrawlSiteJob.php

namespace App\Jobs\crawl;

use App\Models\CrawlJob;
use App\Models\Site;
use App\Services\CrawlService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Importer le service de crawling
// Importer le service d'indexation

class CrawlSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 0;
    public $tries = 1;
    protected string $siteId;

    public function __construct(string $siteId)
    {
        $this->siteId = $siteId;
    }

    public function handle(CrawlService $crawlService)
    {
        $site = Site::findOrFail($this->siteId);

        // 1️⃣ Préparer toutes les URLs à crawler
        $allUrls = $crawlService->prepareQueue($site);

        if (empty($allUrls)) {
            $site->update(['status' => 'ready']);
            return;
        }

        // 2️⃣ Créer un crawl_job pour chaque URL
        foreach ($allUrls as $item) {
            CrawlJob::create([
                'site_id' => $site->id,
                'page_url' => $item['url'],
                'status' => 'pending',
                'source' => 'crawl'
            ]);
        }

        // 3️⃣ Dispatcher les batches de pages
        $batchSize = 5;
        $crawlJobs = CrawlJob::where('site_id', $site->id)
            ->where('status', 'pending')
            ->get();

        foreach ($crawlJobs->chunk($batchSize) as $chunk) {
            $urlsBatch = $chunk->pluck('page_url')->toArray();
            CrawlPageBatchJob::dispatch($site->id, $urlsBatch);
        }

        $site->update(['status' => 'crawling']);
    }

    public function failed(Throwable $e)
    {
        $site = Site::find($this->siteId);
        if ($site) {
            $site->update(['status' => 'error']);
            Log::error("CrawlSiteJob échoué pour site {$this->siteId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
