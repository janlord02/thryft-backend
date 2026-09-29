<?php

/*
|--------------------------------------------------------------------------
| Business abilities
|--------------------------------------------------------------------------
|
| A closed set of abilities and the member roles that hold them.
|
| Config rather than a database table on purpose: the set is small and fixed,
| it changes with deploys rather than at runtime, and this way it is diffable,
| reviewable and testable. Move it into the database only when someone actually
| needs to edit roles without a deploy.
|
| A member may also carry a `permissions` JSON array granting abilities beyond
| their role — see BusinessMember::abilities().
|
*/

return [

    'abilities' => [
        'business.view_analytics',
        'business.manage_offers',     // products and coupons
        'business.manage_events',     // Phase 7
        'business.edit_page',         // Phase 8
        'business.manage_locations',
        'business.manage_staff',
        'business.manage_billing',
        'business.redeem',            // scan and mark coupons used at the till
    ],

    'roles' => [

        // Full control, including billing and transferring staff.
        'owner' => ['*'],

        // Everything except billing — the trusted second-in-command.
        'admin' => [
            'business.view_analytics',
            'business.manage_offers',
            'business.manage_events',
            'business.edit_page',
            'business.manage_locations',
            'business.manage_staff',
            'business.redeem',
        ],

        // Runs the day-to-day: offers, events, redemption, sees the numbers.
        'manager' => [
            'business.view_analytics',
            'business.manage_offers',
            'business.manage_events',
            'business.redeem',
        ],

        // Front of house. Can honour a coupon, cannot change what is on offer.
        'staff' => [
            'business.redeem',
        ],

    ],

];
