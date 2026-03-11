<?php

namespace App\Services\ia;

use Illuminate\Support\Facades\Log;

class ContextBuilder
{
    public function build(array $rankedChunks): string
    {
        $maxCharacters = 12000; // ≈ 3k tokens
        $context = '';
        $length = 0;

        foreach ($rankedChunks as $chunk) {
            $text = trim($chunk['text']);
            $chunkLength = mb_strlen($text);

            if ($length + $chunkLength > $maxCharacters) {
                // Truncate pour que ça rentre
                $text = mb_substr($text, 0, $maxCharacters - $length);
                $context .= $text . "\n\n---\n\n";
                break;
            }

            $context .= $text . "\n\n---\n\n";
            $length += $chunkLength;

        }

        return trim($context);
    }
}
