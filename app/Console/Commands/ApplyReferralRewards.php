<?php

namespace App\Console\Commands;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Services\Referrals;
use Illuminate\Console\Command;

/**
 * Catches rewards that could not be applied when they were earned: the
 * referrer had no active plan yet, or Stripe was unreachable.
 */
class ApplyReferralRewards extends Command
{
    protected $signature = 'referrals:apply-rewards';

    protected $description = 'Earn and apply any outstanding referral free months';

    public function handle(Referrals $referrals): int
    {
        $ids = Referral::query()->where('status', 'qualified')->whereNull('reward_id')->distinct()->pluck('business_id')
            ->merge(ReferralReward::query()->whereNull('applied_at')->distinct()->pluck('business_id'))
            ->unique();

        foreach ($ids as $id) {
            $referrals->rewardBusiness((int) $id);
        }

        $this->info("Checked {$ids->count()} businesses.");

        return self::SUCCESS;
    }
}
