<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerService extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'order_item_id',
        'name',
        'category',
        'plan',
        'status',
        'cancellation_status',
        'cancellation_reason',
        'cancellation_requested_at',
        'cancellation_reviewed_by',
        'cancellation_reviewed_at',
        'renews_on',
    ];

    protected function casts(): array
    {
        return [
            'renews_on' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancellation_reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function cancellationReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_reviewed_by');
    }

    public function helpdeskTickets(): HasMany
    {
        return $this->hasMany(HelpdeskTicket::class, 'service_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'customer_service_id');
    }
}
