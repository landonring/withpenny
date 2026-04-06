<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Receipt;

class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'upload_id',
        'receipt_id',
        'amount',
        'category',
        'note',
        'transaction_date',
        'source',
        'type',
        'confidence_score',
        'flagged',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'flagged' => 'boolean',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'upload_id');
    }
}
