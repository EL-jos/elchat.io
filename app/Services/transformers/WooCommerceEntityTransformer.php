<?php

namespace App\Services\transformers;

use App\Interfaces\EntityTransformer;

class WooCommerceEntityTransformer implements EntityTransformer
{
    public function supports(array $chunk): bool
    {
        return ($chunk['source_type'] ?? null) === 'woocommerce';
    }

    public function transform(array $chunk): ?array
    {
        $raw = $chunk['metadata']['raw'] ?? null;

        if (!$raw) return null;

        return [
            'id' => $chunk['id'],
            'type' => 'product',
            'title' => $raw['product_name'] ?? null,
            'description' => $raw['description'] ?? null, // 🔥 IMPORTANT
            'url' => $raw['product_url'] ?? null,
            'image' => $raw['image_url'] ?? null,
            'price' => $raw['price'] ?? null,
        ];
    }
}
