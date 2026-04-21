<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    public const PRIMARY_SIGNED_DOCUMENT_DISK = 'local';
    public const SIGNED_DOCUMENT_DISKS = ['local', 'public'];

    public const STATUS_PENDING_REVIEW = 'Pending Review';
    public const STATUS_ACCEPTED = 'Accepted';
    public const STATUS_REJECTED = 'Rejected';

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';

    public const SCOPE_CHECKOUT = 'checkout';
    public const SCOPE_ORDER = 'order';
    public const SCOPE_SERVICE = 'service';

    protected $fillable = [
        'user_id',
        'order_id',
        'customer_service_id',
        'external_key',
        'scope',
        'title',
        'description',
        'service_name',
        'version',
        'status',
        'agreement_accepted',
        'terms_accepted',
        'privacy_accepted',
        'requires_signed_document',
        'signed_document_name',
        'signed_document_path',
        'signed_document_uploaded_at',
        'signed_document_uploaded_by',
        'download_path',
        'audit_reference',
        'decision_by',
        'verified_by',
        'decision_at',
        'verified_at',
        'verification_status',
        'accepted_at',
        'rejected_at',
        'document_sections',
    ];

    protected function casts(): array
    {
        return [
            'agreement_accepted' => 'boolean',
            'terms_accepted' => 'boolean',
            'privacy_accepted' => 'boolean',
            'requires_signed_document' => 'boolean',
            'signed_document_uploaded_at' => 'datetime',
            'decision_at' => 'datetime',
            'verified_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'document_sections' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'external_key';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'order_id');
    }

    public function customerService(): BelongsTo
    {
        return $this->belongsTo(CustomerService::class, 'customer_service_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function signedDocumentUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_document_uploaded_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ContractAuditLog::class)->latest('id');
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
        ];
    }

    public static function scopes(): array
    {
        return [
            self::SCOPE_CHECKOUT,
            self::SCOPE_ORDER,
            self::SCOPE_SERVICE,
        ];
    }

    public static function verificationStatuses(): array
    {
        return [
            self::VERIFICATION_PENDING,
            self::VERIFICATION_VERIFIED,
        ];
    }

    public static function defaultDocumentSections(): array
    {
        return [
            [
                'id' => 'service-agreement',
                'title' => 'Service Agreement',
                'description' => 'Defines scope, provisioning, service levels, and commercial obligations.',
            ],
            [
                'id' => 'terms-of-service',
                'title' => 'Terms of Service',
                'description' => 'Covers billing terms, renewals, suspension, cancellation, and account use policies.',
            ],
            [
                'id' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'description' => 'Describes how customer data and operational records are handled for compliance.',
            ],
        ];
    }
}
