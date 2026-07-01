<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoordinationCluster extends Model
{
    public $timestamps = false;

    protected $table = 'coordination_clusters';

    protected $guarded = [];

    protected $casts = [
        'author_ids' => 'array',
        'signals' => 'array',
        'baseline' => 'array',
        'evidence_post_ids' => 'array',
        'created_at' => 'datetime',
    ];
}
