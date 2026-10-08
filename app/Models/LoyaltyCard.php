<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyCard extends Model
{
    protected $fillable = ['program_id', 'user_id', 'code', 'stamps', 'rewards_available', 'rewards_redeemed', 'last_stamp_at'];

    protected $casts = [
        'stamps' => 'integer',
        'rewards_available' => 'integer',
        'rewards_redeemed' => 'integer',
        'last_stamp_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The card as its holder sees it. */
    public function toWallet(): array
    {
        $program = $this->program;

        return [
            'type' => 'loyalty',
            'id' => $this->id,
            'code' => $this->code,
            'title' => $program->title,
            'reward' => $program->reward,
            'stamps' => $this->stamps,
            'stamps_required' => $program->stamps_required,
            'rewards_available' => $this->rewards_available,
            'is_active' => (bool) $program->is_active,
            'business' => $program->business->walletSummary(),
        ];
    }
}
