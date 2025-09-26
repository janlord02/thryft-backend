<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Setting;
use App\Services\ActivityService;
use App\Mail\TwoFactorCodeMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        // Check if registration is enabled
        $registrationEnabled = Setting::getValue('registration_enabled', true);
        if (!$registrationEnabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'User registration is currently disabled.'
            ], 403);
        }

        // Get password requirements from settings
        $minPasswordLength = Setting::getValue('min_password_length', 8);
        $requireUppercase = Setting::getValue('require_uppercase', true);
        $requireLowercase = Setting::getValue('require_lowercase', true);
        $requireNumbers = Setting::getValue('require_numbers', true);
        $requireSymbols = Setting::getValue('require_symbols', false);

        // Build password validation rules
        $passwordRules = ['required', 'string', "min:{$minPasswordLength}", 'confirmed'];

        if ($requireUppercase) {
            $passwordRules[] = 'regex:/[A-Z]/';
        }
        if ($requireLowercase) {
            $passwordRules[] = 'regex:/[a-z]/';
        }
        if ($requireNumbers) {
            $passwordRules[] = 'regex:/[0-9]/';
        }
        if ($requireSymbols) {
            $passwordRules[] = 'regex:/[^A-Za-z0-9]/';
        }

        $validator = Validator::make($request->all(), [
            'firstname' => 'nullable|string|max:255',
            'lastname' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => $passwordRules,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'firstname' => $request->firstname,
            'lastname' => $request->lastname,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Check if email verification is required
        $emailVerificationRequired = Setting::getValue('email_verification', true);

        if ($emailVerificationRequired) {
            // Send verification email
            $user->sendEmailVerificationNotification();

            $message = 'User registered successfully. Please check your email for verification.';
        } else {
            // Mark email as verified if verification is not required
            $user->markEmailAsVerified();
            $message = 'User registered successfully.';
        }

        // Log user registration
        ActivityService::logUserRegistration($user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ], 201);
    }

    /**
     * Register business user
     */
    public function registerBusiness(Request $request): JsonResponse
    {
        // Get password requirements from settings
        $passwordSettings = Setting::getValue('password_requirements', [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_symbols' => false,
        ]);

        $passwordRules = ['required', 'string', 'min:' . $passwordSettings['min_length']];

        if ($passwordSettings['require_uppercase']) {
            $passwordRules[] = 'regex:/[A-Z]/';
        }
        if ($passwordSettings['require_lowercase']) {
            $passwordRules[] = 'regex:/[a-z]/';
        }
        if ($passwordSettings['require_numbers']) {
            $passwordRules[] = 'regex:/[0-9]/';
        }
        if ($passwordSettings['require_symbols']) {
            $passwordRules[] = 'regex:/[^A-Za-z0-9]/';
        }

        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => $passwordRules,
            'password_confirmation' => 'required|same:password',
            'business_name' => 'required|string|max:255',
            'business_description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zipcode' => 'required|string|max:20',
            'country' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'firstname' => $request->firstname,
            'lastname' => $request->lastname,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'business',
            'business_name' => $request->business_name,
            'business_description' => $request->business_description,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'zipcode' => $request->zipcode,
            'country' => $request->country,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        // Check if email verification is required
        $emailVerificationRequired = Setting::getValue('email_verification', true);

        if ($emailVerificationRequired) {
            $user->sendEmailVerificationNotification();
            $message = 'Business account created successfully. Please check your email to verify your account.';
        } else {
            $user->markEmailAsVerified();
            $message = 'Business account created successfully.';
        }

        // Create token for immediate login
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $credentials = $request->only(['email', 'password']);
            $remember = $request->boolean('remember', false);

            if (!Auth::attempt($credentials, $remember)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid credentials',
                ], 401);
            }

            $user = Auth::user();

            // Check if user is verified
            if (!$user->email_verified_at) {
                Auth::logout();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please verify your email address before logging in.',
                ], 403);
            }

            // Check if 2FA is enabled for the user
            if ($user->two_factor_enabled) {
                // Generate and send 2FA code
                $code = $this->generateAndSendTwoFactorCode($user);

                // Store 2FA data in cache for 2 minutes
                $cacheKey = "2fa_{$user->id}_{$user->email}";
                $cacheData = [
                    'user_id' => $user->id,
                    'code' => $code,
                    'email' => $user->email,
                    'created_at' => now()->timestamp,
                ];

                Cache::put($cacheKey, $cacheData, 120); // 2 minutes

                Log::info('2FA cache created', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'cache_key' => $cacheKey,
                    'cache_data' => $cacheData
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => '2FA code sent to your email',
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'role' => $user->role,
                        ],
                        'requires_2fa' => true,
                    ],
                ]);
            }

            // Normal login flow
            $token = $user->createToken('auth-token')->plainTextToken;
            $refreshToken = $user->createToken('refresh-token')->plainTextToken;

            // Log successful login
            ActivityService::logUserLogin($user, $request->ip());

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar' => $user->avatar,
                        'email_verified_at' => $user->email_verified_at,
                        'two_factor_enabled' => $user->two_factor_enabled,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
                    'token' => $token,
                    'refresh_token' => $refreshToken,
                    'requires_2fa' => false,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Login failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify 2FA code
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $code = $request->input('code');
            $email = $request->input('email');

            // Find user by email
            $user = User::where('email', $email)->first();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            // Get stored 2FA data from cache
            $cacheKey = "2fa_{$user->id}_{$email}";
            $cachedData = Cache::get($cacheKey);

            Log::info('2FA verification attempt', [
                'user_id' => $user->id,
                'email' => $email,
                'has_cached_data' => !empty($cachedData),
                'cache_key' => $cacheKey
            ]);

            if (!$cachedData) {
                Log::warning('2FA cache missing data', [
                    'user_id' => $user->id,
                    'email' => $email,
                    'cache_key' => $cacheKey
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => '2FA session expired. Please login again.',
                ], 401);
            }

            // Check if cache is expired (2 minutes)
            if ($cachedData['created_at'] && (now()->timestamp - $cachedData['created_at']) > 120) {
                Log::warning('2FA cache expired', [
                    'user_id' => $user->id,
                    'created_at' => $cachedData['created_at'],
                    'current_time' => now()->timestamp,
                    'elapsed_seconds' => now()->timestamp - $cachedData['created_at']
                ]);
                Cache::forget($cacheKey);
                return response()->json([
                    'status' => 'error',
                    'message' => '2FA session expired. Please login again.',
                ], 401);
            }

            // Verify the code
            if ($code !== $cachedData['code']) {
                Log::warning('2FA code verification failed', [
                    'user_id' => $user->id,
                    'provided_code' => $code,
                    'cached_code' => $cachedData['code']
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid verification code.',
                ], 401);
            }

            // Clear 2FA cache
            Cache::forget($cacheKey);

            // Complete login
            Auth::login($user);
            $token = $user->createToken('auth-token')->plainTextToken;
            $refreshToken = $user->createToken('refresh-token')->plainTextToken;

            // Log successful 2FA verification
            ActivityService::logUserLogin($user, $request->ip());

            Log::info('2FA verification successful', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'status' => 'success',
                'message' => '2FA verification successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar' => $user->avatar,
                        'email_verified_at' => $user->email_verified_at,
                        'two_factor_enabled' => $user->two_factor_enabled,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
                    'token' => $token,
                    'refresh_token' => $refreshToken,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('2FA verification error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => '2FA verification failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Resend 2FA code
     */
    public function resendTwoFactorCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $email = $request->input('email');

            // Find user by email
            $user = User::where('email', $email)->first();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            // Check if there's existing 2FA cache for this user
            $cacheKey = "2fa_{$user->id}_{$email}";
            $existingData = Cache::get($cacheKey);

            Log::info('2FA resend attempt', [
                'user_id' => $user->id,
                'email' => $email,
                'has_existing_cache' => !empty($existingData)
            ]);

            if (!$existingData) {
                Log::warning('2FA resend - no existing cache', [
                    'user_id' => $user->id,
                    'email' => $email
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => '2FA session expired. Please login again.',
                ], 401);
            }

            // Generate and send new 2FA code
            $code = $this->generateAndSendTwoFactorCode($user);

            // Update cache with new code
            $cacheData = [
                'user_id' => $user->id,
                'code' => $code,
                'email' => $user->email,
                'created_at' => now()->timestamp,
            ];

            Cache::put($cacheKey, $cacheData, 120); // 2 minutes

            Log::info('2FA code resent successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'cache_key' => $cacheKey
            ]);

            return response()->json([
                'status' => 'success',
                'message' => '2FA code resent successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Resend 2FA code error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to resend 2FA code. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate and send 2FA code
     */
    private function generateAndSendTwoFactorCode(User $user): string
    {
        // Generate 6-digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Send email with code
        Mail::to($user->email)->send(new TwoFactorCodeMail($code, $user->name));

        return $code;
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Token refreshed successfully',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        // Debug: Log the incoming data
        \Log::info('Profile update request data:', $request->all());

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'firstname' => 'sometimes|string|max:255',
            'lastname' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'sometimes|string|max:20',
            'bio' => 'sometimes|string|max:1000',
            'address' => 'sometimes|string|max:500',
            'city' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'zipcode' => 'sometimes|string|max:20',
            'country' => 'sometimes|string|max:100',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($request->only([
            'name',
            'firstname',
            'lastname',
            'email',
            'phone',
            'bio',
            'address',
            'city',
            'state',
            'zipcode',
            'country',
            'latitude',
            'longitude'
        ]));

        // Debug: Log what was actually saved
        $user->refresh();
        \Log::info('Profile updated successfully. User data:', [
            'id' => $user->id,
            'name' => $user->name,
            'address' => $user->address,
            'city' => $user->city,
            'state' => $user->state,
            'zipcode' => $user->zipcode,
            'country' => $user->country,
            'latitude' => $user->latitude,
            'longitude' => $user->longitude,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect'
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        // Revoke all tokens to force re-login
        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password changed successfully. Please login again.'
        ]);
    }

    /**
     * Update business profile
     */
    public function updateBusinessProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $request->user()->id,
            'business_name' => 'required|string|max:255',
            'business_description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zipcode' => 'required|string|max:20',
            'country' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Check if user is a business
        if ($user->role !== 'business') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only business users can update business profile'
            ], 403);
        }

        // Update user data
        $user->update($request->only([
            'firstname',
            'lastname',
            'email',
            'business_name',
            'business_description',
            'address',
            'city',
            'state',
            'zipcode',
            'country',
            'latitude',
            'longitude'
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Business profile updated successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    /**
     * Update personal profile
     */
    public function updatePersonalProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $request->user()->id,
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        $user->update($request->only([
            'firstname',
            'lastname',
            'email',
            'phone',
            'bio'
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Personal profile updated successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }
}
