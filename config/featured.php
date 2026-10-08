<?php

/*
|--------------------------------------------------------------------------
| Featured placement pricing
|--------------------------------------------------------------------------
|
| What a business pays Thryft to have a business, deal or event shown in the
| Featured row for shoppers near it. Basic visibility never costs anything;
| this is extra. Change the price with FEATURED_PRICE_PER_DAY_CENTS.
|
*/

return [
    'price_per_day_cents' => (int) env('FEATURED_PRICE_PER_DAY_CENTS', 500),
    'max_days' => 30,
    'radius_options' => [10, 25, 50],
];
