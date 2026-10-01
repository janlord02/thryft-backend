<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Referral attribution and qualification.
 *
 * Attribution happens once, at signup, from the code the app captured. A
 * referral qualifies on a real, paid-for or shown-up event, never on the
 * signup itself, so a pile of throwaway accounts earns nothing:
 *
 *   shopper  → their first coupon redemption at any business
 *   business → their first paid subscription
 *
 * Applying the reward stays a human step: an admin marks the row rewarded
 * once the credit is on the account. Every transition here is a conditional
 * UPDATE on the current status, so retries and races cannot double-count.
 */
class Referrals
{
    /**
     * Record that a new account came through a code. Returns the referral,
     * or null when the code is missing, unknown, or the business is not
     * active. Never throws: a bad code must not break a signup.
     */
    public function attach(User $newUser, ?string $code): ?Referral
    {
        $code = Str::upper(trim((string) $code));
        if ($code === '' || strlen($code) > 20) {
            return null;
        }

        try {
            $business = Business::query()->where('referral_code', $code)->active()->first();
            if (!$business || (int) $business->owner_user_id === (int) $newUser->id) {
                return null;
            }

            return Referral::query()->firstOrCreate(
                ['referred_user_id' => $newUser->id],
                [
                    'business_id' => $business->id,
                    'kind' => $newUser->role === 'business' ? 'business' : 'shopper',
                    'status' => 'pending',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Referral attribution failed', ['user_id' => $newUser->id, 'code' => $code, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** A referred shopper redeemed a coupon for the first time. */
    public function onShopperRedeemed(int $userId): void
    {
        $this->qualify($userId, 'shopper');
    }

    /** A referred business paid for a subscription for the first time. */
    public function onBusinessPaid(int $ownerUserId): void
    {
        $this->qualify($ownerUserId, 'business');
    }

    private function qualify(int $userId, string $kind): void
    {
        try {
            Referral::query()
                ->where('referred_user_id', $userId)
                ->where('kind', $kind)
                ->where('status', 'pending')
                ->update(['qualified_at' => now(), 'status' => 'qualified', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Referral qualification failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /** What a merchant sees: their code and link, the numbers, and who came through. */
    public function panelFor(Business $business): array
    {
        $rows = Referral::query()
            ->where('business_id', $business->id)
            ->with('referredUser:id,name,firstname,lastname,business_name,role')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $counts = ['pending' => 0, 'qualified' => 0, 'rewarded' => 0, 'void' => 0];
        foreach ($rows as $r) {
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
        }

        return [
            'code' => $business->referral_code,
            'link' => rtrim(config('app.frontend_url'), '/') . '/auth/register?ref=' . $business->referral_code,
            'rules' => [
                'shopper' => 'Qualifies when they redeem their first coupon.',
                'business' => 'Qualifies when they pay for their first subscription.',
            ],
            'counts' => ['total' => $rows->count()] + $counts,
            'referrals' => $rows->map(fn (Referral $r) => [
                'id' => $r->id,
                'name' => $this->displayName($r->referredUser),
                'kind' => $r->kind,
                'status' => $r->status,
                'joined_at' => $r->created_at,
                'qualified_at' => $r->qualified_at,
                'rewarded_at' => $r->rewarded_at,
                'reward_note' => $r->reward_note,
            ])->values()->all(),
        ];
    }

    /**
     * Enough to recognise a person, not enough to contact them: a business by
     * its name, a shopper by first name and last initial.
     */
    private function displayName(?User $user): string
    {
        if (!$user) {
            return 'Deleted account';
        }
        if ($user->role === 'business' && $user->business_name) {
            return $user->business_name;
        }

        $first = $user->firstname ?: Str::before(trim((string) $user->name), ' ');
        $last = $user->lastname ?: Str::after(trim((string) $user->name), ' ');
        $initial = $last && $last !== $first ? ' ' . Str::upper(Str::substr($last, 0, 1)) . '.' : '';

        return trim($first . $initial) ?: 'A shopper';
    }
}
