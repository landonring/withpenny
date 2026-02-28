<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedbackComment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'feedback_item_id',
        'user_id',
        'author_name',
        'author_email',
        'body',
        'is_admin',
        'is_spam',
        'submitted_ip',
    ];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_spam' => 'boolean',
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
