<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAuditLog extends Model
{
    use HasFactory;

    public const ACTION_CREATED = 'created';
    public const ACTION_ACCEPTED = 'accepted';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_VERIFIED = 'verified';
    public const ACTION_SIGNED_DOCUMENT_UPLOADED = 'signed_document_uploaded';

    public const UPDATED_AT = null;

    protected $fillable = [
        'contract_id',
        'user_id',
        'action',
        'old_status',
        'new_status',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
