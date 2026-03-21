<?php

namespace App\Services\ia;



use Illuminate\Support\Facades\Log;

class EntityExtractor
{
    protected array $transformers;

    public function __construct()
    {
        $this->transformers = collect(config('entities.transformers'))
            ->map(fn($class) => app($class))
            ->toArray();
    }

    public function extract(array $chunks): array
    {
        $entities = [];

        foreach ($chunks as $chunk) {

            foreach ($this->transformers as $transformer) {

                if (!$transformer->supports($chunk)) {
                    continue;
                }

                $entity = $transformer->transform($chunk);

                if ($entity) {
                    $entities[] = $entity;
                }

                break;
            }
        }

        return collect($entities)
            ->unique(fn($e) => $e['url'] ?? $e['title'])
            ->values()
            ->take(4)
            ->toArray();
    }
}
