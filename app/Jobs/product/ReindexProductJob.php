<?php

namespace App\Jobs\product;

use App\Models\Document;
use App\Models\Site;
use App\Services\product\ProductReindexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReindexProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        public string $siteId,
        public string $documentId,
        public int $productIndex,
        public array $productData
    ) {}

    public function handle(ProductReindexService $productReindexService): void
    {
        Log::info('[JOB REINDEX] Start', [
            'site_id' => $this->siteId,
            'document_id' => $this->documentId,
            'product_index' => $this->productIndex
        ]);

        $document = Document::find($this->documentId);

        if (!$document) {
            throw new \Exception("Document not found");
        }

        $productReindexService->reindexProduct(
            $document,
            $this->productIndex,
            $this->productData
        );

        // ✅ Si on arrive ici = succès
        Site::where('id', $this->siteId)
            ->update(['status' => 'ready']);

        Log::info('[JOB REINDEX] Finished successfully', [
            'site_id' => $this->siteId
        ]);
    }

    /**
     * 🔥 Appelé automatiquement si le job échoue après tous les retries
     */
    public function failed(Throwable $exception): void
    {
        Log::error('[JOB REINDEX] Failed', [
            'site_id' => $this->siteId,
            'error' => $exception->getMessage()
        ]);

        Site::where('id', $this->siteId)
            ->update(['status' => 'error']);
    }
}
