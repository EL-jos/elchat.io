<?php

use App\Services\cta\ContextRuleMatcher;
use App\Services\cta\IntentRuleMatcher;
use App\Services\cta\KeywordRuleMatcher;

return [
    'limit' => 3,

    'weights' => [
        'intent' => 5,
        'keyword' => 2,
        'context' => 1,
    ],

    'matchers' => [
        IntentRuleMatcher::class,
        KeywordRuleMatcher::class,
        ContextRuleMatcher::class,
    ],
];
