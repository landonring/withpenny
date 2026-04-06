<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementImport extends Model
{
    use HasFactory;

    protected $table = 'bank_statement_uploads';

    protected $fillable = [
        'user_id',
        'transactions',
        'meta',
        'masked_account',
        'source',
        'file_name',
        'file_path',
        'file_format',
        'status',
        'extraction_confidence',
        'balance_mismatch',
        'extraction_method',
        'processing_status',
        'processing_error',
        'raw_extraction_cache',
        'confidence_score',
        'ai_fallback_used',
        'flagged_rows',
        'total_rows',
        'detected_transactions',
        'processing_started_at',
        'processing_completed_at',
    ];

    protected $casts = [
        'transactions' => 'encrypted:array',
        'meta' => 'encrypted:array',
        'balance_mismatch' => 'boolean',
        'raw_extraction_cache' => 'encrypted',
        'confidence_score' => 'decimal:2',
        'ai_fallback_used' => 'boolean',
        'flagged_rows' => 'integer',
        'total_rows' => 'integer',
        'detected_transactions' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProcessingStatusAttribute(?string $value): string
    {
        return (string) ($value ?: ($this->attributes['status'] ?? 'pending'));
    }

    public function setProcessingStatusAttribute(?string $value): void
    {
        $normalized = (string) ($value ?: 'pending');
        $this->attributes['processing_status'] = $normalized;
        $this->attributes['status'] = $normalized;
    }

    public function setStatusAttribute(?string $value): void
    {
        $normalized = (string) ($value ?: 'pending');
        $this->attributes['status'] = $normalized;
        $this->attributes['processing_status'] = $normalized;
    }
}
