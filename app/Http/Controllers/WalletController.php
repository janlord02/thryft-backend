<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything a shopper holds that they show at a till.
 */
class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $loyalty = LoyaltyCard::query()
            ->where('user_id', $user->id)
            ->whereHas('program.business', fn ($q) => $q->where('status', 'active'))
            ->with('program.business.owner')
            ->orderByDesc('rewards_available')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (LoyaltyCard $c) => $c->toWallet());

        return response()->json(['status' => 'success', 'data' => [
            'loyalty' => $loyalty->values(),
        ]]);
    }
}
