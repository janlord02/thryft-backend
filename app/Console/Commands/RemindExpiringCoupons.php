<?php

namespace App\Console\Commands;

use App\Services\ShopperAlerts;
use Illuminate\Console\Command;

/**
 * Daily nudge for claimed coupons that are about to expire. Each claim is
 * reminded once, so running it more often is harmless.
 */
class RemindExpiringCoupons extends Command
{
    protected $signature = 'coupons:remind-expiring';

    protected $description = 'Remind shoppers about claimed coupons expiring in the next few days';

    public function handle(ShopperAlerts $alerts): int
    {
        $sent = $alerts->remindExpiringClaims();

        $this->info("Sent {$sent} expiring-coupon reminder(s).");

        return self::SUCCESS;
    }
}
