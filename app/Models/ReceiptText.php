<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptText extends Model
{
    /** @use HasFactory<\Database\Factories\ReceiptTextFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'receipt_id',
        'raw_text',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
