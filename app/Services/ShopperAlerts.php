<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The two notifications that bring a shopper back: a new offer from a
 * business they saved, and a claimed coupon that is about to expire.
 *
 * Both are once-only. Each one first claims its row with a conditional
 * UPDATE on a null marker column, the same primitive the claim and redeem
 * paths use, so a retried request or two overlapping runs cannot send the
 * same alert twice. Sending happens after the marker is set: a failure is
 * logged and the alert is simply lost, which is better than a duplicate.
 */
class ShopperAlerts
{
    /** Remind about claims expiring within this many days. */
    public const REMINDER_WINDOW_DAYS = 3;

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Tell the shoppers who saved this business about a newly published offer.
     * Returns how many shoppers were told.
     */
    public function announceNewOffer(Coupon $coupon): int
    {
        if ($coupon->followers_notified_at !== null || !$coupon->is_active) {
            return 0;
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return 0;
        }

        $claimed = Coupon::query()
            ->whereKey($coupon->id)
            ->whereNull('followers_notified_at')
            ->update(['followers_notified_at' => now()]);

        if ($claimed !== 1) {
            return 0;
        }
        $coupon->followers_notified_at = now();

        $shopperIds = $this->followerIds((int) $coupon->user_id);
        if ($shopperIds === []) {
            return 0;
        }

        $businessName = $this->businessNameFor($coupon);
        $flash = (bool) $coupon->is_flash;
        $ends = $flash && $coupon->expires_at ? ' Ends ' . $coupon->expires_at->format('D g:i A') . '.' : '';

        try {
            $this->notifications->send(
                title: $flash ? "Flash deal at {$businessName}" : "New offer at {$businessName}",
                message: "{$coupon->title} — {$this->discountText($coupon)} off." . ($flash ? $ends . ' Grab it before it is gone.' : ' Claim it while it lasts.'),
                type: 'info',
                userIds: $shopperIds,
                data: [
                    'kind' => 'new_offer',
                    'business_id' => $coupon->user_id,
                    'business_name' => $businessName,
                    'coupon_id' => $coupon->id,
                    'coupon_title' => $coupon->title,
                    'action_url' => "/user/business/{$coupon->user_id}",
                ],
                channel: 'shopper',
            );
        } catch (Throwable $e) {
            Log::error('New offer alert failed', ['coupon_id' => $coupon->id, 'error' => $e->getMessage()]);

            return 0;
        }

        return count($shopperIds);
    }

