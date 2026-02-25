<?php

namespace App\Jobs;

use App\Models\Chunk;
use App\Models\Document;
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
use PhpOffice\PhpSpreadsheet\IOFactory;

class PageImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Document $document;
    protected array $mapping;
    protected Site $site;
    public $timeout = 300;

    public function __construct(Document $document, array $mapping, Site $site)
    {
        $this->document = $document;
        $this->mapping = $mapping;
        $this->site = $site;
    }

    public function handle(CrawlService $crawlService, IndexService $indexService, VectorIndexService $vectorIndexService)
    {
        Log::info("🚀 PageImportJob démarré pour le site {$this->site->id}");
        $this->site->update(['status' => 'indexing']); // status début job

        try {
            $rows = $this->parseFile($this->document->path); // parse CSV/XLSX

            $batchSize = 50;
            $chunks = array_chunk($rows, $batchSize);

            foreach ($chunks as $batchIndex => $batchRows) {
                Log::info("📦 Batch {$batchIndex} traitement démarré pour site {$this->site->id}");

                foreach ($batchRows as $row) {
                    $pageData = $this->mapRow($row);

                    if (empty($pageData['content']) && empty($pageData['url'])) {
                        // ❌ rien à faire
                        continue;
                    }

                    $page = Page::updateOrCreate(
                        [
                            'site_id' => $this->site->id,
                            'url'     => $pageData['url'] ?? null,
                        ],
                        [
                            'title'   => $pageData['title'] ?? null,
                            'content' => $pageData['content'] ?? null,
                            'source'  => 'import',
                        ]
                    );

                    Log::info("📄 Page préparée: {$page->title} | URL: {$page->url}");

                    // Crawl si content vide et url présente
                    if (empty($pageData['content']) && !empty($pageData['url'])) {
                        $crawledPage = $crawlService->crawlSinglePage(
                            $this->site,
                            $pageData['url'],
                            0,
                            null
                        );

                        if ($crawledPage) {
                            $page->update(['content' => $crawledPage->content]);
                            Log::info("🕸️ Crawl réussi pour URL: {$page->url}");
                        } else {
                            Log::warning("⚠️ Crawl échoué pour URL: {$page->url}");
                            continue;
                        }
                    }

                    // Indexation si content présent
                    if (!empty($page->content)) {

                        // 🔹 Supprimer les anciens chunks si existants
                        $existingChunks = Chunk::where('page_id', $page->id)->pluck('id')->all();
                        if (!empty($existingChunks)) {
                            $vectorIndexService->deleteChunksBatch($existingChunks);
                            Chunk::whereIn('id', $existingChunks)->delete();
                            Log::info("♻️ Chunks existants supprimés pour la page: {$page->title}");
                        }

                        $indexService->indexPage($page, ['source' => 'import']);
                        Log::info("✅ Page indexée: {$page->title}");
                    }
                }

                Log::info("📦 Batch {$batchIndex} terminé pour site {$this->site->id}");
            }

            $this->site->update(['status' => 'ready']); // status fin job
            Log::info("🎉 PageImportJob terminé avec succès pour site {$this->site->id}");

        } catch (\Throwable $e) {
            $this->site->update(['status' => 'error']);
            Log::error("❌ PageImportJob échoué pour site {$this->site->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    protected function parseFile(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $rows = [];

        if (in_array($extension, ['xls','xlsx'])) {
            $spreadsheet = IOFactory::load(public_path($path));

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetRows = $sheet->toArray(null, true, true, true);
                if (count($sheetRows) < 2) continue;

                $headers = array_map(fn($h) => trim((string)$h), array_shift($sheetRows));

                foreach ($sheetRows as $row) {
                    $row = array_values($row);
                    if (count($row) !== count($headers)) continue; // ✅ ignore ligne invalide
                    $rows[] = array_combine($headers, $row);
                }
            }
        } else { // csv
            if (($handle = fopen(public_path($path), 'r')) !== false) {
                $headers = fgetcsv($handle, 0, ',');
                $headers = array_map('trim', $headers);

                while (($data = fgetcsv($handle, 0, ',')) !== false) {
                    if (count($data) !== count($headers)) continue; // ✅ ignore ligne invalide
                    $rows[] = array_combine($headers, $data);
                }

                fclose($handle);
            }
        }

        return $rows;
    }

    protected function mapRow(array $row): array
    {
        return [
            'title'        => $row[$this->mapping['title']] ?? null,
            'content'      => $row[$this->mapping['content']] ?? null,
            'url'          => $row[$this->mapping['url']] ?? null,
            'categories'   => $row[$this->mapping['categories']] ?? null,
            'tags'         => $row[$this->mapping['tags']] ?? null,
            'seo_keywords' => $row[$this->mapping['seo_keywords']] ?? null,
            'seo_description' => $row[$this->mapping['seo_description']] ?? null,
        ];
    }
}
