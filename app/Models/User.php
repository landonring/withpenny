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

}
