<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\ReceiptText;
use App\Models\Transaction;

class Receipt extends Model
{
    /** @use HasFactory<\Database\Factories\ReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'image_path',
        'scanned_at',
        'processing_status',
        'processing_error',
        'extracted_data',
        'confidence_score',
        'flagged',
        'category_suggestion',
        'category_confidence',
        'processing_started_at',
        'processing_completed_at',
        'reviewed_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'extracted_data' => 'encrypted:array',
        'confidence_score' => 'decimal:2',
        'flagged' => 'boolean',
        'category_confidence' => 'decimal:2',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function receiptText(): HasOne
    {
        return $this->hasOne(ReceiptText::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }
}
