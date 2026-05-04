<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'portal_order_id',
        'user_id',
        'client_id',
        'client_name',
        'company_name',
        'amount',
        'currency',
        'subtotal',
        'discounts',
        'total_amount',
        'status',
        'issued_at',
        'due_date',
        'paid_at',
        'paid_by',
        'payment_reference',
        'internal_note',
        'notes',
    ];

    private static ?array $tableColumns = null;

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'discounts' => 'float',
            'total_amount' => 'float',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public static function generateInvoiceNumber(): string
    {
        do {
            $num = 'INV-'.random_int(100000, 999999);
        } while (self::query()->where('invoice_number', $num)->exists());

        return $num;
    }

    public static function createPortalInvoice(array $attributes): self
    {
        $columns = self::tableColumns();
        $payload = $attributes;

        if (isset($columns['client_id']) && ! array_key_exists('client_id', $payload) && array_key_exists('user_id', $payload)) {
            $payload['client_id'] = $payload['user_id'];
        }

        if (isset($columns['amount']) && ! array_key_exists('amount', $payload)) {
            $payload['amount'] = $payload['total_amount'] ?? $payload['subtotal'] ?? 0;
        }

        if (isset($columns['currency']) && ! array_key_exists('currency', $payload)) {
            $payload['currency'] = 'PHP';
        }

        if (isset($columns['issued_at']) && ! array_key_exists('issued_at', $payload)) {
            $payload['issued_at'] = now();
        }

        if (isset($columns['notes']) && ! array_key_exists('notes', $payload) && array_key_exists('internal_note', $payload)) {
            $payload['notes'] = $payload['internal_note'];
        }

        $payload = array_intersect_key($payload, $columns);

        return self::query()->create($payload);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'portal_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(InvoiceProof::class);
    }

    private static function tableColumns(): array
    {
        if (self::$tableColumns === null) {
            self::$tableColumns = array_fill_keys(Schema::getColumnListing((new self())->getTable()), true);
        }

        return self::$tableColumns;
    }
}
