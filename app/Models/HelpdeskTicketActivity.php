<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpdeskTicketActivity extends Model
{
    use HasFactory;

    public const ACTION_TICKET_CREATED = 'ticket_created';
    public const ACTION_STATUS_CHANGED = 'status_changed';
    public const ACTION_ASSIGNMENT_CHANGED = 'assignment_changed';
    public const ACTION_PRIORITY_CHANGED = 'priority_changed';
    public const ACTION_NOTE_ADDED = 'note_added';

    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'actor_user_id',
        'action',
        'old_value',
        'new_value',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpdeskTicket::class, 'ticket_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
