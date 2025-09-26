<?php

namespace App\Events;

use App\Models\ClaimedCoupon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CouponStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $claimedCoupon;

    /**
     * Create a new event instance.
     */
    public function __construct(ClaimedCoupon $claimedCoupon)
    {
        $this->claimedCoupon = $claimedCoupon;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->claimedCoupon->user_id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'claimedCoupon' => [
                'id' => $this->claimedCoupon->id,
                'user_id' => $this->claimedCoupon->user_id,
                'coupon_code' => $this->claimedCoupon->coupon_code,
                'coupon_title' => $this->claimedCoupon->coupon_title,
                'coupon_description' => $this->claimedCoupon->coupon_description,
                'discount_type' => $this->claimedCoupon->discount_type,
                'discount_amount' => $this->claimedCoupon->discount_amount,
                'discount_percentage' => $this->claimedCoupon->discount_percentage,
                'discount_display' => $this->claimedCoupon->discount_display,
                'minimum_amount' => $this->claimedCoupon->minimum_amount,
                'expires_at' => $this->claimedCoupon->expires_at,
                'status' => $this->claimedCoupon->status,
                'used_at' => $this->claimedCoupon->used_at,
                'usage_notes' => $this->claimedCoupon->usage_notes,
                'is_expired' => $this->claimedCoupon->is_expired,
                'is_usable' => $this->claimedCoupon->is_usable,
                'business' => [
                    'id' => $this->claimedCoupon->business->id,
                    'name' => $this->claimedCoupon->business->business_name ?? $this->claimedCoupon->business->name,
                    'profile_image_url' => $this->claimedCoupon->business->profile_image_url,
                ],
                'product' => $this->claimedCoupon->product ? [
                    'id' => $this->claimedCoupon->product->id,
                    'name' => $this->claimedCoupon->product->name,
                    'image' => $this->claimedCoupon->product->image ? asset('storage/' . $this->claimedCoupon->product->image) : null,
                ] : null,
                'created_at' => $this->claimedCoupon->created_at,
                'updated_at' => $this->claimedCoupon->updated_at,
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'coupon.status.changed';
    }
}
