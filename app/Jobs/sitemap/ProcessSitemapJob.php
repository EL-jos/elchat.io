<?php

namespace App\Jobs\sitemap;

use App\Models\CrawlJob;
use App\Models\Document;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessSitemapJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $siteId,
        public string $sitemapDocumentId
    ) {}

    public function handle()
    {
        $site = Site::findOrFail($this->siteId);
        $document = Document::findOrFail($this->sitemapDocumentId);

        $xml = simplexml_load_file(public_path($document->path));

        if (!$xml || !isset($xml->url)) {
            Log::warning("Sitemap vide ou invalide: {$document->path}");
            $this->finishIfNoJobs($site);
            return;
        }

        $include = $site->include_pages ?? [];
        $exclude = $site->exclude_pages ?? [];

        $created = 0;

        foreach ($xml->url as $node) {
            $url = (string) $node->loc;

            if ($include && !$this->matches($url, $include)) continue;
            if ($exclude && $this->matches($url, $exclude)) continue;

            $job = CrawlJob::firstOrCreate([
                'site_id' => $site->id,
                'page_url' => $url,
            ], [
                'status' => 'pending',
                'source' => 'sitemap',
            ]);

            if ($job->wasRecentlyCreated) {
                $created++;
            }
        }

        if ($created === 0) {
            $this->finishIfNoJobs($site);
            return;
        }

        $batchSize = 5;

        CrawlJob::where('site_id', $site->id)
            ->where('source', 'sitemap')
            ->where('status', 'pending')
            ->get()
            ->chunk($batchSize)
            ->each(function ($chunk) use ($site) {
                SitemapPageBatchJob::dispatch(
                    siteId: $site->id,
                    crawlJobIds: $chunk->pluck('id')->toArray()
                );
            });
    }

    private function finishIfNoJobs(Site $site): void
    {
        $site->update(['status' => 'ready']);
    }

    private function matches(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $url)) return true;
        }
        return false;
    }
}


