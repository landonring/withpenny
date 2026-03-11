<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InAppNotification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'type',
        'subtype',
        'title',
        'body',
        'deep_link',
        'version',
        'priority',
        'push_status',
        'push_sent_at',
        'push_failed_at',
        'push_error',
        'data_json',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'data_json' => 'array',
        'priority' => 'integer',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'push_sent_at' => 'datetime',
        'push_failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
