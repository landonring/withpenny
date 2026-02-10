<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transactions',
        'meta',
        'masked_account',
        'source',
    ];

    protected $casts = [
        'transactions' => 'encrypted:array',
        'meta' => 'encrypted:array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
