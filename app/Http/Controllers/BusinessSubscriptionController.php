<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\User;
use App\Models\Payment;
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

        $activeSubscription = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->with('subscription')
            ->first();

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

        $subscription = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->with('subscription')
            ->first();

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

            // Determine period end from Stripe
            $currentPeriodEnd = isset($stripeSub->current_period_end)
                ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                : now()->addMonth();

            // Persist local subscription record
            $userSubscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_id' => $subscriptionPlan->id,
                'status' => in_array($stripeSub->status, ['active', 'trialing']) ? 'active' : $stripeSub->status,
                'starts_at' => now(),
                'ends_at' => $currentPeriodEnd,
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

        // Find active free plan
        $freePlan = Subscription::whereRaw('LOWER(name) = ?', ['free'])
            ->where('status', true)
            ->first();

        if (!$freePlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'Free plan is not available',
            ], 404);
        }

        // Check if user already has an active subscription
        $existingActive = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($existingActive) {
            return response()->json([
                'status' => 'error',
                'message' => 'You already have an active subscription',
            ], 400);
        }

        // Check if user has ever claimed free plan before (one-time)
        $claimedFreeBefore = UserSubscription::where('user_id', $user->id)
            ->where('subscription_id', $freePlan->id)
            ->exists();

        if ($claimedFreeBefore) {
            return response()->json([
                'status' => 'error',
                'message' => 'Free plan can only be claimed once',
            ], 400);
        }

        $startsAt = now();
        // Always end Free subscriptions on Dec 1, 2025
        $endsAt = Carbon::parse('2025-12-01')->endOfDay();

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

        try {
            // Cancel Stripe subscription if exists
            if (isset($activeSubscription->subscription_data['stripe_subscription_id'])) {
                $stripeSubscription = StripeSubscription::retrieve(
                    $activeSubscription->subscription_data['stripe_subscription_id']
                );
                $stripeSubscription->cancel();
            }

            // Update local subscription
            $activeSubscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
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
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription cancelled locally (Stripe cancellation may have failed)',
            ]);
        }
    }

    /**
     * Get or create Stripe customer
     */
    private function getOrCreateStripeCustomer($user)
    {
        try {
            // Try to find existing customer by email
            $customers = Customer::all(['email' => $user->email, 'limit' => 1]);

            if ($customers->data && count($customers->data) > 0) {
                return $customers->data[0];
            }

            // Create new customer
            return Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ]);

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

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;

            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($event->data->object);
                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event->data->object);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle successful payment
     */
    private function handlePaymentSucceeded($paymentIntent)
    {
        Log::info('Payment succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'customer_id' => $paymentIntent->customer,
        ]);
    }

    /**
     * Handle successful invoice payment
     */
    private function handleInvoicePaymentSucceeded($invoice)
    {
        Log::info('Invoice payment succeeded', [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer,
        ]);

        try {
            $stripeSubscriptionId = $invoice->subscription ?? null;
            $userSubscription = $stripeSubscriptionId
                ? UserSubscription::where('transaction_id', $stripeSubscriptionId)->first()
                : null;

            if ($userSubscription) {
                Payment::create([
                    'user_id' => $userSubscription->user_id,
                    'user_subscription_id' => $userSubscription->id,
                    'provider' => 'stripe',
                    'provider_payment_id' => $invoice->payment_intent ?? $invoice->id,
                    'amount' => $userSubscription->subscription->price ?? 0,
                    'currency' => $invoice->currency ?? 'usd',
                    'status' => 'succeeded',
                    'raw_response' => $invoice,
                    'paid_at' => Carbon::createFromTimestamp($invoice->status_transitions->paid_at ?? time()),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to record payment from invoice webhook', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoice->id ?? null,
            ]);
        }
    }

    /**
     * Handle failed invoice payment
     */
    private function handleInvoicePaymentFailed($invoice)
    {
        Log::warning('Invoice payment failed', [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer,
        ]);
    }

    /**
     * Handle subscription deletion
     */
    private function handleSubscriptionDeleted($subscription)
    {
        Log::info('Subscription deleted', [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer,
        ]);
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
