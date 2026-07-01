<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $label
 * @property array<int, string> $query_terms
 * @property Carbon|null $created_at
 */
class Topic extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['query_terms' => 'array', 'created_at' => 'datetime'];

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<Narrative, $this> */
    public function narratives(): HasMany
    {
        return $this->hasMany(Narrative::class);
    }

    /** @return HasMany<CoordinationCluster, $this> */
    public function coordination(): HasMany
    {
        return $this->hasMany(CoordinationCluster::class);
    }
}
