<?php
namespace App\Services;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\FieldSynonym;
use App\Models\Page;
use App\Services\ia\EmbeddingService;
use App\Services\vector\VectorIndexService;
use App\Traits\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IndexService
{

    use TextNormalizer;
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected VectorIndexService $vectorIndexService
    ) {}

    /**
     * Point d'entrée UNIQUE
     */
    public function indexPage(Page $page, array $context = []): void
    {
        // 🛑 Idempotence  : ne jamais réindexer
        if ($page->is_indexed) {
            return;
        }

        DB::beginTransaction();

        try {
            $chunks = $this->buildChunks($page);

            foreach ($chunks as $i => $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];


                if ($this->chunkAlreadyExists($page, $textChunk)) continue;

                $embedding = $this->embeddingService->getEmbedding($textChunk);

                $chunk = Chunk::create([
                    'page_id'     => $page->id,
                    'site_id'     => $page->site_id,
                    'source_type' => $context['source'] ?? $page->source ?? 'unknown',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'document_id' => null,
                ]);

                $this->vectorIndexService->upsertChunk(
                    $chunk->id,
                    $embedding,
                    [
                        'site_id'  => $chunk->site_id,
                        'page_id'  => $chunk->page_id,
                        'priority' => $priority,
                    ]
                );
            }

            // ✅ Page marquée indexée SEULEMENT si tout est OK
            $page->update(['is_indexed' => true]);

            DB::commit();

            Log::info('Page indexée', [
                'site_id' => $page->site_id,
                'page_id' => $page->id,
                'chunks' => count($chunks),
            ]);


        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Indexation échouée', [
                'page_id' => $page->id,
                'url' => $page->url,
                'error' => $e->getMessage(),
            ]);

            throw $e; // laisse le job gérer le retry
        }
    }

    /**
     * Construction des chunks
     */
    protected function buildChunks(Page $page): array
    {
        // Cas 1 : contenu structuré (JSON depuis CrawlService B)
        $decoded = json_decode($page->content, true);

        if (is_array($decoded)) {
            return $this->buildChunksFromSections($page, $decoded);
        }

        // Cas 2 : contenu brut (fallback robuste)
        return $this->buildChunksFromRawText($page);
    }

    protected function buildChunksFromSections(Page $page, array $sections): array
    {
        $chunks = [];

        foreach ($sections as $sectionIndex => $section) {
            $sectionTitle = trim($section['title'] ?? '');
            $content      = trim($section['content'] ?? '');

            if (mb_strlen($content) < 100) continue;

            $contextHeader = implode("\n", array_filter([
                $page->title ? "Page: {$page->title}" : null,
                $sectionTitle ? "Section: {$sectionTitle}" : null,
                "URL: {$page->url}",
            ]));

            $fullText = $contextHeader . "\n\n" . $content;

            foreach ($this->chunkBySentences($fullText, 800, 120) as $chunkText) {
                $chunks[] = [
                    'text' => $chunkText,
                    'priority' => $this->computePriority($sectionIndex, $sectionTitle),
                ];
            }
        }

        return $chunks;
    }

    protected function buildChunksFromRawText(Page $page): array
    {
        $text = trim($page->content);
        if (mb_strlen($text) < 300) return [];

        $header = implode("\n", array_filter([
            $page->title ? "Page: {$page->title}" : null,
            "URL: {$page->url}",
        ]));

        $chunks = [];
        foreach ($this->chunkBySentences($header . "\n\n" . $text, 800, 120) as $chunkText) {
            $chunks[] = [
                'text' => $chunkText,
                'priority' => 50, // fallback priority neutre
            ];
        }

        return $chunks;
    }

    protected function chunkBySentences(string $text, int $maxChars, int $overlapChars): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $chunks    = [];
        $buffer    = '';

        foreach ($sentences as $sentence) {
            if (mb_strlen($buffer . ' ' . $sentence) <= $maxChars) {
                $buffer .= ' ' . $sentence;
            } else {
                $chunks[] = trim($buffer);
                $buffer = mb_substr($buffer, -$overlapChars) . ' ' . $sentence;
            }
        }

        if (mb_strlen($buffer) > 200) {
            $chunks[] = trim($buffer);
        }

        return $chunks;
    }

    /**
     * Calcul de la priorité d'un chunk
     */
    protected function computePriority(int $sectionIndex, ?string $title): int
    {
        $score = 50; // valeur neutre par défaut

        if ($sectionIndex === 0) $score += 20;
        if ($title) $score += 10;
        if (preg_match('/faq|question|help|guide/i', $title ?? '')) {
            $score += 20;
        }

        return $score;
    }
    /**
     * Déduplication par hash
     */
    protected function chunkAlreadyExists(Page $page, string $text): bool
    {
        $hash = sha1($text);

        return Chunk::where('site_id', $page->site_id)
            ->whereRaw('SHA1(text) = ?', [$hash])
            ->exists();
    }

    /**
     * Découpe avec overlap
     */
    protected function chunkText( string $text, int $chunkSize, float $overlapRatio ): array {
        $words = preg_split('/\s+/', trim($text));
        $words = array_values(array_filter($words));
        $chunks = [];

        $overlap = (int) round($chunkSize * $overlapRatio);
        $step = max(1, $chunkSize - $overlap);

        for ($i = 0; $i < count($words); $i += $step) {
            $chunkWords = array_slice($words, $i, $chunkSize);
            if ($chunkWords) {
                $chunks[] = implode(' ', $chunkWords);
            }
        }

        return $chunks;
    }

    /**
     * Indexe un document (PDF, Word, TXT)
     */
    public function indexDocument(Document $document, array $context = []): void
    {
        $siteId = $document->documentable->site->id ?? null;

        // 1️⃣ Extraction du texte
        $text = $this->extractTextFromDocument($document->path, $document->extension);

        if (mb_strlen($text) < 50) {
            Log::info("Document trop court, ignoré: {$document->path}");
            return;
        }

        DB::beginTransaction();

        try {
            // 2️⃣ Construction des chunks intelligents
            $chunks = $this->chunkBySentencesWithMetadata(
                $text,
                $document->name ?? basename($document->path),
                $document->id,
                $siteId,
                800, // max chars
                120  // overlap
            );

            // 3️⃣ Insertion et vectorisation
            foreach ($chunks as $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];
                $metadata  = $chunkData['metadata'];

                if ($this->chunkAlreadyExistsForDocument($document, $textChunk)) {
                    continue;
                }

                try {
                    $embedding = $this->embeddingService->getEmbedding($textChunk);
                } catch (\Throwable $e) {
                    Log::warning("Embedding échoué pour document {$document->id}", [
                        'chunk_preview' => mb_substr($textChunk, 0, 100),
                        'error' => $e->getMessage(),
                    ]);
                    continue; // on skip ce chunk mais pas tout le document
                }

                $chunk = Chunk::create([
                    'page_id'     => null,
                    'site_id'     => $siteId,
                    'document_id' => $document->id,
                    'source_type' => 'document',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'metadata'    => $metadata,
                ]);

                $this->vectorIndexService->upsertChunk(
                    $chunk->id,
                    $embedding,
                    array_merge([
                        'site_id'     => $chunk->site_id,
                        'page_id'     => $chunk->page_id,
                        'document_id' => $chunk->document_id,
                        'source_type' => $chunk->source_type,
                        'priority'    => $chunk->priority,
                    ], $metadata)
                );
            }

            DB::commit();

            Log::info("Document indexé: {$document->path}", [
                'chunks_count' => count($chunks),
                'document_id'  => $document->id,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Indexation document échouée: {$document->path}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    /**
     * Découpe un texte en chunks intelligents avec metadata et overlap
     */
    protected function chunkBySentencesWithMetadata(
        string $text,
        string $documentName,
        string $documentId,
        ?string $siteId,
        int $maxChars,
        int $overlapChars
    ): array {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));
        $chunks = [];
        $buffer = '';
        $chunkIndex = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            if (mb_strlen($buffer . ' ' . $sentence) <= $maxChars) {
                $buffer .= ' ' . $sentence;
            } else {
                $chunks[] = [
                    'text' => trim($buffer),
                    'priority' => 50 + $chunkIndex, // priorité progressive
                    'metadata' => [
                        'document_name' => $documentName,
                        'document_id'   => $documentId,
                        'site_id'       => $siteId,
                        'chunk_index'   => $chunkIndex,
                    ],
                ];
                $buffer = mb_substr($buffer, -$overlapChars) . ' ' . $sentence;
                $chunkIndex++;
            }
        }

        if (mb_strlen(trim($buffer)) > 50) {
            $chunks[] = [
                'text' => trim($buffer),
                'priority' => 50 + $chunkIndex,
                'metadata' => [
                    'document_name' => $documentName,
                    'document_id'   => $documentId,
                    'site_id'       => $siteId,
                    'chunk_index'   => $chunkIndex,
                ],
            ];
        }

        return $chunks;
    }

    protected function chunkAlreadyExistsForDocument(Document $document, string $text): bool
    {
        $hash = sha1($text);

        return Chunk::where('site_id', $document->documentable->id)
            ->whereRaw('SHA1(text) = ?', [$hash])
            ->exists();
    }
    /**
     * Extraction du texte selon type
     */
    protected function extractTextFromDocument(string $path, string $extension): string
    {
        $fullPath = public_path($path);

        return match($extension) {
            'pdf' => $this->extractTextFromPDF($fullPath),
            'doc', 'docx' => $this->extractTextFromWord($fullPath),
            'txt' => file_get_contents($fullPath),
            default => '',
        };
    }
    protected function extractTextFromPDF(string $fullPath): string
    {
        if (!file_exists($fullPath)) return '';

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);
            return trim($pdf->getText());
        } catch (\Throwable $e) {
            Log::error("Erreur extraction PDF: {$fullPath}", ['error' => $e->getMessage()]);
            return '';
        }
    }
    protected function extractTextFromWord(string $fullPath): string
    {
        if (!file_exists($fullPath)) return '';

        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . ' ';
                    }
                }
            }

            return trim($text);
        } catch (\Throwable $e) {
            Log::error("Erreur extraction Word: {$fullPath}", ['error' => $e->getMessage()]);
            return '';
        }
    }
    protected function extractTextFromTXT(string $fullPath): string
    {
        if (!file_exists($fullPath)) return '';
        return trim(file_get_contents($fullPath));
    }

    /**
     * Indexe un produit standard dans un document
     */
    public function indexStandardProduct(array $product, Document $document, int $priority): void
    {
        $productIndex = $priority + 1;

        $identifier = $product['identifier'] ?? $product['product_name'] ?? $product['product_reference'] ?? 'unknown-product';


        Log::info('Indexation produit démarrée', [
            'document_id'   => $document->id,
            'product_index' => $productIndex,
            'identifier'    => $identifier,
        ]);

        // 🔹 Vérifie si le produit a déjà été indexé avec CE document
        $alreadyIndexedWithDocument = Chunk::where('source_type', 'woocommerce')
            ->where('document_id', $document->id)
            ->where('metadata->identifier', $identifier)
            ->exists();

        if ($alreadyIndexedWithDocument) {
            Log::info("Produit déjà indexé avec ce document, on passe", [
                'document_id' => $document->id,
                'identifier' => $identifier,
            ]);
            return; // NE RIEN FAIRE
        }

        // 🔹 Si c'est un nouveau document et que le produit existe déjà avec un autre document
        $existingChunks = Chunk::where('source_type', 'woocommerce')
            ->where('metadata->identifier', $identifier)
            ->where('document_id', '<>', $document->id)
            ->get();

        if ($existingChunks->isNotEmpty()) {
            $chunkIds = $existingChunks->pluck('id')->all();
            $this->vectorIndexService->deleteChunksBatch($chunkIds);
            Chunk::whereIn('id', $chunkIds)->delete();

            Log::info('Ancien produit supprimé pour nouveau document', [
                'document_id' => $document->id,
                'identifier' => $identifier,
                'chunks_deleted' => count($chunkIds),
            ]);
        }

        DB::beginTransaction();

        try {
            $chunksToCreate = [];

            // 🔹 1️⃣ Chunk global (contexte complet)
            $parts = [];
            foreach ($product as $key => $value) {
                if ($value) {
                    $parts[] = ucfirst(str_replace('_', ' ', $key)) . ": " . $value;
                }
            }

            $globalText = implode(". ", $parts) . ".";

            if (!$this->chunkAlreadyExistsForDocument($document, $globalText)) {
                $chunksToCreate[] = [
                    'text'     => $globalText,
                    'priority' => $productIndex,
                    'metadata' => [
                        'type' => 'global',
                        'identifier' => $identifier,
                        'product_index' => $productIndex,
                        'raw' => $product,
                    ],
                ];
            }

            // 🔹 2️⃣ Chunks granulaires avec alias et synonymes
            $splitValues = fn(string $value): array => array_filter(array_map('trim', preg_split('/[,;|]/', trim($value))));

            foreach ($product as $field => $value) {
                if (!$value) continue;

                foreach ($splitValues($value) as $v) {
                    if ($v === '') continue;

                    $aliases = $this->generateStatisticalAliases(str_replace('_', ' ', $field), $v);

                    foreach ($aliases as $aliasText) {
                        if (strlen($aliasText) < 3) continue;
                        if (str_word_count($aliasText) === 1 && strlen($aliasText) < 4) continue;
                        if ($this->chunkAlreadyExistsForDocument($document, $aliasText)) continue;

                        try {
                            $embedding = $this->embeddingService->getEmbedding($aliasText);
                        } catch (\Throwable $e) {
                            Log::warning("Embedding échoué pour chunk produit", [
                                'document_id' => $document->id,
                                'product_index' => $productIndex,
                                'chunk_preview' => mb_substr($aliasText, 0, 100),
                                'error' => $e->getMessage(),
                            ]);
                            continue;
                        }

                        $chunk = Chunk::create([
                            'document_id' => $document->id,
                            'site_id'     => $document->documentable->id,
                            'source_type' => 'woocommerce',
                            'text'        => $aliasText,
                            'priority'    => $productIndex + 10,
                            'metadata'    => [
                                'type' => 'statistical_alias',
                                'field' => $field,
                                'value' => $v,
                                'identifier' => $identifier,
                                'product_index' => $productIndex,
                            ],
                        ]);

                        if ($chunk) {
                            $this->vectorIndexService->upsertChunk(
                                $chunk->id,
                                $embedding,
                                [
                                    'site_id'     => $chunk->site_id,
                                    'page_id'     => $chunk->page_id,
                                    'document_id' => $chunk->document_id,
                                    'source_type' => $chunk->source_type,
                                    'priority'    => $chunk->priority,
                                ]
                            );
                        }
                    }
                }
            }

            // 🔹 3️⃣ Création des chunks globaux
            foreach ($chunksToCreate as $chunkData) {
                try {
                    $embedding = $this->embeddingService->getEmbedding($chunkData['text']);
                } catch (\Throwable $e) {
                    Log::warning("Embedding échoué pour chunk global produit", [
                        'document_id' => $document->id,
                        'chunk_preview' => mb_substr($chunkData['text'], 0, 100),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $chunk = Chunk::create([
                    'document_id' => $document->id,
                    'site_id'     => $document->documentable->id,
                    'source_type' => 'woocommerce',
                    'text'        => $chunkData['text'],
                    'metadata'    => $chunkData['metadata'],
                    'priority'    => $chunkData['priority'],
                ]);

                if ($chunk) {
                    $this->vectorIndexService->upsertChunk(
                        $chunk->id,
                        $embedding,
                        [
                            'site_id'     => $chunk->site_id,
                            'page_id'     => $chunk->page_id,
                            'document_id' => $chunk->document_id,
                            'source_type' => $chunk->source_type,
                            'priority'    => $chunk->priority,
                        ]
                    );
                }
            }

            DB::commit();

            Log::info('Produit indexé avec succès', [
                'document_id'    => $document->id,
                'product_index'  => $productIndex,
                'identifier'     => $identifier,
                'chunks_created' => count($chunksToCreate),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Indexation produit échouée", [
                'document_id' => $document->id,
                'product_index' => $productIndex,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    /**
     * Génère des alias et synonymes pour un champ produit
     */
    protected function generateStatisticalAliases(string $label, string $value): array
    {
        $aliases = [];
        $label = $this->normalizeText($label);
        $value = $this->normalizeText($value);

        // Forme complète
        $aliases[] = "{$label}: {$value}";

        // Valeur seule
        $aliases[] = $value;

        // Tokens individuels
        foreach (explode(' ', $value) as $token) {
            if (strlen($token) >= 3) $aliases[] = $token;
        }

        // Synonymes depuis FieldSynonym
        $synonyms = FieldSynonym::where('field_key', $label)
            ->pluck('synonym')
            ->toArray();

        if (!empty($synonyms)) {
            shuffle($synonyms);
            $count = min(max(5, rand(5, 7)), count($synonyms));
            $selectedSynonyms = array_slice($synonyms, 0, $count);

            foreach ($selectedSynonyms as $syn) {
                $syn = $this->normalizeText($syn);
                if ($syn !== '' && !in_array($syn, $aliases)) $aliases[] = $syn;
            }
        }

        return array_unique($aliases);
    }

}

