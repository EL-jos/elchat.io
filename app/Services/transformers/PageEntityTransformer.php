<?php

namespace App\Services\transformers;

use App\Interfaces\EntityTransformer;

class PageEntityTransformer implements EntityTransformer
{
    public function supports(array $chunk): bool
    {
        return in_array($chunk['source_type'] ?? null, ['crawl', 'sitemap', 'manual']);
    }

    /*public function transform(array $chunk): ?array
    {
        $metadata = $chunk['metadata'] ?? [];
        preg_match('/URL:\s(.+)/', $chunk['text'], $match);
        preg_match('/Page:\s(.+)/', $chunk['text'], $matchPageTitle);

        if ($match[1] && !($match !== "" || $match[1] !== " ")){
            return [
                'id' => $chunk['id'],
                'type' => 'page',
                'title' => $chunk['title'] ?? $matchPageTitle[1] ?? 'Page',
                'url' => $match[1] ?? null,
                'description' => $chunk['text'] ?? null,
            ];
        }
        return [];
    }*/
    public function transform(array $chunk): ?array
    {
        $metadata = $chunk['metadata'] ?? [];

        $text = $chunk['text'] ?? '';

        preg_match('/URL:\s(.+)/', $text, $match);
        preg_match('/Page:\s(.+)/', $text, $matchPageTitle);

        // 👉 On vérifie que l'index existe
        if (!isset($match[1]) || trim($match[1]) === '') {
            return [];
        }

        return [
            'id' => $chunk['id'],
            'type' => 'page',
            'title' => $chunk['title'] ?? ($matchPageTitle[1] ?? 'Page'),
            'url' => trim($match[1]),
            'description' => $text,
        ];
    }
}
