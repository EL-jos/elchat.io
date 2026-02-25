<?php

namespace App\Jobs\product;

use App\Models\Document;
use App\Models\ProductImport;
use App\Services\IndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IndexProductBatchJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $products,
        public Document $document,
        public string $importId
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(IndexService $indexService)
    {
        foreach ($this->products as $index => $product) {
            $indexService->indexStandardProduct(
                $product,
                $this->document,
                $index
            );
        }

        ProductImport::where('id', $this->importId)
            ->increment('processed_products', count($this->products));
    }
}
