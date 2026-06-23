<?php

namespace App\Services\Social\Facebook;

use Illuminate\Support\Facades\Log;

class FacebookWebhookSecurityService
{
    public function isValid(
        string $payload,
        ?string $signature
    ): bool {

        if (!$signature) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac(
                'sha256',
                $payload,
                config('services.facebook.app_secret')
            );

        Log::debug('[Meta][Security] Vérification signature', [
            'payload_length'   => strlen($payload),
            'payload_preview'  => substr($payload, 0, 100),
            'received'         => $signature,
            'expected'         => $expected,
            'app_secret_first4' => substr(config('services.facebook.app_secret'), 0, 4) . '...',
            'app_secret' => config('services.facebook.app_secret'),
        ]);

        return hash_equals(
            $expected,
            $signature
        );
    }
}
