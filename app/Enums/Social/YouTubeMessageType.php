<?php

namespace App\Enums\Social;

enum YouTubeMessageType: string
{
    case COMMENT = 'comment';
    case REPLY = 'reply';
}
