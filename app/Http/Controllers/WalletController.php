<?php

namespace App\Http\Controllers;

use App\Models\GiftCertificate;
use App\Models\LoyaltyCard;
use App\Models\Membership;
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

        // A certificate sent to this email before the account existed is
        // picked up now — only by a verified address, so nobody can sign up
        // with someone else's email to take it.
        if ($user->email_verified_at) {
            GiftCertificate::query()
                ->whereNull('recipient_user_id')
                ->where('status', 'active')
                ->whereRaw('LOWER(recipient_email) = ?', [strtolower($user->email)])
                ->update(['recipient_user_id' => $user->id, 'updated_at' => now()]);
        }

        $gifts = GiftCertificate::query()
            ->where(fn ($q) => $q->where('recipient_user_id', $user->id)
                ->orWhere(fn ($q) => $q->where('purchaser_user_id', $user->id)->where('status', 'pending_payment')))
            ->where('status', '!=', 'void')
            ->with('business.owner')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (GiftCertificate $g) => $g->status === 'pending_payment' || $g->isUsable())
            ->map(fn (GiftCertificate $g) => $g->toWallet());

        $memberships = Membership::query()
            ->where('user_id', $user->id)
            ->where('ends_at', '>', now()->subDays(30))
            ->with(['plan', 'business.owner'])
            ->orderByDesc('ends_at')
            ->get()
            ->map(fn (Membership $m) => $m->toWallet());

        return response()->json(['status' => 'success', 'data' => [
            'memberships' => $memberships->values(),
            'gifts' => $gifts->values(),
            'loyalty' => $loyalty->values(),
        ]]);
    }
}
