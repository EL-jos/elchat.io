<?php

namespace App\Traits;

trait TextNormalizer
{
    protected function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        // Supprimer accents
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        // Supprimer caractères spéciaux
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

        // Espaces multiples
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
