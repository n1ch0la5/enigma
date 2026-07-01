<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $platform
 * @property string $platform_author_id
 * @property string|null $handle
 * @property Carbon|null $account_created_at
 * @property int|null $followers
 * @property int|null $following
 * @property int|null $total_posts
 * @property string|null $profile_location
 * @property string|null $inferred_timezone
 * @property array<string, mixed> $meta
 * @property Carbon|null $first_seen_at
 */
class Author extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'account_created_at' => 'datetime',
        'first_seen_at' => 'datetime',
    ];

    public function accountAgeDays(): ?int
    {
        return $this->account_created_at
            ? (int) $this->account_created_at->diffInDays(now())
            : null;
    }
}
