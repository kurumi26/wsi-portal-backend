<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalOrder extends Model
{
    use HasFactory;

    protected $table = 'portal_orders';

    protected $fillable = [
        'order_number',
        'user_id',
        'total_amount',
        'payment_method',
        'customer_note',
        'agreement_accepted',
        'terms_accepted',
        'privacy_accepted',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
            'agreement_accepted' => 'boolean',
            'terms_accepted' => 'boolean',
            'privacy_accepted' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'portal_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'portal_order_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'order_id');
    }
}
