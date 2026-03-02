<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Transaction;
use App\Models\Receipt;
use App\Models\SavingsJourney;
use App\Models\BankStatementImport;
use App\Models\FeedbackVote;
use App\Models\FeedbackComment;
use App\Models\PushSubscription;
use App\Models\InAppNotification;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use App\Models\SavingsContribution;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements WebAuthnAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, WebAuthnAuthentication, Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'life_phase',
        'role',
        'onboarding_mode',
        'onboarding_step',
        'onboarding_completed',
        'onboarding_started_at',
        'timezone',
        'notifications_enabled',
        'notifications_enabled_at',
        'welcome_notification_sent_at',
        'last_notification_sent_at',
        'show_financial_data_in_notifications',
        'notifications_sent_today_count',
        'last_notification_window',
        'last_notification_date',
        'last_notification_opened_at',
        'last_micro_tip_sent_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'onboarding_mode' => 'boolean',
            'onboarding_step' => 'integer',
            'onboarding_completed' => 'boolean',
            'onboarding_started_at' => 'datetime',
            'notifications_enabled' => 'boolean',
            'notifications_enabled_at' => 'datetime',
            'welcome_notification_sent_at' => 'datetime',
            'last_notification_sent_at' => 'datetime',
            'show_financial_data_in_notifications' => 'boolean',
            'notifications_sent_today_count' => 'integer',
            'last_notification_date' => 'date',
            'last_notification_opened_at' => 'datetime',
            'last_micro_tip_sent_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function savingsJourneys(): HasMany
    {
        return $this->hasMany(SavingsJourney::class);
    }

    public function bankStatementImports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
    }

    public function savingsContributions(): HasMany
    {
        return $this->hasMany(SavingsContribution::class);
    }

    public function feedbackVotes(): HasMany
    {
        return $this->hasMany(FeedbackVote::class);
    }

    public function feedbackComments(): HasMany
    {
        return $this->hasMany(FeedbackComment::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class);
    }

}
