<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Narrative extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'keywords' => 'array',
        'started_at' => 'datetime',
        'peaked_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
