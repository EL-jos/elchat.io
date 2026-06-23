<?php

namespace App\SocialChannels;

use App\Enums\Social\SocialProvider;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use App\SocialChannels\Facebook\FacebookChannel;
use App\SocialChannels\Slack\SlackChannel;
use App\SocialChannels\Telegram\TelegramChannel;
use App\SocialChannels\YouTube\YouTubeChannel;
use InvalidArgumentException;

class ChannelManager
{
    /**
     * Retourne le driver demandé
     */
    public function driver(
        string | SocialProvider $provider
    ): SocialChannelInterface {

        $provider = $provider instanceof SocialProvider
            ? $provider->value
            : $provider;

        return match ($provider) {

            'facebook' => app(FacebookChannel::class),

            'youtube' => app(YouTubeChannel::class),

            'slack' => app(SlackChannel::class),

            'telegram' => app(TelegramChannel::class),

            default
            => throw new InvalidArgumentException(
                "Unsupported provider [{$provider}]"
            ),
        };
    }

    /**
     * Synchronisation
     */
    public function sync(
        string $provider
    ): array {

        return $this
            ->driver($provider)
            ->fetchMessages();
    }

    /**
     * Publication générique
     */
    public function sendReply(
        SocialAccount $account,
        SocialMessage $message
    ): array {

        return $this
            ->driver($account->provider)
            ->sendReply(
                account: $account,
                message: $message
            );
    }
}
