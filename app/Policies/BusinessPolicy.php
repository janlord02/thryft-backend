<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

/**
 * Authorization for a business itself and for its staff list.
 */
class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $user->membershipFor($business) !== null
            || (int) $business->owner_user_id === (int) $user->id;
    }

    public function update(User $user, Business $business): bool
    {
        return $user->hasBusinessAbility('business.edit_page', $business);
    }

    public function viewAnalytics(User $user, Business $business): bool
    {
        return $user->hasBusinessAbility('business.view_analytics', $business);
    }

    public function manageStaff(User $user, Business $business): bool
    {
        return $user->hasBusinessAbility('business.manage_staff', $business);
    }

    public function manageBilling(User $user, Business $business): bool
    {
        // Owner-only by default in config/permissions.php — not even 'admin'
        // holds this one.
        return $user->hasBusinessAbility('business.manage_billing', $business);
    }

    public function manageLocations(User $user, Business $business): bool
    {
        return $user->hasBusinessAbility('business.manage_locations', $business);
    }
}
