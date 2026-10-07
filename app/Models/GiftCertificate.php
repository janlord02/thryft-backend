<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCertificate extends Model
{
    protected $fillable = [
        'business_id', 'code', 'initial_cents', 'balance_cents', 'status',
        'recipient_user_id', 'recipient_email', 'recipient_name',
        'purchaser_user_id', 'from_name', 'message', 'issued_by_user_id',
        'paid_at', 'expires_at',
    ];

    protected $casts = [
        'initial_cents' => 'integer',
        'balance_cents' => 'integer',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchaser_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && !$this->isExpired() && $this->balance_cents > 0;
    }

    /** One line on where it stands, for the holder and the till. */
    public function statusNote(): string
    {
        return match (true) {
            $this->status === 'pending_payment' => 'Awaiting payment at the counter',
            $this->status === 'void' => 'Cancelled by the business',
            $this->isExpired() => 'Expired ' . $this->expires_at->toFormattedDateString(),
            $this->balance_cents === 0 => 'Fully used',
            $this->expires_at !== null => 'Valid until ' . $this->expires_at->toFormattedDateString(),
            default => 'No expiry',
        };
    }

    public function toWallet(): array
    {
        return [
            'type' => 'gift',
            'id' => $this->id,
            'code' => $this->code,
            'title' => 'Gift certificate',
            'initial_cents' => $this->initial_cents,
            'balance_cents' => $this->balance_cents,
            'status' => $this->status,
            'status_note' => $this->statusNote(),
            'from_name' => $this->from_name,
            'message' => $this->message,
            'recipient_name' => $this->recipient_name,
            'expires_at' => $this->expires_at,
            'business' => $this->business->walletSummary(),
        ];
    }
}
