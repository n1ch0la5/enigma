<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['query_terms' => 'array', 'created_at' => 'datetime'];

    public function posts()        { return $this->hasMany(Post::class); }
    public function narratives()   { return $this->hasMany(Narrative::class); }
    public function coordination() { return $this->hasMany(CoordinationCluster::class); }
}
