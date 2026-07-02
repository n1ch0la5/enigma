<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $topic_id
 * @property int|null $source_id
 * @property int|null $author_id
 * @property string $platform
 * @property string $platform_post_id
 * @property string|null $url
 * @property string $body
 * @property string|null $body_normalized
 * @property int|null $score
 * @property Carbon $posted_at
 * @property Carbon|null $ingested_at
 * @property array<string, mixed> $raw
 * @property-read Author|null $author
 * @property-read Topic|null $topic
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'posted_at' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    /** @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
