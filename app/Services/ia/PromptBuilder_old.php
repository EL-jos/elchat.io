<?php

namespace App\Services\ia;

use App\Models\AIRole;
use App\Models\Site;

class PromptBuilder
{
    /**
     * Construit le payload complet pour l'IA
     */
    public function build(
        Site $site,
        string $question,
        string $context,
        array $history = []
    ): array {
        return [
            'system' => $this->buildSystemPrompt($site),
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
    protected function buildSystemPrompt(Site $site): string
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
