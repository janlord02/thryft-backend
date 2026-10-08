<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
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
 * Rewards are applied automatically, one free month at a time:
 *
 *   each qualified business      → 1 free month
 *   every 10 qualified shoppers  → 1 free month
 *
 * A free month is Stripe account credit worth one month of the referrer's
 * plan when they pay through Stripe, otherwise their plan's end date moves
 * out a month. With no active plan the reward waits; the daily
 * referrals:apply-rewards run picks it up once they have one.
 *
 * Every transition is a conditional UPDATE on the current status, and the
 * reward row exists before any credit is sent, so retries and races cannot
 * double-count or double-credit.
 */
class Referrals
{
    public const SHOPPERS_PER_REWARD = 10;

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
            $referral = Referral::query()
                ->where('referred_user_id', $userId)
                ->where('kind', $kind)
                ->where('status', 'pending')
                ->first();

            $moved = $referral && Referral::query()
                ->whereKey($referral->id)
                ->where('status', 'pending')
                ->update(['qualified_at' => now(), 'status' => 'qualified', 'updated_at' => now()]);

            if ($moved) {
                // After the response: a Stripe call has no business slowing
                // down the redemption or checkout that triggered it.
                $businessId = $referral->business_id;
                dispatch(fn () => app(self::class)->rewardBusiness($businessId))->afterResponse();
            }
        } catch (\Throwable $e) {
            Log::warning('Referral qualification failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Turn a business's qualified referrals into free months and apply any
     * that are not on the account yet. Safe to call any number of times.
     */
    public function rewardBusiness(int $businessId): void
    {
        try {
            $this->earn($businessId);

            ReferralReward::query()
                ->where('business_id', $businessId)
                ->whereNull('applied_at')
                ->orderBy('id')
                ->get()
                ->each(fn (ReferralReward $reward) => $this->apply($reward));
        } catch (\Throwable $e) {
            Log::warning('Referral reward failed', ['business_id' => $businessId, 'error' => $e->getMessage()]);
        }
    }

    /** Write a reward row for each qualified business and each full set of ten shoppers. */
    private function earn(int $businessId): void
    {
        DB::transaction(function () use ($businessId) {
            // Serialises concurrent runs for the same business.
            Business::query()->whereKey($businessId)->lockForUpdate()->first();

            $unrewarded = fn (string $kind) => Referral::query()
                ->where('business_id', $businessId)
                ->where('kind', $kind)
                ->where('status', 'qualified')
                ->whereNull('reward_id')
                ->orderBy('qualified_at')
                ->orderBy('id');

            foreach ($unrewarded('business')->pluck('id') as $id) {
                $reward = ReferralReward::create(['business_id' => $businessId, 'reason' => 'business']);
                Referral::query()->whereKey($id)->update(['reward_id' => $reward->id]);
            }

            $shoppers = $unrewarded('shopper')->pluck('id');
            foreach ($shoppers->chunk(self::SHOPPERS_PER_REWARD) as $set) {
                if ($set->count() < self::SHOPPERS_PER_REWARD) {
                    break;
                }
                $reward = ReferralReward::create(['business_id' => $businessId, 'reason' => 'shoppers']);
                Referral::query()->whereIn('id', $set->values())->update(['reward_id' => $reward->id]);
            }
        });
    }

    private function apply(ReferralReward $reward): void
    {
        $owner = $reward->business?->owner;
        $plan = $owner?->activeSubscription();
        if (!$plan) {
            return; // Waits for an active plan.
        }

        $note = '1 free month';

        if ($plan->stripe_subscription_id && $owner->stripe_customer_id) {
            $cents = $this->monthlyCents($plan);
            $txn = app(StripeCredits::class)->credit(
                $owner->stripe_customer_id,
                $cents,
                'Thryft referral reward: 1 free month',
                'thryft-referral-reward-' . $reward->id,
            );
            $reward->fill(['method' => 'stripe_credit', 'amount_cents' => $cents, 'stripe_balance_transaction_id' => $txn]);
            $note .= ' — $' . number_format($cents / 100, 2) . ' credit';
        } else {
            $from = $plan->ends_at && $plan->ends_at->isFuture() ? $plan->ends_at : now();
            $to = $from->copy()->addMonthNoOverflow();
            $plan->update(['ends_at' => $to] + ($plan->current_period_end ? ['current_period_end' => $to] : []));
            $reward->fill(['method' => 'extension', 'extended_to' => $to]);
            $note .= ' — plan runs to ' . $to->toFormattedDateString();
        }

        DB::transaction(function () use ($reward, $note) {
            $reward->applied_at = now();
            $reward->save();
            Referral::query()
                ->where('reward_id', $reward->id)
                ->where('status', 'qualified')
                ->update(['status' => 'rewarded', 'rewarded_at' => now(), 'reward_note' => $note, 'updated_at' => now()]);
        });
    }

    /** One month of the plan, whatever its billing cycle. */
    private function monthlyCents(UserSubscription $plan): int
    {
        $price = (float) ($plan->subscription?->price ?? $plan->amount_paid);
        $monthly = match ($plan->subscription?->billing_cycle) {
            'yearly' => $price / 12,
            'weekly' => $price * 52 / 12,
            'daily' => $price * 365 / 12,
            default => $price,
        };

        return (int) round($monthly * 100);
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
                'shopper' => 'Qualifies when they redeem their first coupon. Every ' . self::SHOPPERS_PER_REWARD . ' qualified shoppers earn you a free month.',
                'business' => 'Qualifies when they pay for their first subscription, and earns you a free month.',
            ],
            'counts' => ['total' => $rows->count()] + $counts,
            'shoppers_toward_next' => Referral::query()
                ->where('business_id', $business->id)
                ->where('kind', 'shopper')
                ->where('status', 'qualified')
                ->whereNull('reward_id')
                ->count() % self::SHOPPERS_PER_REWARD,
            'shoppers_per_reward' => self::SHOPPERS_PER_REWARD,
            'rewards' => ReferralReward::query()
                ->where('business_id', $business->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (ReferralReward $r) => [
                    'id' => $r->id,
                    'reason' => $r->reason,
                    'method' => $r->method,
                    'amount_cents' => $r->amount_cents,
                    'extended_to' => $r->extended_to,
                    'applied_at' => $r->applied_at,
                    'earned_at' => $r->created_at,
                ])->values()->all(),
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
