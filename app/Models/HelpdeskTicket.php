<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpdeskTicket extends Model
{
    use HasFactory;

    public const STATUSES = ['Open', 'In Progress', 'Escalated', 'Resolved', 'Closed'];

    public const SOURCES = ['customer_portal', 'admin_created', 'notification', 'system'];

    public const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    public const ASSIGNABLE_ROLES = ['admin', 'technical_support'];

    protected $fillable = [
        'service_id',
        'customer_id',
        'title',
        'message',
        'category',
        'status',
        'assigned_to_user_id',
        'priority',
        'source',
        'reference_number',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customerService(): BelongsTo
    {
        return $this->belongsTo(CustomerService::class, 'service_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(HelpdeskTicketActivity::class, 'ticket_id')->latest('id');
    }
}
