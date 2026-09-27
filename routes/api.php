<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserDashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogsController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\BusinessTagController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\Admin\PromoCodeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\NotificationController as UserNotificationController;
use App\Http\Controllers\BusinessSubscriptionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\BusinessDashboardController;
use App\Http\Controllers\Public\GuestBrowseController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Critical routes that should work even in maintenance mode
// Route::middleware('auth:sanctum')->group(function () {
//     // User validation endpoint - must work in maintenance mode for super-admin bypass
//     Route::get('/user', function (Request $request) {
//         return $request->user();
//     });
// });

// Apply maintenance mode middleware to all other routes
Route::middleware('maintenance')->group(function () {
    // Public routes
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register-business', [AuthController::class, 'registerBusiness']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/validate-reset-token', [PasswordResetController::class, 'validateToken']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    Route::post('/verify-email', [EmailVerificationController::class, 'verify']);
    Route::post('/resend-verification', [EmailVerificationController::class, 'resend']);

    // 2FA verification routes (public - no auth required)
    Route::post('/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/2fa/resend', [AuthController::class, 'resendTwoFactorCode'])->middleware('throttle:two-factor');

    // Public settings route
    Route::get('/settings/public', [SettingsController::class, 'getPublicSettings']);

    // Public theme settings route
    Route::get('/settings/theme/public', [SettingsController::class, 'getPublicThemeSettings']);

    // Public categories route
    Route::get('/categories/public', [CategoryController::class, 'public']);

    // Public business tags route
    Route::get('/business-tags/public', [BusinessTagController::class, 'public']);

    // Guest mode: browse businesses and deals without an account.
    // Read-only; anything tied to identity (claiming, favouriting, redeeming)
    // stays behind auth:sanctum below. Its own throttle tier because it is
    // unauthenticated and therefore keyed on IP, and is the surface a scraper
    // would target.
    Route::prefix('public')->middleware('throttle:public')->group(function () {
        Route::get('/businesses', [GuestBrowseController::class, 'businesses']);
        Route::get('/businesses/{business}', [GuestBrowseController::class, 'business']);
        Route::get('/deals', [GuestBrowseController::class, 'deals']);
        Route::get('/businesses/{business}/deals/{couponSlug}', [GuestBrowseController::class, 'deal']);
    });

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            $user = $request->user();
            // Try to load businessTags, but don't fail if table doesn't exist yet
            try {
                if (
                    Schema::hasTable('business_tags') &&
                    Schema::hasTable('business_assign_tags')
                ) {
                    $user->load('businessTags');
                }
            } catch (\Exception $e) {
                // Table might not exist yet (migrations not run)
                // Continue without business tags
            }
            return $user;
        });
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/business-profile', [AuthController::class, 'updateBusinessProfile']);
        Route::put('/personal-profile', [AuthController::class, 'updatePersonalProfile']);

        // Profile routes
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::post('/image', [ProfileController::class, 'uploadImage']);
            Route::delete('/image', [ProfileController::class, 'removeImage']);
            Route::put('/password', [ProfileController::class, 'changePassword']);
        });

        // 2FA routes
        Route::prefix('2fa')->group(function () {
            Route::get('/status', [ProfileController::class, 'getTwoFactorStatus']);
            Route::post('/enable', [ProfileController::class, 'enableTwoFactor']);
            Route::post('/confirm', [ProfileController::class, 'confirmTwoFactor']);
            Route::delete('/disable', [ProfileController::class, 'disableTwoFactor']);
            Route::delete('/enable', [ProfileController::class, 'cancelTwoFactorSetup']);
            Route::get('/qr-code', [ProfileController::class, 'generateQrCode']);
        });

        // User activity route
        Route::get('/activity', [UserDashboardController::class, 'userActivity']);

        // Nearby businesses route (for all authenticated users)
        Route::get('/nearby-businesses', [UserDashboardController::class, 'nearbyBusinesses']);
        Route::get('/business/{businessId}/products', [UserDashboardController::class, 'businessProducts']);
        Route::post('/coupons/claim', [UserDashboardController::class, 'claimCoupon'])->middleware('throttle:claim');
        Route::get('/coupons/claimed', [UserDashboardController::class, 'getClaimedCoupons']);
        Route::post('/coupons/validate-scan', [UserDashboardController::class, 'validateScan']);
        Route::post('/coupons/validate-qr-direct', [UserDashboardController::class, 'validateQRDirect']);
        Route::post('/coupons/validate-manual', [UserDashboardController::class, 'validateManual']);
        Route::post('/coupons/validate-specific', [UserDashboardController::class, 'validateSpecificCustomer']);
        Route::post('/coupons/search-customers', [UserDashboardController::class, 'searchCustomers']);
        Route::post('/coupons/mark-as-used', [UserDashboardController::class, 'markAsUsed'])->middleware('throttle:redeem');

        // Product favorites
        Route::post('/products/favorite', [UserDashboardController::class, 'toggleProductFavorite']);
        Route::get('/products/favorites', [UserDashboardController::class, 'getFavoriteProducts']);

        // Business favorites
        Route::get('/businesses/favorites', [UserDashboardController::class, 'getFavoriteBusinesses']);
        Route::post('/businesses/favorite', [UserDashboardController::class, 'toggleBusinessFavorite']);

        // Theme settings route (for all authenticated users)
        Route::get('/settings/theme', [SettingsController::class, 'getThemeSettings']);

        // Admin routes - Super Admin only
        Route::middleware('role:super-admin')->prefix('admin')->group(function () {
            // Dashboard routes
            Route::prefix('dashboard')->group(function () {
                Route::get('/analytics', [DashboardController::class, 'analytics']);
                Route::get('/activity', [DashboardController::class, 'recentActivity']);
                Route::get('/user-stats', [DashboardController::class, 'userStats']);
            });

            // User management routes
            Route::prefix('users')->group(function () {
                Route::get('/', [UserController::class, 'index']);
                Route::post('/', [UserController::class, 'store']);
                Route::get('/stats', [UserController::class, 'stats']);
                Route::get('/export', [UserController::class, 'export']);
                Route::post('/bulk-action', [UserController::class, 'bulkAction']);
                Route::get('/{user}', [UserController::class, 'show']);
                Route::put('/{user}', [UserController::class, 'update']);
                Route::delete('/{user}', [UserController::class, 'destroy']);
            });

            // Settings routes
            Route::prefix('settings')->group(function () {
                Route::get('/', [SettingsController::class, 'index']);
                Route::get('/{group}', [SettingsController::class, 'getByGroup']);
                Route::put('/', [SettingsController::class, 'update']);
                Route::post('/reset', [SettingsController::class, 'reset']);
                Route::post('/logo', [SettingsController::class, 'uploadLogo']);
            });

            // Logs routes
            Route::prefix('logs')->group(function () {
                Route::get('/', [LogsController::class, 'index']);
                Route::get('/stats', [LogsController::class, 'stats']);
                Route::get('/types', [LogsController::class, 'types']);
                Route::get('/users', [LogsController::class, 'users']);
                Route::get('/export', [LogsController::class, 'export']);
                Route::delete('/clear', [LogsController::class, 'clear']);
                Route::get('/{log}', [LogsController::class, 'show']);
            });

            // Notification routes
            Route::prefix('notifications')->group(function () {
                Route::get('/', [NotificationController::class, 'index']);
                Route::post('/', [NotificationController::class, 'store']);
                Route::get('/stats', [NotificationController::class, 'stats']);
                Route::get('/types', [NotificationController::class, 'types']);
                Route::get('/users', [NotificationController::class, 'users']);
                Route::get('/{notification}', [NotificationController::class, 'show']);
                Route::put('/{notification}', [NotificationController::class, 'update']);
                Route::delete('/{notification}', [NotificationController::class, 'destroy']);
            });

            // Subscription management routes
            Route::prefix('subscriptions')->group(function () {
                Route::get('/', [SubscriptionController::class, 'index']);
                Route::post('/', [SubscriptionController::class, 'store']);
                Route::get('/stats', [SubscriptionController::class, 'stats']);
                Route::get('/options', [SubscriptionController::class, 'options']);
                Route::post('/reorder', [SubscriptionController::class, 'reorder']);

                // Assignment routes (must come before parameterized routes)
                Route::get('/business-users', [BusinessSubscriptionController::class, 'getBusinessUsers']);
                Route::get('/recent-assignments', [BusinessSubscriptionController::class, 'getRecentAssignments']);
                Route::post('/assign', [BusinessSubscriptionController::class, 'assignSubscription']);
                Route::post('/{assignmentId}/cancel', [BusinessSubscriptionController::class, 'cancelAssignment']);

                // Parameterized routes (must come after specific routes)
                Route::get('/{subscription}', [SubscriptionController::class, 'show']);
                Route::put('/{subscription}', [SubscriptionController::class, 'update']);
                Route::delete('/{subscription}', [SubscriptionController::class, 'destroy']);
                Route::post('/{subscription}/toggle-status', [SubscriptionController::class, 'toggleStatus']);
                Route::post('/{subscription}/toggle-visibility', [SubscriptionController::class, 'toggleVisibility']);
                Route::get('/{subscription}/subscribers', [SubscriptionController::class, 'subscribers']);
            });

            // Payments list
            Route::get('/payments', [PaymentController::class, 'index']);

            // Category management routes
            Route::prefix('categories')->group(function () {
                Route::get('/', [CategoryController::class, 'index']);
                Route::post('/', [CategoryController::class, 'store']);
                Route::get('/active', [CategoryController::class, 'active']);
                Route::get('/{category}', [CategoryController::class, 'show']);
                Route::put('/{category}', [CategoryController::class, 'update']);
                Route::delete('/{category}', [CategoryController::class, 'destroy']);
                Route::post('/{category}/toggle-status', [CategoryController::class, 'toggleStatus']);
            });

            // Business tag management routes
            Route::prefix('business-tags')->group(function () {
                Route::get('/', [\App\Http\Controllers\Admin\BusinessTagController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Admin\BusinessTagController::class, 'store']);
                Route::get('/active', [\App\Http\Controllers\Admin\BusinessTagController::class, 'active']);
                Route::get('/{businessTag}', [\App\Http\Controllers\Admin\BusinessTagController::class, 'show']);
                Route::put('/{businessTag}', [\App\Http\Controllers\Admin\BusinessTagController::class, 'update']);
                Route::delete('/{businessTag}', [\App\Http\Controllers\Admin\BusinessTagController::class, 'destroy']);
                Route::post('/{businessTag}/toggle-status', [\App\Http\Controllers\Admin\BusinessTagController::class, 'toggleStatus']);
            });

            // Promo code management routes
            Route::prefix('promo-codes')->group(function () {
                Route::get('/', [PromoCodeController::class, 'index']);
                Route::post('/', [PromoCodeController::class, 'store']);
                Route::get('/stats', [PromoCodeController::class, 'stats']);
                Route::get('/business-users', [PromoCodeController::class, 'businessUsers']);
                Route::get('/{promoCode}', [PromoCodeController::class, 'show']);
                Route::put('/{promoCode}', [PromoCodeController::class, 'update']);
                Route::delete('/{promoCode}', [PromoCodeController::class, 'destroy']);
                Route::post('/{promoCode}/toggle-status', [PromoCodeController::class, 'toggleStatus']);
                Route::post('/{promoCode}/toggle-visibility', [PromoCodeController::class, 'toggleVisibility']);
                Route::post('/{promoCode}/assign', [PromoCodeController::class, 'assign']);
                Route::post('/{promoCode}/revoke', [PromoCodeController::class, 'revoke']);
                Route::get('/{promoCode}/assigned-users', [PromoCodeController::class, 'assignedUsers']);
                Route::post('/{promoCode}/duplicate', [PromoCodeController::class, 'duplicate']);
            });
        });

        // Business routes.
        //
        // 'business:<ability>' resolves which business the request acts for,
        // confirms it is paid up, then confirms the caller holds the ability.
        // It supersedes ['role:business', 'subscribed']: role is no longer the
        // thing that grants access, membership is — which is what allows staff
        // accounts without sharing the owner's login.
        //
        // The group requires only business context; each prefix below then
        // declares the ability it actually needs, so a 'staff' member can
        // redeem at the till without being able to edit what is on offer.
        Route::middleware('business')->group(function () {
            // Merchant dashboard. Separate ability from manage_offers so a
            // manager can see the numbers without being able to edit billing,
            // and so analytics can later be withheld on cheaper plans.
            Route::prefix('business/dashboard')->middleware('business:business.view_analytics')->group(function () {
                Route::get('/analytics', [BusinessDashboardController::class, 'analytics']);
                Route::get('/onboarding', [BusinessDashboardController::class, 'onboarding']);
            });

            // Product management routes
            Route::prefix('products')->middleware('business:business.manage_offers')->group(function () {
                Route::get('/', [ProductController::class, 'index']);
                Route::post('/', [ProductController::class, 'store']);
                Route::get('/categories', [ProductController::class, 'getCategories']);
                Route::get('/{product}', [ProductController::class, 'show']);
                Route::put('/{product}', [ProductController::class, 'update']);
                Route::delete('/{product}', [ProductController::class, 'destroy']);
                Route::post('/{product}/toggle-status', [ProductController::class, 'toggleStatus']);
                Route::post('/{product}/toggle-featured', [ProductController::class, 'toggleFeatured']);
            });

            // Tag management routes
            Route::prefix('tags')->middleware('business:business.manage_offers')->group(function () {
                Route::get('/search', [TagController::class, 'search']);
                Route::get('/popular', [TagController::class, 'popular']);
                Route::post('/', [TagController::class, 'store']);
                Route::get('/', [TagController::class, 'index']);
            });

            // Coupon management routes
            Route::prefix('coupons')->group(function () {
                // Managing what is on offer.
                Route::middleware('business:business.manage_offers')->group(function () {
                    Route::get('/', [CouponController::class, 'index']);
                    Route::post('/', [CouponController::class, 'store']);
                    Route::get('/products', [CouponController::class, 'getProducts']);
                });

                // Honouring a coupon at the till. Separate ability so front-of-
                // house staff can redeem without being able to edit offers.
                //
                // Literal paths MUST stay above the /{coupon} routes below.
                // Previously these sat underneath POST /{coupon}, so "validate"
                // and "redeem" were bound as route-model ids and always 404'd.
                Route::middleware('business:business.redeem')->group(function () {
                    Route::post('/validate', [CouponController::class, 'validate']);
                    Route::post('/redeem', [CouponController::class, 'redeem'])->middleware('throttle:redeem');
                });

                // whereNumber() keeps any future literal segment from binding as a model.
                Route::middleware('business:business.manage_offers')->group(function () {
                    Route::get('/{coupon}', [CouponController::class, 'show'])->whereNumber('coupon');
                    Route::put('/{coupon}', [CouponController::class, 'update'])->whereNumber('coupon');
                    Route::post('/{coupon}', [CouponController::class, 'update'])->whereNumber('coupon'); // For FormData with _method=PUT
                    Route::delete('/{coupon}', [CouponController::class, 'destroy'])->whereNumber('coupon');
                    Route::post('/{coupon}/toggle-featured', [CouponController::class, 'toggleFeatured'])->whereNumber('coupon');
                });
            });
        });

        // User notification routes (for all authenticated users)
        Route::prefix('notifications')->group(function () {
            Route::get('/', [UserNotificationController::class, 'index']);
            Route::get('/user/stats', [UserNotificationController::class, 'userStats']);
            Route::get('/types', [UserNotificationController::class, 'types']);
            Route::get('/preferences', [UserNotificationController::class, 'getPreferences']);
            Route::put('/preferences', [UserNotificationController::class, 'updatePreferences']);
            Route::get('/{notification}', [UserNotificationController::class, 'show']);
            Route::post('/{notification}/read', [UserNotificationController::class, 'markAsRead']);
            Route::post('/mark-all-read', [UserNotificationController::class, 'markAllAsRead']);
            Route::post('/push-subscription', [UserNotificationController::class, 'storePushSubscription']);
            Route::get('/push-subscription', [UserNotificationController::class, 'getPushSubscription']);
            Route::delete('/push-subscription', [UserNotificationController::class, 'deletePushSubscription']);
            Route::post('/test-push', [UserNotificationController::class, 'testPushNotification']);
            Route::post('/test', [UserNotificationController::class, 'sendTestNotification']);
        });

        // Business subscription routes (for all authenticated users)
        Route::prefix('subscriptions')->group(function () {
            Route::get('/check', [BusinessSubscriptionController::class, 'check']);
            Route::get('/current', [BusinessSubscriptionController::class, 'current']);
            Route::get('/plans', [BusinessSubscriptionController::class, 'plans']);
            Route::post('/cancel', [BusinessSubscriptionController::class, 'cancel']);
            Route::post('/claim-free', [BusinessSubscriptionController::class, 'claimFree']);
            Route::post('/free/update-expiry', [BusinessSubscriptionController::class, 'updateFreeExpiry']);
        });


        // Stripe routes (for all authenticated users)
        Route::prefix('stripe')->group(function () {
            Route::get('/config', [BusinessSubscriptionController::class, 'stripeConfig']);
            Route::post('/create-setup-intent', [BusinessSubscriptionController::class, 'createSetupIntent']);
            Route::post('/create-subscription', [BusinessSubscriptionController::class, 'createSubscription']);
            Route::post('/create-payment-intent', [BusinessSubscriptionController::class, 'createPaymentIntent']);
            Route::post('/payment-success', [BusinessSubscriptionController::class, 'handlePaymentSuccess']);
        });
    });

    // Search routes (public)
    Route::prefix('search')->middleware('throttle:search')->group(function () {
        Route::get('/', [SearchController::class, 'search']);
        Route::get('/suggestions', [SearchController::class, 'suggestions']);
    });
});

// Stripe webhook route (outside maintenance middleware).
// Deliberately exempt from throttling: Stripe retries aggressively and a 429
// would be recorded as a delivery failure.
Route::post('/stripe/webhook', [BusinessSubscriptionController::class, 'webhook'])
    ->withoutMiddleware('throttle:api');
