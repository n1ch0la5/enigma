<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
