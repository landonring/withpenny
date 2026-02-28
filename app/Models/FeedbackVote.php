<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'feedback_item_id',
        'user_id',
        'ip_address',
        'voter_key',
        'direction',
    ];

    protected function casts(): array
    {
        return [
            'direction' => 'integer',
        ];
    }

    public function feedbackItem(): BelongsTo
    {
        return $this->belongsTo(FeedbackItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
