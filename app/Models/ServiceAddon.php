<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'label',
        'extra_price',
        'billing_cycle',
    ];

    protected function casts(): array
    {
        return [
            'extra_price' => 'float',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
