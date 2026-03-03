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
        'file_name',
        'file_format',
        'extraction_confidence',
        'balance_mismatch',
        'extraction_method',
        'processing_status',
        'processing_error',
        'raw_extraction_cache',
        'confidence_score',
        'flagged_rows',
        'total_rows',
        'processing_started_at',
        'processing_completed_at',
    ];

    protected $casts = [
        'transactions' => 'encrypted:array',
        'meta' => 'encrypted:array',
        'balance_mismatch' => 'boolean',
        'raw_extraction_cache' => 'encrypted',
        'confidence_score' => 'decimal:2',
        'flagged_rows' => 'integer',
        'total_rows' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
