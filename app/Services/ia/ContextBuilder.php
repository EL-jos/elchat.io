<?php

namespace App\Services\ia;

use Illuminate\Support\Facades\Log;

class ContextBuilder
{
    public function build(array $rankedChunks): string
    {
        if (empty($rankedChunks)) {
            return '';
        }

        $maxCharacters = 8000;
        $context = '';
        $length = 0;
        $docIndex = 1;

        foreach ($rankedChunks as $chunk) {

            $text = trim($chunk['text'] ?? '');

            if (!$text) {
                continue;
            }

            // Nettoyage du texte
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = trim($text);

            $rawType = $chunk['source_type'] ?? 'unknown';

            $typeMap = [
                'manual'      => 'internal_knowledge',
                'woocommerce' => 'product',
                'page'        => 'webpage',
                'crawl'       => 'webpage',
                'import'      => 'webpage',
                'document'    => 'document',
            ];

            $sourceType = $typeMap[$rawType] ?? 'document';
            $priority = $chunk['priority'] ?? 'normal';
            // 🔥 Score de pertinence
            $score = round($chunk['final_score'] ?? 0, 3);
            // 🔥 Importance label
            $importance = $score > 0.8 ? 'HIGH' : ($score > 0.6 ? 'MEDIUM' : 'LOW');


            $metadata = $chunk['metadata'] ?? [];

            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }

            if (!is_array($metadata)) {
                $metadata = [];
            }

            if (!is_array($metadata)) {
                $metadata = [];
            }

            $title = $metadata['title'] ?? null;
            $url = $metadata['url'] ?? null;

            $header = "DOCUMENT {$docIndex}\n";
            $header .= "TYPE: {$sourceType}\n";
            $header .= "RELEVANCE: {$score}\n";
            $header .= "IMPORTANCE: {$importance}\n";

            if ($title) {
                $header .= "TITLE: {$title}\n";
            }

            if ($url) {
                $header .= "URL: {$url}\n";
            }

            if ($sourceType === 'product') {
                $header .= "CONTENT_TYPE: PRODUCT_INFO\n";
            }

            $header .= "PRIORITY: {$priority}\n\n";

            $block = $header . $text . "\n\n---\n\n";

            $blockLength = mb_strlen($block);

            if ($length + $blockLength > $maxCharacters) {

                $remaining = $maxCharacters - $length;

                if ($remaining <= 0) {
                    break;
                }

                $text = mb_substr($text, 0, $remaining);
                $text = preg_replace('/\s+\S*$/u', '', $text);

                $context .= $header . $text . "\n\n---\n\n";
                break;
            }

            $context .= $block;

            $length += $blockLength;
            $docIndex++;
        }

        return trim($context);
    }
}
