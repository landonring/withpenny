<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FeedbackItem extends Model
{
    use HasFactory;

    public const TYPE_IDEA = 'idea';
    public const TYPE_BUG = 'bug';
    public const TYPE_IMPROVEMENT = 'improvement';

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REPORTED = 'reported';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CLOSED = 'closed';

    public const TYPES = [
        self::TYPE_IDEA,
        self::TYPE_BUG,
        self::TYPE_IMPROVEMENT,
    ];

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_REPORTED,
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SHIPPED,
        self::STATUS_CLOSED,
    ];

    public const ROADMAP_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SHIPPED,
    ];

    protected $fillable = [
        'title',
        'description',
        'admin_response',
        'type',
        'status',
        'comments_locked',
        'is_system_thread',
        'vote_count',
        'contact_email',
        'browser_notes',
        'screenshot_path',
        'submitted_ip',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'comments_locked' => 'boolean',
            'is_system_thread' => 'boolean',
            'vote_count' => 'integer',
        ];
    }

    public function votes(): HasMany
    {
        return $this->hasMany(FeedbackVote::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedbackComment::class);
    }

    public function announcement(): HasOne
    {
        return $this->hasOne(Announcement::class);
    }

    public function roadmapItems(): HasMany
    {
        return $this->hasMany(RoadmapItem::class);
    }
}
