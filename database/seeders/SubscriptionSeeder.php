<?php

namespace Database\Seeders;

use App\Models\Subscription;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if subscriptions already exist
        $existingCount = Subscription::count();

        if ($existingCount > 0) {
            echo "Found {$existingCount} existing subscriptions. Updating them to match new structure...\n";

            // Update existing subscriptions to match our new structure
            $this->updateExistingSubscriptions();
        }

        $subscriptions = [
            [
                'name' => 'Free',
                'description' => 'Freemium plan with limited features',
                'price' => 0.00,
                'billing_cycle' => 'monthly',
                'features' => [
                    'Up to 10 products',
                    'Up to 20 coupons',
                    'Basic analytics',
                ],
                'is_popular' => false,
                'status' => true,
                'on_show' => true,
                'sort_order' => 0,
                'stripe_price_id' => null,
                'metadata' => [
                    'max_products' => 10,
                    'max_coupons' => 20,
                    'analytics_level' => 'basic',
                    'support_level' => 'none',
                    // Absolute expiry date for free plan (YYYY-MM-DD)
                    'expires_at' => '2025-12-01',
                    // Fallback duration if absolute date not set
                    'expires_in_days' => 30,
                    'is_free' => true,
                ],
            ],
            [
                'name' => 'Basic',
                'description' => 'Perfect for small businesses getting started',
                'price' => 29.00,
                'billing_cycle' => 'monthly',
                'features' => [
                    'Up to 50 products',
                    'Up to 100 coupons',
                    'Basic analytics',
                    'Email support',
                    'QR code generation',
                ],
                'is_popular' => false,
                'status' => true,
                'on_show' => true,
                'sort_order' => 1,
                'stripe_price_id' => null, // Will be set up in Stripe dashboard
                'metadata' => [
                    'max_products' => 50,
                    'max_coupons' => 100,
                    'analytics_level' => 'basic',
                    'support_level' => 'email',
                ],
            ],
            [
                'name' => 'Professional',
                'description' => 'Ideal for growing businesses with advanced needs',
                'price' => 79.00,
                'billing_cycle' => 'monthly',
                'features' => [
                    'Up to 200 products',
                    'Up to 500 coupons',
                    'Advanced analytics',
                    'Priority support',
                    'Custom branding',
                    'API access',
                ],
                'is_popular' => true,
                'status' => true,
                'on_show' => true,
                'sort_order' => 2,
                'stripe_price_id' => null, // Will be set up in Stripe dashboard
                'metadata' => [
                    'max_products' => 200,
                    'max_coupons' => 500,
                    'analytics_level' => 'advanced',
                    'support_level' => 'priority',
                    'custom_branding' => true,
                    'api_access' => true,
                ],
            ],
            [
                'name' => 'Enterprise',
                'description' => 'For large businesses with unlimited requirements',
                'price' => 199.00,
                'billing_cycle' => 'monthly',
                'features' => [
                    'Unlimited products',
                    'Unlimited coupons',
                    'Enterprise analytics',
                    '24/7 phone support',
                    'White-label solution',
                    'Dedicated account manager',
                ],
                'is_popular' => false,
                'status' => true,
                'on_show' => true,
                'sort_order' => 3,
                'stripe_price_id' => null, // Will be set up in Stripe dashboard
                'metadata' => [
                    'max_products' => -1, // Unlimited
                    'max_coupons' => -1, // Unlimited
                    'analytics_level' => 'enterprise',
                    'support_level' => '24/7_phone',
                    'white_label' => true,
                    'dedicated_manager' => true,
                ],
            ],
        ];

        foreach ($subscriptions as $subscriptionData) {
            Subscription::updateOrCreate(
                ['name' => $subscriptionData['name']],
                $subscriptionData
            );
        }

        echo "Subscription seeder completed successfully!\n";
    }

    /**
     * Update existing subscriptions to match the new structure
     */
    private function updateExistingSubscriptions(): void
    {
        // Get existing subscriptions
        $existingSubscriptions = Subscription::all();

        foreach ($existingSubscriptions as $subscription) {
            // Update based on current name
            if ($subscription->name === 'Monthly') {
                $subscription->update([
                    'name' => 'Basic',
                    'description' => 'Perfect for small businesses getting started',
                    'price' => 29.00,
                    'features' => [
                        'Up to 50 products',
                        'Up to 100 coupons',
                        'Basic analytics',
                        'Email support',
                        'QR code generation',
                    ],
                    'is_popular' => false,
                    'sort_order' => 1,
                    'metadata' => [
                        'max_products' => 50,
                        'max_coupons' => 100,
                        'analytics_level' => 'basic',
                        'support_level' => 'email',
                    ],
                ]);
                echo "Updated 'Monthly' subscription to 'Basic'\n";
            } elseif ($subscription->name === 'Annually') {
                $subscription->update([
                    'name' => 'Professional',
                    'description' => 'Ideal for growing businesses with advanced needs',
                    'price' => 79.00,
                    'features' => [
                        'Up to 200 products',
                        'Up to 500 coupons',
                        'Advanced analytics',
                        'Priority support',
                        'Custom branding',
                        'API access',
                    ],
                    'is_popular' => true,
                    'sort_order' => 2,
                    'metadata' => [
                        'max_products' => 200,
                        'max_coupons' => 500,
                        'analytics_level' => 'advanced',
                        'support_level' => 'priority',
                        'custom_branding' => true,
                        'api_access' => true,
                    ],
                ]);
                echo "Updated 'Annually' subscription to 'Professional'\n";
            }
        }
    }
}
