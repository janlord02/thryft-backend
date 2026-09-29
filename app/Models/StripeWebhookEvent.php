<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A received Stripe webhook event.
 *
 * The unique event_id is the idempotency key: handlers insert first and treat
 * a unique violation as "already handled".
 */
class StripeWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'type',
        'payload',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function markProcessed(): void
    {
        $this->update(['processed_at' => now(), 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        // Deliberately leaves processed_at null so the row reads as outstanding
        // and can be replayed from the stored payload.
        $this->update(['error' => $error]);
    }
}