    /**
     * Tell the shoppers who saved this business about a newly published
     * event. Same once-only marker as offers.
     */
    public function announceNewEvent(Event $event): int
    {
        if ($event->followers_notified_at !== null || $event->status !== 'published' || $event->isOver()) {
            return 0;
        }

        $claimed = Event::query()
            ->whereKey($event->id)
            ->whereNull('followers_notified_at')
            ->update(['followers_notified_at' => now()]);

        if ($claimed !== 1) {
            return 0;
        }
        $event->followers_notified_at = now();

        $business = $event->business;
        if (!$business) {
            return 0;
        }

        $shopperIds = $this->followerIds((int) $business->owner_user_id);
        if ($shopperIds === []) {
            return 0;
        }

        $businessName = $business->name ?: 'a business you saved';

        try {
            $this->notifications->send(
                title: "New event at {$businessName}",
                message: "{$event->title} — {$event->localStartsAt()->format('D, M j \a\t g:i A')}. Save your spot.",
                type: 'info',
                userIds: $shopperIds,
                data: [
                    'kind' => 'new_event',
                    'business_id' => $business->owner_user_id,
                    'business_name' => $businessName,
                    'event_id' => $event->id,
                    'event_slug' => $event->slug,
                    'action_url' => "/user/events/{$event->slug}",
                ],
                channel: 'shopper',
            );
        } catch (Throwable $e) {
            Log::error('New event alert failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);

            return 0;
        }

        return count($shopperIds);
    }

    /**
     * Tell the shoppers who saved this business about an announcement. Same
     * once-only marker as offers and events.
     */
    public function announceNews(Announcement $announcement): int
    {
        if ($announcement->followers_notified_at !== null || $announcement->status !== 'published') {
            return 0;
        }

        $claimed = Announcement::query()
            ->whereKey($announcement->id)
            ->whereNull('followers_notified_at')
            ->update(['followers_notified_at' => now()]);

        if ($claimed !== 1) {
            return 0;
        }
        $announcement->followers_notified_at = now();

        $business = $announcement->business;
        if (!$business) {
            return 0;
        }

        $shopperIds = $this->followerIds((int) $business->owner_user_id);
        if ($shopperIds === []) {
            return 0;
        }

        $businessName = $business->name ?: 'a business you saved';

        try {
            $this->notifications->send(
                title: "News from {$businessName}",
                message: $announcement->title . ($announcement->body ? ' — ' . Str::limit(trim($announcement->body), 120) : ''),
                type: 'info',
                userIds: $shopperIds,
                data: [
                    'kind' => 'announcement',
                    'business_id' => $business->owner_user_id,
                    'business_name' => $businessName,
                    'announcement_id' => $announcement->id,
                    'action_url' => "/user/business/{$business->owner_user_id}?tab=updates",
                ],
                channel: 'shopper',
            );
        } catch (Throwable $e) {
            Log::error('Announcement alert failed', ['announcement_id' => $announcement->id, 'error' => $e->getMessage()]);

            return 0;
        }

        return count($shopperIds);
    }

    /**
     * Remind shoppers about claimed coupons that expire within the window.
     * Returns how many reminders went out.
     */
    public function remindExpiringClaims(?CarbonInterface $now = null): int
    {
        $now = $now ? $now->toImmutable() : now()->toImmutable();

        $claims = ClaimedCoupon::query()
            ->where('status', 'claimed')
            ->whereNull('expiry_reminded_at')
            ->whereBetween('expires_at', [$now, $now->addDays(self::REMINDER_WINDOW_DAYS)])
            ->with('business')
            ->orderBy('expires_at')
            ->get();

        $sent = 0;

        foreach ($claims as $claim) {
            $marked = ClaimedCoupon::query()
                ->whereKey($claim->id)
                ->whereNull('expiry_reminded_at')
                ->update(['expiry_reminded_at' => $now]);

            if ($marked !== 1) {
                continue;
            }

            $businessName = $claim->business?->name ?: 'a business you saved';
            $due = $this->dueText($claim->expires_at, $now);

            try {
                $this->notifications->send(
                    title: "Your coupon expires {$due}",
                    message: "{$claim->coupon_title} at {$businessName} ({$claim->discount_display}) is still waiting. Use it by {$claim->expires_at->format('M j')}.",
                    type: 'warning',
                    userIds: [$claim->user_id],
                    data: [
                        'kind' => 'expiring_coupon',
                        'coupon_id' => $claim->id,
                        'coupon_code' => $claim->coupon_code,
                        'business_id' => $claim->business_id,
                        'business_name' => $businessName,
                        'expires_at' => $claim->expires_at->toISOString(),
                        'action_url' => '/user/coupons',
                    ],
                    channel: 'shopper',
                );
                $sent++;
            } catch (Throwable $e) {
                Log::error('Expiring coupon reminder failed', ['claimed_coupon_id' => $claim->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Everyone who saved the business, or any of its products, except the
     * owner. Keyed by the owner's users.id, which is what business_favorites
     * stores and what the consumer business page is addressed by.
     */
    private function followerIds(int $ownerId): array
    {
        $direct = DB::table('business_favorites')
            ->where('business_id', $ownerId)
            ->pluck('user_id');

        $viaProducts = DB::table('product_favorites')
            ->join('products', 'products.id', '=', 'product_favorites.product_id')
            ->where('products.user_id', $ownerId)
            ->pluck('product_favorites.user_id');

        return $direct->merge($viaProducts)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === $ownerId)
            ->values()
            ->all();
    }

    /** "15%" or "$5", not the "15.00%" the decimal cast would print. */
    private function discountText(Coupon $coupon): string
    {
        if ($coupon->discount_type === 'percentage') {
            return rtrim(rtrim(number_format((float) $coupon->discount_percentage, 2, '.', ''), '0'), '.') . '%';
        }

        return '$' . rtrim(rtrim(number_format((float) $coupon->discount_amount, 2, '.', ''), '0'), '.');
    }

    private function businessNameFor(Coupon $coupon): string
    {
        return $coupon->business?->name
            ?: $coupon->user?->business_name
            ?: $coupon->user?->name
            ?: 'a business you saved';
    }

    private function dueText(CarbonInterface $expiresAt, CarbonInterface $now): string
    {
        $days = (int) $now->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'tomorrow',
            default => "in {$days} days",
        };
    }
}
