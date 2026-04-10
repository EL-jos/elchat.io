<?php
namespace App\Services;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\FieldSynonym;
use App\Models\Page;
use App\Services\ia\EmbeddingService;
use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorIndexService;
use App\Traits\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexService
{

    use TextNormalizer;
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected VectorIndexService $vectorIndexService,
        protected LexicalIndexService  $lexicalIndexService,
    ) {}
    public function indexPage(Page $page, array $context = []): void
    {
        // 🛑 Idempotence  : ne jamais réindexer
        if ($page->is_indexed) {
            return;
        }

        DB::beginTransaction();

        try {
            $chunks = $this->buildChunks($page);

            $this->lexicalIndexService->ensureIndex($page->site_id);

            foreach ($chunks as $i => $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];


                if ($this->chunkAlreadyExists($page, $textChunk)) continue;
                if (trim($textChunk) === '') continue;
                //$embedding = $this->embeddingService->getEmbedding($textChunk);
                if (!($chunkData['no_embedding'] ?? false)) {
                    $embedding = $this->embeddingService->getEmbedding($textChunk);
                } else {
                    $embedding = null;
                }

                $hash = hash('sha256', $textChunk . ($page->id ?? ''));

                $chunk = Chunk::create([
                    'page_id'     => $page->id,
                    'site_id'     => $page->site_id,
                    'source_type' => $context['source'] ?? $page->source ?? 'unknown',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'document_id' => null,
                    'hash'        => $hash,
                ]);

                $this->lexicalIndexService->upsertChunk([
                    'id' => (string) $chunk->id,
                    'text' => $chunk->text,
                    'content' => $chunk->text, // 🔥 duplication stratégique
                    'title' => $page->title ?? null,
                    'url' => $page->url ?? null,
                    'site_id' => $chunk->site_id,
                    'source_type' => $chunk->source_type,
                    'priority' => $chunk->priority,
                ], $chunk->site_id);

                if ($embedding) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $page->site->id,
                        chunkId: $chunk->id,
                        embedding: $embedding,
                        payload: [
                            'site_id'  => $chunk->site_id,
                            'page_id'  => $chunk->page_id,
                            'priority' => $priority,
                            'source'  => $page->source,
                            'title'   => $page->title,
                            'url'     => $page->url,
                        ],
                        collection: "chunks_{$chunk->site_id}"
                    );
                }

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
            //$sectionTitle = trim($section['title'] ?? '');
            $sectionTitle = trim(
                $section['title']
                ?? $section['h2']
                ?? ''
            );
            $content      = trim($section['content'] ?? '');

            //if (mb_strlen($content) < 100) continue; //Elle empêche l’indexation des petites sections.

            $contextHeader = implode("\n", array_filter([
                $page->title ? "Page: {$page->title}" : null,
                $section['h1'] ?? null, // 🔥 AJOUT CRITIQUE
                $sectionTitle ? "Section: {$sectionTitle}" : null,
                "URL: {$page->url}",
            ]));

            $fullText = $contextHeader . "\n\n" . $content;

            foreach ($this->chunkBySentences($fullText, 800, 120) as $chunkText) {
                $weight = $section['weight'] ?? 1;

                //$base = $this->computePriority($sectionIndex, $sectionTitle);

                $semantic = $this->computeSemanticPriority($chunkText, 'section');

                $priority = (int) round($semantic * (0.7 + 0.3 * $weight));

                $chunks[] = [
                    'text' => $chunkText,
                    'priority' => $priority,
                    // 🔥 IMPORTANT
                    'no_embedding' => $section['no_embedding'] ?? false,
                    'type' => $sectionTitle === 'Overview' ? 'overview' : 'section',
                ];
            }
        }

        return $chunks;
    }
    protected function buildChunksFromRawText(Page $page): array
    {
        $text = trim($page->content);
        //if (mb_strlen($text) < 300) return []; //Sinon les petites pages ne sont jamais indexées.

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
        if (!$sentences || count($sentences) === 0) {
            return [trim($text)];
        }
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

        if (mb_strlen($buffer) > 0) {
            $chunks[] = trim($buffer);
        }

        return $chunks;
    }
    protected function computePriority(int $sectionIndex, ?string $title): int
    {
        Log::info("SectionIndex dans computePriority", [
            "sectionIndex" => $sectionIndex,
            "title"       => $title,
        ]);
        $score = 50; // valeur neutre par défaut

        if ($sectionIndex === 0) $score += 20;
        if ($title) $score += 10;
        if (preg_match('/faq|question|help|guide/i', $title ?? '')) {
            $score += 20;
        }

        return $score;
    }
    protected function chunkAlreadyExists(Page $page, string $text): bool
    {
        //$hash = sha1($text);
        $hash = hash('sha256', $text . ($page->id ?? ''));

        return Chunk::where('site_id', $page->site_id)
            ->where('hash', $hash)
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

        /*if (mb_strlen($text) < 50) {
            Log::info("Document trop court, ignoré: {$document->path}");
            return;
        }*/

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

            $this->lexicalIndexService->ensureIndex($siteId);

            // 3️⃣ Insertion et vectorisation
            foreach ($chunks as $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];
                $metadata  = $chunkData['metadata'];

                if ($this->chunkAlreadyExistsForDocument($document, $textChunk)) {
                    continue;
                }

                try {
                    if (trim($textChunk) === '') continue;
                    //$embedding = $this->embeddingService->getEmbedding($textChunk);
                    if (!($chunkData['no_embedding'] ?? false)) {
                        $embedding = $this->embeddingService->getEmbedding($textChunk);
                    } else {
                        $embedding = null;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Embedding échoué pour document {$document->id}", [
                        'chunk_preview' => mb_substr($textChunk, 0, 100),
                        'error' => $e->getMessage(),
                    ]);
                    continue; // on skip ce chunk mais pas tout le document
                }

                $hash = hash('sha256', $textChunk);

                $chunk = Chunk::create([
                    'page_id'     => null,
                    'site_id'     => $siteId,
                    'document_id' => $document->id,
                    'source_type' => 'document',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'metadata'    => $metadata,
                    'hash'        => $hash
                ]);

                $this->lexicalIndexService->upsertChunk([
                    'id' => (string) $chunk->id,
                    'site_id' => $chunk->site_id,
                    'document_id' => $chunk->document_id,
                    'source_type' => $chunk->source_type,
                    'text' => $chunk->text,
                    'content' => $chunk->text, // 🔥 duplication stratégique
                    'priority' => $chunk->priority,
                    'metadata' => $chunk->metadata,
                ], $chunk->site_id);

                if ($embedding) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $siteId,
                        chunkId: $chunk->id,
                        embedding: $embedding,
                        payload: array_merge([
                            'site_id'     => $chunk->site_id,
                            'page_id'     => $chunk->page_id,
                            'document_id' => $chunk->document_id,
                            'source_type' => $chunk->source_type,
                            'priority'    => $chunk->priority,
                            'metadata'    => $metadata
                        ]),
                        collection: "chunks_{$chunk->site_id}"
                    );
                }

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
        if (!$sentences || count($sentences) === 0) {
            return [[
                'text' => trim($text),
                'priority' => 50,
                'metadata' => [
                    'document_name' => $documentName,
                    'document_id'   => $documentId,
                    'site_id'       => $siteId,
                    'chunk_index'   => 0,
                ],
            ]];
        }
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

        if (mb_strlen(trim($buffer)) > 0) {
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
    protected function chunkAlreadyExistsForDocument(Document $document, string $text, ?string $productId = null): bool
    {
        //$hash = sha1($text);
        //$hash = sha1($text . ($identifier ?? ''));

        $hash = hash('sha256', $text . ($productId ?? ''));

        $query = Chunk::where('site_id', $document->documentable->id)
            ->where('hash', $hash);

        if ($productId !== null) {
            //$query->where('metadata->identifier', $identifier);
            $query->where('source_type', 'woocommerce')
            ->where('metadata->product_id', $productId);
        }

        return $query->exists();
    }
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
        $productId = hash('sha256', $identifier);

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
            $this->vectorIndexService->deleteChunksBatch($chunkIds, collection: "chunks_{$document->documentable->id}");
            Chunk::whereIn('id', $chunkIds)->delete();

            Log::info('Ancien produit supprimé pour nouveau document', [
                'document_id' => $document->id,
                'identifier' => $identifier,
                'chunks_deleted' => count($chunkIds),
            ]);
        }

        DB::beginTransaction();



        try {
            //=====================================
            //=         PRODUIT SECTION           =
            //=====================================
            // 🔹 NEW: produit → sections
            $structured = $this->productToSections($product);
            // 🔹 Fake Page pour réutiliser ton pipeline existant
            $page = new Page([
                'id' => (string) Str::uuid(),
                'site_id' => $document->documentable->id,
                'title' => $product['product_name'] ?? 'Produit',
                'url' => $product['product_url'] ?? null,
                'content' => json_encode($structured),
                'source' => 'product',
            ]);
            // 🔹 Générer les chunks via ton moteur existant
            $chunks = $this->buildChunksFromSections($page, $structured['sections']);

            $splitValues = fn(string $value): array => array_filter(array_map('trim', preg_split('/[,;|]/', trim($value))));

            $this->lexicalIndexService->ensureIndex($document->documentable->id);
            // 🔹 Sauvegarde + embeddings
            foreach ($chunks as $chunkData) {
                $textChunk = $chunkData['text'];
                $priority  = $chunkData['priority'];

                if (trim($textChunk) === '') continue;
                if ($this->chunkAlreadyExistsForDocument($document, $textChunk, $productId)) continue;

                try {
                    //$embedding = $this->embeddingService->getEmbedding($textChunk);
                    if (!($chunkData['no_embedding'] ?? false)) {
                        $embedding = $this->embeddingService->getEmbedding($textChunk);
                    } else {
                        $embedding = null;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Embedding échoué (section produit)", [
                        'document_id' => $document->id,
                        'preview' => mb_substr($textChunk, 0, 100),
                    ]);
                    continue;
                }

                $hash = hash('sha256', $textChunk . $productId);
                $chunk = Chunk::create([
                    'document_id' => $document->id,
                    'site_id'     => $document->documentable->id,
                    'source_type' => 'woocommerce',
                    'text'        => $textChunk,
                    'priority'    => $priority,
                    'metadata'    => [
                        'type' => 'section',
                        'identifier' => $identifier,
                        'product_id' => $productId, // 🔥 AJOUT
                        //'overview' => $this->buildProductOverview($product),
                        //'field' => $section['h2'] ?? null,
                    ],
                    'hash' => $hash,
                ]);

                $aliasesMap = [];

                foreach ($product as $field => $value) {
                    if (!$value) continue;

                    foreach ($splitValues($value) as $v) {
                        if ($v === '') continue;

                        foreach ($this->generateStatisticalAliasesLexical($field, $v) as $alias) {
                            $aliasesMap[$alias] = true; // anti-dup direct
                        }
                    }
                }

                $aliases = array_keys($aliasesMap);

                $this->lexicalIndexService->upsertChunk([
                    'id' => (string) $chunk->id,
                    'site_id' => $chunk->site_id,
                    'source_type' => $chunk->source_type,
                    'text' => $chunk->text,
                    'content' => $chunk->text, // 🔥 duplication stratégique
                    'aliases' => $aliases, // 🔥 ici
                    'priority' => $chunk->priority,
                    'metadata' => $chunk->metadata,
                ], $chunk->site_id);

                if ($embedding) {
                    $this->vectorIndexService->upsertChunk(
                        siteId: $chunk->site_id,
                        chunkId: $chunk->id,
                        embedding: $embedding,
                        payload: [
                            'site_id'     => $chunk->site_id,
                            'document_id' => $chunk->document_id,
                            'source_type' => $chunk->source_type,
                            'priority'    => $chunk->priority,
                            'product_id'  => $productId, // 🔥 ICI
                            'metadata' => $chunk->metadata,
                            'has_overview' => true,
                        ],
                        collection: "chunks_{$chunk->site_id}"
                    );
                }

            }

            // 🔹 2️⃣ Chunks granulaires avec alias et synonymes
            //$splitValues = fn(string $value): array => array_filter(array_map('trim', preg_split('/[,;|]/', trim($value))));

            foreach ($product as $field => $value) {
                if (!$value) continue;

                foreach ($splitValues($value) as $v) {
                    if ($v === '') continue;

                    $aliases = $this->generateStatisticalAliases($field, $v);

                    foreach ($aliases as $aliasText) {
                        if (strlen($aliasText) < 3) continue;
                        if (str_word_count($aliasText) === 1 && strlen($aliasText) < 4) continue;
                        if ($this->chunkAlreadyExistsForDocument($document, $aliasText, $productId)) continue;

                        try {
                            if (trim($aliasText) === '') continue;
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

                        $hash = hash('sha256', $aliasText . $productId);

                        $chunk = Chunk::create([
                            'document_id' => $document->id,
                            'site_id'     => $document->documentable->id,
                            'source_type' => 'woocommerce',
                            'text'        => $aliasText,
                            'priority'    => $this->computeSemanticPriority(
                                $aliasText,
                                type: 'alias',
                                field: $field
                            ),
                            'metadata'    => [
                                'type' => 'statistical_alias',
                                'field' => $field,
                                'value' => $v,
                                'identifier' => $identifier,
                                'product_id' => $productId, // 🔥 AJOUT
                                'product_index' => $productIndex,
                            ],
                            'hash' => $hash,
                        ]);

                        if ($chunk) {
                            $this->vectorIndexService->upsertChunk(
                                siteId: $document->documentable->id,
                                chunkId: $chunk->id,
                                embedding: $embedding,
                                payload: [
                                    'site_id'     => $chunk->site_id,
                                    'page_id'     => $chunk->page_id,
                                    'document_id' => $chunk->document_id,
                                    'source_type' => $chunk->source_type,
                                    'priority'    => $chunk->priority,
                                    'product_id'  => $productId, // 🔥 ICI
                                    'metadata' => $chunk->metadata,
                                    'has_overview' => false,
                                ],
                                collection: "chunks_{$chunk->site_id}"
                            );
                        }
                    }
                }
            }

            DB::commit();

            Log::info('Produit indexé avec succès', [
                'document_id'    => $document->id,
                'product_index'  => $productIndex,
                'identifier'     => $identifier,
                'chunks_created' => count($chunks),
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
        //$value = $this->normalizeText($value);
        //$label = ucfirst(str_replace('_', ' ', $label));
        $value = trim($value);

        if ($value === '') return [];

        // Forme complète
        $aliases[] = "{$label}: {$value}";

        // Valeur seule
        //$aliases[] = $value;

        // Tokens individuels
        if (strlen($value) > 3 && str_word_count($value) <= 6) {
            $aliases[] = $value;
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
    protected function productToSections(array $product): array
    {
        $sections = [];
        $identifier = $product['identifier'] ?? $product['product_name'] ?? $product['product_reference'] ?? 'unknown-product';

        $h1 = $product['product_name'] ?? 'Produit';

        foreach ($product as $field => $value) {
            if (!$value) continue;

            $values = is_array($value)
                ? $value
                : array_filter(array_map('trim', preg_split('/[,;|]/', $value)));

            if (empty($values)) continue;

            $label = ucfirst(str_replace('_', ' ', $field));

            $sections[] = [
                'h1' => $h1,
                'h2' => $label,
                'content' => implode(', ', $values),
                'weight' => $this->getFieldWeight($field),
            ];
        }

        $sections[] = [
            'h1' => $h1,
            'h2' => 'Overview',
            'content' => $this->buildProductOverview($product),
            'weight' => 3.5,
            'no_embedding' => true,
        ];

        return [
            'sections' => $sections,
            'type' => 'product',
            'meta' => [
                'identifier' => $identifier,
            ]
        ];
    }
    protected function getFieldWeight(string $field): float
    {
        return match ($field) {
            'product_name' => 3,
            'description' => 2.5,
            'features' => 2,
            'short_description' => 2,
            'product_category' => 1.8,
            'brand' => 1.5,
            'price' => 1.3,
            'discount_price' => 1.3,
            default => 1,
        };
    }
    protected function buildProductOverview(array $product): string
    {
        $parts = [];

        foreach ($product as $key => $value) {
            if (!$value) continue;

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
        }

        return implode('. ', $parts);
    }
    protected function computeSemanticPriority(
        string $text,
        string $type = 'section',
        ?string $field = null
    ): int {
        $score = 50; // base neutre (IMPORTANT)

        // 🔹 Type = signal léger (pas dominant)
        if ($type === 'section') {
            $score += 5;
        } elseif ($type === 'alias') {
            $score -= 5;
        }

        // 🔹 Champ = léger hint (pas ranking global)
        if ($field) {
            $fieldBoost = match ($field) {
                'product_name', 'title' => 8,
                'description' => 5,
                'features' => 4,
                'category' => 3,
                'brand' => 2,
                default => 0
            };

            $score += $fieldBoost;
        }

        // 🔹 Longueur (évite bruit extrême)
        $len = str_word_count($text);

        if ($len < 3) $score -= 10;
        if ($len > 80) $score -= 5;

        // 🔹 Clamp strict
        return max(10, min(90, $score));
    }
    protected function generateStatisticalAliasesLexical(string $label, string $value): array
    {
        $aliases = [];

        //$label = $this->normalizeText($label);
        $label = trim($label);
        $value = trim($value);

        if ($value === '') return [];

        $group = $this->getFieldGroup($label);
        $boost = $this->getFieldBoost($label);

        // 0. fallback
        $aliases[] = "raw|{$label}|{$value}";

        // 1. CORE SIGNAL (structuré)
        $aliases[] = "core|{$group}|{$label}|{$value}|b{$boost}";

        // 2. VALUE ONLY (signal brut)
        if (strlen($value) > 3 && str_word_count($value) <= 6) {
            $aliases[] = "val|{$label}|{$value}";
        }

        // 3. SYNONYMS (cached)
        static $synonymCache = [];

        $synonyms = $synonymCache[$label] ??= FieldSynonym::where('field_key', $label)
            ->pluck('synonym')
            ->toArray();

        foreach (array_slice($synonyms, 0, 5) as $syn) {
            $syn = $this->normalizeText($syn);
            if ($syn === '') continue;
            if (similar_text($syn, $value) > 80) continue; // anti redondance
            $aliases[] = "syn|{$group}|{$label}|{$syn}";
        }

        $tokens = preg_split('/\s+/', strtolower($value));
        foreach ($tokens as $t) {
            if (strlen($t) > 2) {
                $aliases[] = "tok|{$label}|{$t}";
            }
        }

        return $aliases;
    }
    protected function getFieldGroup(string $field): string
    {
        return match ($field) {
            'product_name', 'product_reference', 'product_category', 'description' => 'core',

            'price', 'price_min', 'price_max', 'discount_price', 'currency' => 'pricing',

            'brand', 'tags', 'keywords', 'features' => 'descriptive',

            'stock_status', 'stock_quantity', 'availability', 'colors', 'materials' => 'logistics',

            default => 'meta',
        };
    }
    protected function getFieldBoost(string $field): int
    {
        return match ($field) {
            'product_name' => 10,
            'product_reference' => 9,
            'product_category' => 8,

            'price', 'discount_price' => 7,

            'brand' => 8,
            'keywords' => 7,
            'features' => 6,

            'availability' => 5,

            default => 3,
        };
    }
}
