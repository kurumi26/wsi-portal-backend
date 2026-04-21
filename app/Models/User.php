<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'company',
        'address',
        'mobile_number',
        'tin',
        'profile_photo_url',
        'two_factor_enabled',
        'role',
        'is_enabled',
        'registration_status',
        'registration_admin_notes',
        'registration_reviewed_by',
        'registration_reviewed_at',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'is_enabled' => 'boolean',
            'registration_reviewed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function registrationReviewer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'registration_reviewed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PortalOrder::class);
    }

    public function customerServices(): HasMany
    {
        return $this->hasMany(CustomerService::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function contractAuditLogs(): HasMany
    {
        return $this->hasMany(ContractAuditLog::class);
    }

    public function portalNotifications(): HasMany
    {
        return $this->hasMany(PortalNotification::class);
    }

    public function reportedHelpdeskTickets(): HasMany
    {
        return $this->hasMany(HelpdeskTicket::class, 'customer_id');
    }

    public function assignedHelpdeskTickets(): HasMany
    {
        return $this->hasMany(HelpdeskTicket::class, 'assigned_to_user_id');
    }

    public function helpdeskTicketActivities(): HasMany
    {
        return $this->hasMany(HelpdeskTicketActivity::class, 'actor_user_id');
    }

    public function profileUpdateRequests(): HasMany
    {
        return $this->hasMany(ProfileUpdateRequest::class);
    }

    public function latestProfileUpdateRequest(): HasOne
    {
        return $this->hasOne(ProfileUpdateRequest::class)->latestOfMany();
    }
}
