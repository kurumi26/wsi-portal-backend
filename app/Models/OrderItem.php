<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'portal_order_id',
        'service_id',
        'service_name',
        'category',
        'configuration',
        'addon',
        'price',
        'billing_cycle',
        'provisioning_status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'portal_order_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function customerService(): HasOne
    {
        return $this->hasOne(CustomerService::class);
    }
}
