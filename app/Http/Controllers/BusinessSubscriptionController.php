<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\User;
use App\Models\Payment;
use App\Services\StripeWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer;
use Stripe\Subscription as StripeSubscription;
use Stripe\SetupIntent;
use Stripe\Exception\ApiErrorException;

class BusinessSubscriptionController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Check if user has an active subscription
     */
    public function check()
    {
        $user = Auth::user();

        // Single source of truth for "does this grant access" — it also covers
        // past_due inside grace, which the old inline clause did not.
        $activeSubscription = $user->activeSubscription();

        return response()->json([
            'status' => 'success',
            'data' => [
                'hasActiveSubscription' => $activeSubscription !== null,
                'subscription' => $activeSubscription,
            ],
        ]);
    }

    /**
     * Get current user's subscription
     */
    public function current()
    {
        $user = Auth::user();

        $subscription = $user->activeSubscription();

        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active subscription found',
            ], 404);
        }

        // Auto-correct FREE plan expiry to absolute date, if defined
        try {
            $plan = $subscription->subscription;
            if ($plan && strtolower($plan->name) === 'free') {
                $absoluteExpiry = data_get($plan->metadata, 'expires_at');
                if ($absoluteExpiry) {
                    $targetEndsAt = Carbon::parse($absoluteExpiry)->endOfDay();
                    if (!$subscription->ends_at || $subscription->ends_at->ne($targetEndsAt)) {
                        $subscription->update(['ends_at' => $targetEndsAt]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore auto-correct failures; do not block response
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'plan_name' => $subscription->subscription->name,
                'amount' => $subscription->subscription->price,
                'billing_cycle' => $subscription->subscription->billing_cycle,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'next_billing_date' => $subscription->ends_at,
                'remaining_days' => $subscription->getRemainingDays(),
                'features' => $subscription->subscription->features,
            ],
        ]);
    }

    /**
     * Get available subscription plans
     */
    public function plans()
    {
        $plans = Subscription::active()
            ->visible()
            ->ordered()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $plans,
        ]);
    }

    /**
     * Get Stripe configuration
     */
    public function stripeConfig()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'publishableKey' => config('services.stripe.key'),
            ],
        ]);
    }

    /**
     * Create a Setup Intent to save payment method for recurring subscription
     */
    public function createSetupIntent()
    {
        $user = Auth::user();

        try {
            $customer = $this->getOrCreateStripeCustomer($user);

            $setupIntent = SetupIntent::create([
                'customer' => $customer->id,
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'clientSecret' => $setupIntent->client_secret,
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe setup intent creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create setup intent',
            ], 500);
        }
    }

    /**
     * Create a recurring subscription using plan's stripe_price_id
     */
    public function createSubscription(Request $request)
    {
        $request->validate([
            'plan' => 'required|string', // plan name (basic, professional, ...)
            'payment_method' => 'required|string',
        ]);

        $user = Auth::user();

        // Retrieve plan by name and ensure stripe_price_id exists
        $subscriptionPlan = Subscription::whereRaw('LOWER(name) = ?', [strtolower($request->plan)])
            ->where('status', true)
            ->first();

        if (!$subscriptionPlan || empty($subscriptionPlan->stripe_price_id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Subscription plan not available or missing stripe_price_id',
            ], 422);
        }

        try {
            // Customer and payment method
            $customer = $this->getOrCreateStripeCustomer($user);

            // Attach payment method to customer and set default
            \Stripe\PaymentMethod::retrieve($request->payment_method)->attach(['customer' => $customer->id]);
            \Stripe\Customer::update($customer->id, [
                'invoice_settings' => [
                    'default_payment_method' => $request->payment_method,
                ],
            ]);

            // Create subscription
            $stripeSub = StripeSubscription::create([
                'customer' => $customer->id,
                'items' => [
                    ['price' => $subscriptionPlan->stripe_price_id],
                ],
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            // Cancel any existing active subscription locally
            UserSubscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            // Determine period end from Stripe. current_period_end moved from
            // the subscription to the subscription ITEM in API versions from
            // 2025-04-30, so read both before falling back — the old
            // single-location read silently produced "now + 1 month" instead.
            $currentPeriodEnd = $this->resolveStripePeriodEnd($stripeSub) ?? now()->addMonth();

            // Persist local subscription record
            $userSubscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_id' => $subscriptionPlan->id,
                'status' => in_array($stripeSub->status, ['active', 'trialing']) ? 'active' : $stripeSub->status,
                'starts_at' => now(),
                'ends_at' => $currentPeriodEnd,
                'current_period_end' => $currentPeriodEnd,
                'stripe_subscription_id' => $stripeSub->id,
                'stripe_price_id' => $subscriptionPlan->stripe_price_id,
                'cancel_at_period_end' => false,
                'amount_paid' => $subscriptionPlan->price,
                'payment_method' => 'stripe_subscription',
                'transaction_id' => $stripeSub->id,
                'subscription_data' => [
                    'stripe_subscription_id' => $stripeSub->id,
                    'stripe_customer_id' => $customer->id,
                    'price_id' => $subscriptionPlan->stripe_price_id,
                    'latest_invoice' => $stripeSub->latest_invoice ?? null,
                ],
            ]);

            // Record payment if invoice/payment_intent data present
            $invoice = $stripeSub->latest_invoice ?? null;
            $piId = is_object($invoice) && isset($invoice->payment_intent) ? $invoice->payment_intent->id ?? $invoice->payment_intent : null;
            Payment::create([
                'user_id' => $user->id,
                'user_subscription_id' => $userSubscription->id,
                'provider' => 'stripe',
                'provider_payment_id' => $piId ?: $stripeSub->id,
                'amount' => $subscriptionPlan->price,
                'currency' => 'usd',
                'status' => in_array($stripeSub->status, ['active', 'trialing']) ? 'succeeded' : $stripeSub->status,
                'raw_response' => [
                    'subscription' => $stripeSub,
                ],
                'paid_at' => now(),
            ]);

            // Ensure role set to business
            if ($user->role !== 'business') {
                $user->update(['role' => 'business']);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription created successfully',
                'data' => [
                    'subscription' => $userSubscription->load('subscription'),
                ],
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe subscription creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create subscription',
            ], 500);
        }
    }

    /**
     * Claim/activate free plan (no Stripe)
     */
    public function claimFree(Request $request)
    {
        $user = Auth::user();

        // Find active free plan - check by name or by price = 0 and billing_cycle = 'free'
        $freePlan = Subscription::where(function ($query) {
            $query->whereRaw('LOWER(name) = ?', ['free'])
                ->orWhere(function ($q) {
                    $q->where('price', 0)
                        ->where('billing_cycle', 'free');
                });
        })
            ->where('status', true)
            ->first();

        if (!$freePlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Free plan is not available. Please contact support.',
            ], 404);
        }

        // Check if user already has an active subscription
        $existingActive = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        // If user has an active subscription
        if ($existingActive) {
            // If they're already on the free plan, just return success (no need to create duplicate)
            if ($existingActive->subscription_id === $freePlan->id) {
                // Update the expiry date if needed (in case the free plan expiry date changed)
                $absoluteExpiry = data_get($freePlan->metadata, 'expires_at');
                if ($absoluteExpiry) {
                    $targetEndsAt = Carbon::parse($absoluteExpiry)->endOfDay();
                    if (!$existingActive->ends_at || $existingActive->ends_at->ne($targetEndsAt)) {
                        $existingActive->update(['ends_at' => $targetEndsAt]);
                    }
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'You are already on the free plan',
                    'data' => $existingActive->fresh()->load('subscription'),
                ]);
            }

            // Cancel existing subscription to allow switching to free plan
            $existingActive->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        } else {
            // Only check if they've claimed free before if they don't have an active subscription
            // (i.e., this is a first-time claim, not a plan change)
            $claimedFreeBefore = UserSubscription::where('user_id', $user->id)
                ->where('subscription_id', $freePlan->id)
                ->exists();

            if ($claimedFreeBefore) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Free plan can only be claimed once. You can switch to it from a paid plan.',
                ], 400);
            }
        }

        $startsAt = now();
        // Always end Free subscriptions on Dec 1, 2025
        $endsAt = Carbon::parse('2025-12-27')->endOfDay();

        $userSubscription = UserSubscription::create([
            'user_id' => $user->id,
            'subscription_id' => $freePlan->id,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'amount_paid' => 0,
            'payment_method' => 'free',
            'transaction_id' => 'free_' . uniqid(),
            'subscription_data' => [
                'assignment_type' => 'free',
                'plan_details' => $freePlan->toArray(),
            ],
        ]);

        // Ensure role set to business
        if ($user->role !== 'business') {
            $user->update(['role' => 'business']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Free plan activated',
            'data' => $userSubscription->load('subscription'),
        ]);
    }

    /**
     * Update existing active FREE subscription expiry to absolute date (e.g., Dec 1, 2025)
     */
    public function updateFreeExpiry(Request $request)
    {
        $user = Auth::user();

        // Find free plan
        $freePlan = Subscription::whereRaw('LOWER(name) = ?', ['free'])
            ->where('status', true)
            ->first();

        if (!$freePlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Free plan not found',
            ], 404);
        }

        $absoluteExpiry = data_get($freePlan->metadata, 'expires_at');
        if (!$absoluteExpiry) {
            return response()->json([
                'status' => 'error',
                'message' => 'No absolute expiry found for free plan',
            ], 422);
        }

        $endsAt = Carbon::parse($absoluteExpiry)->endOfDay();

        // Update current user's active FREE subscription
        $updated = UserSubscription::where('user_id', $user->id)
            ->where('subscription_id', $freePlan->id)
            ->where('status', 'active')
            ->update(['ends_at' => $endsAt]);

        return response()->json([
            'status' => 'success',
            'message' => $updated ? 'Free subscription expiry updated' : 'No active free subscription to update',
        ]);
    }

    /**
     * Create payment intent for subscription
     */
    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'plan' => 'required|string|in:basic,professional,enterprise',
            'currency' => 'required|string|in:usd',
        ]);

        $user = Auth::user();

        // Get subscription plan by name (case-insensitive)
        $subscriptionPlan = Subscription::whereRaw('LOWER(name) = ?', [strtolower($request->plan)])
            ->where('status', true)
            ->first();

        if (!$subscriptionPlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Subscription plan not found',
            ], 404);
        }

        try {
            // Create or get Stripe customer
            $customer = $this->getOrCreateStripeCustomer($user);

            // Compute amount (in cents) from plan price, ignore client-provided amount
            $amountCents = (int) round(((float) $subscriptionPlan->price) * 100);

            // Create payment intent using server-calculated amount
            $paymentIntent = PaymentIntent::create([
                'amount' => $amountCents,
                'currency' => $request->currency,
                'customer' => $customer->id,
                'metadata' => [
                    'user_id' => $user->id,
                    'subscription_plan' => $request->plan,
                    'subscription_id' => $subscriptionPlan->id,
                ],
                'setup_future_usage' => 'off_session', // For recurring payments
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'clientSecret' => $paymentIntent->client_secret,
                    'subscription_plan' => $subscriptionPlan,
                ],
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe payment intent creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create payment intent',
            ], 500);
        }
    }

    /**
     * Handle successful payment and create subscription
     */
    public function handlePaymentSuccess(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
            'subscription_plan' => 'required|string|in:basic,professional,enterprise',
        ]);

        $user = Auth::user();

        try {
            // Retrieve payment intent from Stripe
            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);

            if ($paymentIntent->status !== 'succeeded') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment not completed',
                ], 400);
            }

            // Get subscription plan by name (case-insensitive)
            $subscriptionPlan = Subscription::whereRaw('LOWER(name) = ?', [strtolower($request->subscription_plan)])
                ->where('status', true)
                ->first();

            if (!$subscriptionPlan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Subscription plan not found',
                ], 404);
            }

            // Cancel any existing active subscription
            UserSubscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            // Create new subscription
            $userSubscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_id' => $subscriptionPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addMonth(), // Monthly billing
                'amount_paid' => $subscriptionPlan->price,
                'payment_method' => 'stripe',
                'transaction_id' => $paymentIntent->id,
                'subscription_data' => [
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'stripe_customer_id' => $paymentIntent->customer,
                    'plan_name' => $subscriptionPlan->name,
                ],
            ]);

            Log::info('Subscription created successfully', [
                'user_id' => $user->id,
                'subscription_id' => $userSubscription->id,
                'plan' => $subscriptionPlan->name,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription activated successfully',
                'data' => [
                    'subscription' => $userSubscription->load('subscription'),
                ],
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe payment verification failed', [
                'user_id' => $user->id,
                'payment_intent_id' => $request->payment_intent_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment verification failed',
            ], 500);
        }
    }

    /**
     * Cancel user's subscription
     */
    public function cancel()
    {
        $user = Auth::user();

        $activeSubscription = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$activeSubscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active subscription found',
            ], 404);
        }

        // The customer paid through the end of the current period, so keep
        // access until then rather than revoking on the spot. Previously
        // ends_at was left untouched, which combined with the absent access
        // gate meant cancellation changed nothing at all.
        $accessUntil = $activeSubscription->current_period_end
            ?? $activeSubscription->ends_at
            ?? now();

        try {
            $stripeSubscriptionId = $activeSubscription->stripe_subscription_id
                ?? $activeSubscription->subscription_data['stripe_subscription_id']
                ?? null;

            if ($stripeSubscriptionId) {
                $stripeSubscription = StripeSubscription::retrieve($stripeSubscriptionId);
                $stripeSubscription->cancel();
            }

            // Update local subscription
            $activeSubscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => $accessUntil,
                'cancel_at_period_end' => true,
            ]);

            Log::info('Subscription cancelled successfully', [
                'user_id' => $user->id,
                'subscription_id' => $activeSubscription->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription cancelled successfully',
            ]);

        } catch (ApiErrorException $e) {
            Log::error('Stripe subscription cancellation failed', [
                'user_id' => $user->id,
                'subscription_id' => $activeSubscription->id,
                'error' => $e->getMessage(),
            ]);

            // Still cancel locally even if Stripe fails
            $activeSubscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => $accessUntil,
                'cancel_at_period_end' => true,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription cancelled locally (Stripe cancellation may have failed)',
            ]);
        }
    }

    /**
     * Read a Stripe subscription's period end across API versions.
     *
     * current_period_end sits on the subscription up to API 2025-03-31 and on
     * the subscription item from 2025-04-30. stripe-php 18 targets the newer
     * shape, so reading only the old location yields null.
     */
    private function resolveStripePeriodEnd($stripeSubscription): ?Carbon
    {
        $timestamp = $stripeSubscription->current_period_end
            ?? $stripeSubscription->items->data[0]->current_period_end
            ?? null;

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }

    /**
     * Get or create Stripe customer
     */
    private function getOrCreateStripeCustomer($user)
    {
        try {
            // Prefer the stored id. The previous email lookup cost a round trip
            // on every payment flow, broke when a user changed their email, and
            // could match a customer from another environment sharing the same
            // Stripe account.
            if ($user->stripe_customer_id) {
                try {
                    $existing = Customer::retrieve($user->stripe_customer_id);

                    if (empty($existing->deleted)) {
                        return $existing;
                    }
                } catch (ApiErrorException $e) {
                    // Stored id is stale (wrong environment, deleted customer).
                    // Fall through and create a fresh one.
                    Log::warning('Stored stripe_customer_id could not be retrieved', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $customer = Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ]);

            $user->forceFill(['stripe_customer_id' => $customer->id])->save();

            return $customer;

        } catch (ApiErrorException $e) {
            Log::error('Stripe customer creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle Stripe webhooks
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\Exception $e) {
            Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // All event handling lives in StripeWebhookService, including the
        // idempotency guard. Always answer 200 once the signature is valid:
        // a non-2xx makes Stripe redeliver, and the idempotency guard would
        // then discard the redelivery as a replay, so the event would be lost
        // rather than retried. Handler failures are recorded on the event row.
        app(StripeWebhookService::class)->handle($event);

        return response()->json(['status' => 'success']);
    }

    /**
     * Admin: Get business users for assignment
     */
    public function getBusinessUsers(Request $request)
    {
        // Check if user is super-admin
        if (Auth::user()->role !== 'super-admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Super-admin role required.',
            ], 403);
        }

        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);

        $perPage = $request->get('per_page', 50);

        $users = User::where('role', 'business')
            ->select(['id', 'name', 'email', 'business_name', 'profile_image', 'created_at'])
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Admin: Get recent subscription assignments
     */
    public function getRecentAssignments(Request $request)
    {
        // Check if user is super-admin
        if (Auth::user()->role !== 'super-admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Super-admin role required.',
            ], 403);
        }

        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = $request->get('per_page', 10);

        $assignments = UserSubscription::with(['user:id,name,email,business_name,profile_image', 'subscription:id,name,price,billing_cycle'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $assignments->items(),
            'pagination' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }

    /**
     * Admin: Manually assign subscription to user
     */
    public function assignSubscription(Request $request)
    {
        // Check if user is super-admin
        if (Auth::user()->role !== 'super-admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Super-admin role required.',
            ], 403);
        }

        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'subscription_id' => 'required|integer|exists:subscriptions,id',
            'replace_existing' => 'nullable|boolean',
            'send_notification' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        $user = User::findOrFail($request->user_id);

        // Ensure user is a business user
        if ($user->role !== 'business') {
            return response()->json([
                'status' => 'error',
                'message' => 'User must have business role to assign subscription',
            ], 400);
        }

        $subscription = Subscription::findOrFail($request->subscription_id);

        // Check if user already has an active subscription
        $existingSubscription = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($existingSubscription && !$request->replace_existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'User already has an active subscription. Use replace_existing option to override.',
            ], 400);
        }

        try {
            // Cancel existing subscription if replacing
            if ($existingSubscription && $request->replace_existing) {
                $existingSubscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }

            // Create new subscription assignment
            $userSubscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'status' => 'active',
                'starts_at' => $request->starts_at ? Carbon::parse($request->starts_at) : now(),
                'ends_at' => $request->ends_at ? Carbon::parse($request->ends_at) : null,
                'amount_paid' => 0, // Manual assignment, no payment
                'payment_method' => 'manual',
                'transaction_id' => 'manual_' . uniqid(),
                'subscription_data' => [
                    'assigned_by' => Auth::id(),
                    'assignment_type' => 'manual',
                    'plan_details' => $subscription->toArray(),
                ],
            ]);

            // Send notification if requested
            if ($request->send_notification) {
                try {
                    Mail::send('emails.subscription-assignment', [
                        'user' => $user,
                        'subscription' => $subscription,
                        'userSubscription' => $userSubscription,
                    ], function ($message) use ($user, $subscription) {
                        $message->to($user->email, $user->name)
                            ->subject('Subscription Assigned - ' . $subscription->name . ' Plan');
                    });

                    Log::info('Subscription assignment email sent successfully', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'subscription_id' => $subscription->id,
                        'subscription_name' => $subscription->name,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send subscription assignment email', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);

                    // Don't fail the entire operation if email fails
                    // Just log the error and continue
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription assigned successfully',
                'data' => $userSubscription->load(['user', 'subscription']),
            ]);
        } catch (\Exception $e) {
            Log::error('Manual subscription assignment failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'assigned_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign subscription: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin: Cancel subscription assignment
     */
    public function cancelAssignment(Request $request, $assignmentId)
    {
        // Check if user is super-admin
        if (Auth::user()->role !== 'super-admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Super-admin role required.',
            ], 403);
        }

        $userSubscription = UserSubscription::findOrFail($assignmentId);

        try {
            $userSubscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription cancelled successfully',
                'data' => $userSubscription->load(['user', 'subscription']),
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription cancellation failed: ' . $e->getMessage(), [
                'assignment_id' => $assignmentId,
                'cancelled_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel subscription: ' . $e->getMessage(),
            ], 500);
        }
    }
}
