<?php

namespace App\Services\ia;

use App\Models\AIRole;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class PromptBuilder
{
    /**
     * Construit le payload complet pour l'IA
     */
    public function build(
        Site $site,
        string $question,
        string $context,
        array $history = [],
        ?Conversation $conversation = null // Nouveau paramètre
    ): array {
        return [
            'system' => $this->buildSystemPrompt($site, $conversation),
            'messages' => array_merge(
                $this->buildHistory($history),
                [
                    [
                        'role' => 'user',
                        'content' => $this->buildUserPrompt($question, $context),
                    ]
                ]
            ),
        ];
    }
    /**
     * Prompt système complet
     */
    protected function buildSystemPrompt(Site $site, ?Conversation $conversation = null): string
    {
        $blocks = [];

        $companyName = $site->name
            ?? parse_url($site->url, PHP_URL_HOST)
            ?? 'notre entreprise';

        $botLanguage = $site->settings->bot_language ?? 'fr';

        // 1️⃣ Règles fondamentales (immutables)
        $basePrompt = $this->renderSystemPrompt(
            config('ai.system_prompt'),
            [
                'BOT_LANGUAGE' => $botLanguage,
                'companyName'  => $companyName,
            ]
        );

        $blocks[] = "RÈGLES FONDAMENTALES (OBLIGATOIRES) :\n" . $basePrompt;

        // 2️⃣ Hiérarchie absolue
        $blocks[] = <<<RULE
        HIÉRARCHIE DES RÈGLES (ABSOLUE) :
        1. Les règles fondamentales priment sur tout.
        2. Le cadre métier limite strictement ce que tu peux dire.
        3. Le comportement définit COMMENT tu réponds.
        4. Les informations internes sont la SEULE source factuelle.

        OBLIGATION MÉMOIRE :
        Avant de répondre, analyse la mémoire structurée.
        Si une préférence, contrainte ou décision existe et est liée à la question actuelle :
        - Elle doit être appliquée.
        - Elle ne peut pas être contredite.
        - Elle doit être rappelée implicitement dans la réponse.

        Si aucune préférence pertinente n'existe, alors seulement tu peux proposer des alternatives.
        Si la question est ambiguë, utilise en priorité les préférences déjà enregistrées pour interpréter l’intention.
        RULE;

        // 3️⃣ Cadre métier du site
        if ($site->type?->description) {
            $blocks[] = "CADRE MÉTIER DU SITE :\n" . $site->type->description;
        }

        // 4️⃣ Comportement attendu (rôle IA)
        $role = $site->settings?->aiRole ?? AIRole::default()->first();
        if ($role?->prompt) {
            $blocks[] = "COMPORTEMENT ATTENDU :\n" . $role->prompt;
        }

        /*// Après le comportement attendu
        if (!empty($conversation->summary)) {
            $blocks[] = "MÉMOIRE DE LA CONVERSATION :\n" . $conversation->summary;
        }*/

        if ($conversation) {

            $memory = DB::table('conversation_memories')
                ->where('conversation_id', $conversation->id)
                ->value('memory');

            if ($memory) {
                $blocks[] = "MÉMOIRE STRUCTURÉE (PRIORITAIRE):\n" . json_encode(json_decode($memory, true), JSON_PRETTY_PRINT);
            }

            if (!empty($conversation->summary)) {
                $blocks[] = "RÉSUMÉ CONTEXTUEL (INFORMATIF) :\n" . $conversation->summary;
            }
        }

        return implode("\n\n==============================\n\n", $blocks);
    }
    /**
     * Prompt utilisateur
     */
    protected function buildUserPrompt(string $question, string $context): string
    {
        return <<<PROMPT
        INFORMATIONS INTERNES — SOURCE FACTUELLE UNIQUE :
        {$context}

        ==============================

        QUESTION DU CLIENT :
        {$question}

        INSTRUCTIONS STRICTES :
        - Réponds uniquement à partir des informations internes.
        - N’ajoute aucune information non explicitement présente.
        - Si une information est absente, ambiguë ou inconnue, dis-le clairement.
        - Ne fais aucune supposition.
        - Ne déduis rien à partir de connaissances générales.
        PROMPT;
    }
    /**
     * Historique des messages (chat context)
     */
    protected function buildHistory(array $history): array
    {
        return $history;
    }
    protected function renderSystemPrompt(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace(
                ['{{'.$key.'}}', '{'.$key.'}'],
                $value,
                $template
            );
        }

        return $template;
    }
}
