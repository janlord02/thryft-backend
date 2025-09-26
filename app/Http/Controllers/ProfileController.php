<?php

namespace App\Http\Controllers;

use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Setting;

class ProfileController extends Controller
{
    /**
     * Get user profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Update user profile
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'firstname' => 'sometimes|nullable|string|max:255',
            'lastname' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $request->user()->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'bio' => 'sometimes|nullable|string|max:1000',
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'zipcode' => 'sometimes|nullable|string|max:20',
            'country' => 'sometimes|nullable|string|max:100',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
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

        // Log profile update
        ActivityService::logProfileUpdate($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    /**
     * Upload profile image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        Log::info('Profile image upload started', [
            'user_id' => $request->user()->id,
            'has_file' => $request->hasFile('image'),
            'file_name' => $request->file('image')?->getClientOriginalName(),
            'file_size' => $request->file('image')?->getSize(),
        ]);

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($validator->fails()) {
            Log::error('Profile image upload validation failed', [
                'errors' => $validator->errors()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Delete old image if exists
        if ($user->profile_image) {
            Log::info('Deleting old profile image', ['old_path' => $user->profile_image]);
            Storage::disk('public')->delete($user->profile_image);
        }

        // Store new image
        $image = $request->file('image');
        $filename = 'profile-images/' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();

        Log::info('Attempting to store image', [
            'filename' => $filename,
            'original_name' => $image->getClientOriginalName(),
            'size' => $image->getSize(),
            'mime_type' => $image->getMimeType(),
        ]);

        try {
            $path = $image->storeAs($filename, '', 'public');
            Log::info('Image stored successfully', ['path' => $path]);
        } catch (\Exception $e) {
            Log::error('Failed to store image', [
                'error' => $e->getMessage(),
                'filename' => $filename
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to store image: ' . $e->getMessage()
            ], 500);
        }

        // Check if file actually exists
        if (!Storage::disk('public')->exists($filename)) {
            Log::error('File does not exist after storage', ['filename' => $filename]);
            return response()->json([
                'status' => 'error',
                'message' => 'File was not stored properly'
            ], 500);
        }

        $user->update(['profile_image' => $filename]);

        Log::info('Profile image upload completed', [
            'user_id' => $user->id,
            'profile_image' => $filename,
            'file_exists' => Storage::disk('public')->exists($filename),
            'file_size' => Storage::disk('public')->size($filename),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile image uploaded successfully',
            'data' => [
                'user' => $user->fresh(),
                'image_url' => $user->profile_image_url
            ]
        ]);
    }

    /**
     * Remove profile image
     */
    public function removeImage(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
            $user->update(['profile_image' => null]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profile image removed successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
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
            'current_password' => 'required|string',
            'password' => $passwordRules,
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

        $user->update(['password' => Hash::make($request->password)]);

        // Log password change
        ActivityService::logPasswordChange($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Enable 2FA
     */
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'status' => 'error',
                'message' => '2FA is already enabled'
            ], 400);
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true
        ]);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        // Generate QR code as base64 image
        $qrCodeImage = QrCode::generate($qrCodeUrl);

        return response()->json([
            'status' => 'success',
            'message' => '2FA setup initiated',
            'data' => [
                'secret' => $secret,
                'qr_code_image' => 'data:image/svg+xml;base64,' . base64_encode($qrCodeImage),
                'qr_code_url' => $qrCodeUrl
            ]
        ]);
    }

    /**
     * Confirm 2FA setup
     */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => '2FA setup not initiated'
            ], 400);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (!$valid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid 2FA code'
            ], 400);
        }

        $user->update(['two_factor_confirmed_at' => now()]);

        // Log 2FA enable
        ActivityService::logTwoFactorEnable($user);

        return response()->json([
            'status' => 'success',
            'message' => '2FA enabled successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    /**
     * Disable 2FA
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        // Debug: Log the current 2FA status
        Log::info('2FA Disable Request', [
            'user_id' => $user->id,
            'two_factor_enabled' => $user->two_factor_enabled,
            'two_factor_secret' => $user->two_factor_secret ? 'exists' : 'null',
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at,
            'hasTwoFactorEnabled' => $user->hasTwoFactorEnabled()
        ]);

        // Allow disabling if 2FA is enabled (even if not confirmed) or if there's a secret
        if (!$user->two_factor_enabled && !$user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => '2FA is not enabled'
            ], 400);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null
        ]);

        // Log 2FA disable
        ActivityService::logTwoFactorDisable($user);

        return response()->json([
            'status' => 'success',
            'message' => '2FA disabled successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    /**
     * Get 2FA status
     */
    public function getTwoFactorStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'two_factor_setup' => $user->two_factor_enabled && !$user->two_factor_confirmed_at
            ]
        ]);
    }

    /**
     * Generate QR code for 2FA
     */
    public function generateQrCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => '2FA setup not initiated'
            ], 400);
        }

        $google2fa = new Google2FA();
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $user->two_factor_secret
        );

        // Generate QR code as base64 image
        $qrCodeImage = QrCode::generate($qrCodeUrl);

        return response()->json([
            'status' => 'success',
            'data' => [
                'qr_code_image' => 'data:image/svg+xml;base64,' . base64_encode($qrCodeImage),
                'qr_code_url' => $qrCodeUrl
            ]
        ]);
    }

    /**
     * Cancel 2FA setup
     */
    public function cancelTwoFactorSetup(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only allow canceling if 2FA is enabled but not confirmed
        if (!$user->two_factor_enabled || $user->two_factor_confirmed_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot cancel 2FA setup'
            ], 400);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null
        ]);

        return response()->json([
            'status' => 'success',
            'message' => '2FA setup canceled successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }
}
