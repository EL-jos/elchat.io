<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Jobs\product\ReindexProductJob;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Site;
use App\Services\product\ProductReindexService;
use Illuminate\Http\Request;

class ChunkController extends Controller
{
    public function __construct(protected ProductReindexService $productReindexService) {}

    public function indexProducts(Request $request, string $siteId)
    {
        $page = (int)$request->get('page', 1);
        $perPage = (int)$request->get('per_page', 20);
        $search = $request->get('search');

        $paginator = $this->productReindexService->listProducts($siteId, $page, $perPage, $search);

        return response()->json($paginator);
    }

    public function reindexProduct(
        Request $request,
        string $siteId,
        string $documentId,
        int $productIndex
    ) {
        // 1️⃣ Vérifier que le document existe
        $document = Document::findOrFail($documentId);

        // 2️⃣ Vérifier que le document appartient bien au site
        $belongsToSite = Chunk::where('document_id', $documentId)
            ->where('site_id', $siteId)
            ->exists();

        if (!$belongsToSite) {
            return response()->json([
                'success' => false,
                'message' => 'Document does not belong to this site.'
            ], 403);
        }

        // 3️⃣ Récupérer les données produit envoyées par Angular
        $productData = $request->input('fields');

        if (empty($productData)) {
            return response()->json([
                'success' => false,
                'message' => 'Product data is required.'
            ], 422);
        }

        // 🔥 DISPATCH JOB
        ReindexProductJob::dispatch(
            $siteId,
            $document->id,
            $productIndex,
            $productData
        );

        $site = Site::findOrFail($siteId);
        $site->update(['status' => 'indexing']);

        return response()->json([
            'status' => 'queued',
            'message' => 'Reindexation en cours'
        ], 202);
    }
}
