<?php

namespace App\Jobs\product;

use App\Mappers\ProductFileParser;
use App\Mappers\ProductMapper;
use App\Models\Document;
use App\Models\ProductImport;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProductImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Document $document,
        public $mapping,
        public Site $site
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $productsRaw = ProductFileParser::parse($this->document);

        $products = ProductMapper::map($productsRaw, $this->mapping);

        if (empty($products)) {
            Log::warning("Aucun produit trouvé pour document {$this->document->id}");
            $this->site->update(['status' => 'ready']);
            return;
        }

        $existing = ProductImport::where('document_id', $this->document->id)
            ->where('status', 'processing')
            ->first();

        if ($existing) {
            return;
        }

        $import = ProductImport::create([
            'site_id' => $this->site->id,
            'document_id' => $this->document->id,
            'total_products' => count($products),
            'processed_products' => 0,
            'status' => 'processing',
            'started_at' => now()
        ]);

        // Dispatch des batches
        collect($products)
            ->chunk(100) // 🔥 batch size configurable
            ->each(function ($batch) use ($import) {
                IndexProductBatchJob::dispatch(
                    $batch,
                    $this->document,
                    $import->id
                );
            });

        // Vérification périodique
        CheckProductImportCompletionJob::dispatch($import->id)->delay(now()->addSeconds(30));

    }
}
