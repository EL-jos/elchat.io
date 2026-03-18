<?php

namespace App\Services\ia;



use Illuminate\Support\Facades\Log;

class EntityExtractor
{
    public function extract(array $chunks): array
    {
        return collect($chunks)
            ->map(function ($chunk) {

                /*Log::info("ENTITY EXTRATOR", [
                    "chunk" => $chunk
                ]);*/

                $source = $chunk['source_type'] ?? null;
                $metadata = $chunk['metadata'] ?? [];

                // 🛒 PRODUIT
                if ($source === 'woocommerce' && isset($metadata['raw'])) {

                    $p = $metadata['raw'];

                    return [
                        'id' => $chunk['id'],
                        'type' => 'product',
                        'title' => $p['product_name'] ?? null,
                        'url' => $p['product_url'] ?? null,     // ✅ FIX
                        'image' => $p['image_url'] ?? null,     // ✅ FIX
                        'price' => $p['price'] ?? null,
                    ];
                }

                // 🌐 PAGE (via texte)
                if (in_array($source, ['crawl', 'sitemap', 'manual'])) {

                    preg_match('/URL:\s(.+)/', $chunk['text'], $match);
                    preg_match('/Page:\s(.+)/', $chunk['text'], $matchPageTitle);

                    return [
                        'id' => $chunk['id'],
                        'type' => 'page',
                        'title' => $chunk['title'] ?? $matchPageTitle[1] ?? 'Page',
                        'url' => $match[1] ?? null,
                    ];
                }

                // 📄 DOCUMENT
                if ($source === 'document') {

                    return [
                        'id' => $chunk['id'],
                        'type' => 'document',
                        'title' => $metadata['document_name'] ?? null,
                        'url' => '/document/' . ($metadata['document_id'] ?? ''),
                    ];
                }

                return null;
            })
            ->filter()
            ->unique(fn($e) => $e['url'] ?? $e['title'])
            ->values()
            ->take(4)
            ->toArray();
    }
}
