<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'raw' => 'array',
        'posted_at' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    public function author() { return $this->belongsTo(Author::class); }
    public function topic()  { return $this->belongsTo(Topic::class); }
}
