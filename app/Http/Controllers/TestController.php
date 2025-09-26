<?php

namespace App\Http\Controllers;

use App\Models\ClaimedCoupon;
use Illuminate\Http\Request;

class TestController extends Controller
{
    /**
     * Test endpoint to simulate marking a coupon as used
     */
    public function testMarkAsUsed(Request $request)
    {
        try {
            $claimedCouponId = $request->get('claimedCouponId');

            if (!$claimedCouponId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'claimedCouponId is required',
                ], 400);
            }

            // Find the claimed coupon
            $claimedCoupon = ClaimedCoupon::find($claimedCouponId);

            if (!$claimedCoupon) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Coupon not found',
                ], 404);
            }

            // Mark as used
            $claimedCoupon->markAsUsed('Test: Marked as used via API');

            // Emit WebSocket event
            event(new \App\Events\CouponStatusChanged($claimedCoupon->fresh()));

            return response()->json([
                'status' => 'success',
                'message' => 'Coupon marked as used successfully',
                'data' => [
                    'claimedCoupon' => $claimedCoupon->fresh(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark coupon as used',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
