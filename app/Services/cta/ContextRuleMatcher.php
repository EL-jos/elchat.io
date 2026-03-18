<?php

namespace App\Services\cta;

use App\Interfaces\CtaRuleMatcher;
use App\Models\ChatbotCta;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\queryAnalyzer\QueryPlan;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ContextRuleMatcher implements CtaRuleMatcher
{
    /**
     * Calcule un score pour chaque CTA en fonction du contexte conversationnel.
     *
     * @param ChatbotCta $cta
     * @param QueryPlan $queryPlan
     * @param Conversation $conversation
     * @return ScoreResult
     */
    public function score(ChatbotCta $cta, QueryPlan $queryPlan, Conversation $conversation): ScoreResult
    {
        $scoreResult = new ScoreResult();

        // Assurez-vous que les règles sont chargées
        $cta->loadMissing('rules');

        $query = strtolower($queryPlan->cleanQuery);
        $intent = strtolower($queryPlan->intent);

        // Parcours toutes les règles du CTA
        foreach ($cta->rules as $rule) {
            $ruleType = strtolower($rule->rule_type);
            $ruleValue = strtolower($rule->rule_value);

            switch ($ruleType) {
                case 'intent':
                    if ($ruleValue === $intent) {
                        $scoreResult->add(10, "Intent matches rule '{$ruleValue}'");
                    }
                    break;

                case 'keyword':
                    if (str_contains($query, $ruleValue)) {
                        $scoreResult->add(5, "Keyword found '{$ruleValue}' in query");
                    }
                    break;

                /*case 'entity':
                    // Vérifie si une entité détectée correspond à la règle
                    foreach ($conversation->recentEntities() as $entity) {
                        if (strtolower($entity) === $ruleValue) {
                            $scoreResult->add(3, "Entity '{$ruleValue}' matches recent message entity");
                        }
                    }
                    break;*/

                case 'context':
                    // Exemple avancé : cherche dans l'historique des messages
                    foreach ($conversation->messages()->take(6)->reverse()->get() as $message) {
                        if (str_contains(strtolower($message->content), $ruleValue)) {
                            $scoreResult->add(2, "Context word '{$ruleValue}' found in previous message");
                        }
                    }
                    break;

                default:
                    Log::warning("Unknown CTA rule type '{$ruleType}' for CTA {$cta->id}");
            }
        }

        return $scoreResult;
    }
}
