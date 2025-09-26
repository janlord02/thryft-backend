<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    /**
     * Verify email address
     */
    public function verify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:users,id',
            'hash' => 'required|string',
            'expires' => 'nullable|string',
            'signature' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::find($request->id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.'
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Email already verified.',
                'data' => [
                    'user' => $user
                ]
            ]);
        }

        // Verify the hash (main security check)
        if (
            !hash_equals(
                sha1($user->getEmailForVerification()),
                $request->hash
            )
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid verification link.'
            ], 400);
        }

        $user->markEmailAsVerified();

        // Log email verification
        ActivityService::logEmailVerification($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully.',
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Verify email address (web route)
     */
    public function verifyWeb(Request $request)
    {
        $user = User::find($request->route('id'));
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:9000'), '/');

        if (!$user) {
            abort(404, 'User not found.');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($frontendUrl . '/auth/login?verified=already');
        }

        if (
            !hash_equals(
                sha1($user->getEmailForVerification()),
                $request->route('hash')
            )
        ) {
            return redirect($frontendUrl . '/auth/login?verified=invalid');
        }

        $user->markEmailAsVerified();

        // Log email verification
        ActivityService::logEmailVerification($user);

        return redirect($frontendUrl . '/auth/login?verified=success');
    }

    /**
     * Resend verification email
     */
    public function resend(Request $request): JsonResponse
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

        $user = User::where('email', $request->email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email already verified.'
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'status' => 'success',
            'message' => 'Verification email sent successfully.'
        ]);
    }
}

