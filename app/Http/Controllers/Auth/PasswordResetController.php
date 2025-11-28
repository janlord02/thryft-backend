<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset link sent to your email address.'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unable to send password reset link.'
        ], 400);
    }

    /**
     * Validate password reset token
     */
    public function validateToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
                'error_type' => 'invalid_user'
            ], 404);
        }

        // Check if token exists in password_reset_tokens table
        // Laravel stores tokens hashed, so we need to check all tokens for this email
        $tokenRecords = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->get();

        $tokenValid = false;
        $tokenRecord = null;

        foreach ($tokenRecords as $record) {
            if (Hash::check($request->token, $record->token)) {
                $tokenValid = true;
                $tokenRecord = $record;
                break;
            }
        }

        if (!$tokenValid) {
            // Check if password was recently changed (within last 24 hours)
            // This might indicate the token was already used
            if ($user->updated_at && $user->updated_at->gt(now()->subHours(24))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This password reset link has already been used. Your password has already been changed.',
                    'error_type' => 'already_used'
                ], 400);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid password reset token. Please request a new password reset link.',
                'error_type' => 'invalid_token'
            ], 400);
        }

        // Check if token is expired (default is 60 minutes)
        $tokenAge = now()->diffInMinutes(\Carbon\Carbon::parse($tokenRecord->created_at));
        $expirationMinutes = config('auth.passwords.users.expire', 60);
        
        if ($tokenAge > $expirationMinutes) {
            return response()->json([
                'status' => 'error',
                'message' => 'This password reset link has expired. Please request a new password reset link.',
                'error_type' => 'expired'
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Token is valid.'
        ]);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully.'
            ]);
        }

        // Handle specific error cases
        if ($status === Password::INVALID_TOKEN) {
            // Check if password was already changed
            $user = User::where('email', $request->email)->first();
            if ($user && $user->updated_at && $user->updated_at->gt(now()->subHours(24))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This password reset link has already been used. Your password has already been changed.',
                    'error_type' => 'already_used'
                ], 400);
            }

            // Check if token is expired
            $tokenRecord = \DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();
            
            if ($tokenRecord) {
                $tokenAge = now()->diffInMinutes($tokenRecord->created_at);
                $expirationMinutes = config('auth.passwords.users.expire', 60);
                
                if ($tokenAge > $expirationMinutes) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This password reset link has expired. Please request a new password reset link.',
                        'error_type' => 'expired'
                    ], 400);
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid password reset token. Please request a new password reset link.',
                'error_type' => 'invalid_token'
            ], 400);
        }

        if ($status === Password::INVALID_USER) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
                'error_type' => 'invalid_user'
            ], 404);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unable to reset password. Please check your token and try again.',
            'error_type' => 'unknown'
        ], 400);
    }
}
