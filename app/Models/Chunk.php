<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends BaseModel
{
    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array'
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
