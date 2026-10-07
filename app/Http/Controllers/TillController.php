<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Support\WalletCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Scan page's second job: a code a shopper shows for something they
 * hold — a loyalty card today, gift certificates and memberships next.
 * Behind business:business.redeem, so front-of-house staff can use it.
 * A code from another business reads as not found.
 */
class TillController extends Controller
{
    /** Minimum gap between two stamps on one card: stops a double scan counting twice. */
    private const STAMP_COOLDOWN_SECONDS = 600;

    public function show(Request $request, string $code): JsonResponse
    {
        [$kind, $row] = $this->find($request, $code);

        return response()->json(['status' => 'success', 'data' => $this->view($kind, $row)]);
    }

    public function act(Request $request, string $code): JsonResponse
    {
        [$kind, $row] = $this->find($request, $code);
        $data = $request->validate(['action' => ['required', 'string', 'max:20']]);

        $message = match ([$kind, $data['action']]) {
            ['LOY', 'stamp'] => $this->stamp($row, $request->user()->id),
            ['LOY', 'redeem'] => $this->redeemReward($row, $request->user()->id),
            default => abort(422, 'That action does not apply to this code.'),
        };

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $this->view($kind, $row->fresh()),
        ]);
    }

    private function stamp(LoyaltyCard $card, int $staffId): string
    {
        return DB::transaction(function () use ($card, $staffId) {
            $card = LoyaltyCard::query()->lockForUpdate()->findOrFail($card->id);
            $program = $card->program;

            abort_unless($program->is_active, 422, 'This loyalty card has ended.');
            if ($card->last_stamp_at && $card->last_stamp_at->diffInSeconds(now()) < self::STAMP_COOLDOWN_SECONDS) {
                abort(429, 'This card was stamped a moment ago.');
            }

            $stamps = $card->stamps + 1;
            $earned = $stamps >= $program->stamps_required;
            $card->update([
                'stamps' => $earned ? 0 : $stamps,
                'rewards_available' => $card->rewards_available + ($earned ? 1 : 0),
                'last_stamp_at' => now(),
            ]);
            DB::table('loyalty_card_events')->insert(['card_id' => $card->id, 'staff_user_id' => $staffId, 'type' => 'stamp', 'created_at' => now()]);

            return $earned
                ? "Stamped. That completes the card — they've earned: {$program->reward}."
                : "Stamped. {$stamps} of {$program->stamps_required}.";
        });
    }

    private function redeemReward(LoyaltyCard $card, int $staffId): string
    {
        return DB::transaction(function () use ($card, $staffId) {
            $moved = LoyaltyCard::query()->whereKey($card->id)->where('rewards_available', '>', 0)
                ->update([
                    'rewards_available' => DB::raw('rewards_available - 1'),
                    'rewards_redeemed' => DB::raw('rewards_redeemed + 1'),
                    'updated_at' => now(),
                ]);
            abort_unless($moved, 422, 'There is no reward on this card yet.');
            DB::table('loyalty_card_events')->insert(['card_id' => $card->id, 'staff_user_id' => $staffId, 'type' => 'redeem', 'created_at' => now()]);

            return "Reward used: {$card->program->reward}.";
        });
    }

    /** @return array{0: string, 1: mixed} */
    private function find(Request $request, string $code): array
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        $code = WalletCode::normalize($code);
        $kind = WalletCode::prefixOf($code);

        $row = match ($kind) {
            'LOY' => LoyaltyCard::query()->where('code', $code)
                ->whereHas('program', fn ($q) => $q->where('business_id', $business->id))
                ->with(['program', 'user'])
                ->first(),
            default => null,
        };

        abort_unless($row, 404, 'No card with that code here.');

        return [$kind, $row];
    }

    private function view(string $kind, $row): array
    {
        return match ($kind) {
            'LOY' => [
                'kind' => 'loyalty',
                'code' => $row->code,
                'title' => $row->program->title,
                'customer' => $row->user?->display_name,
                'stamps' => $row->stamps,
                'stamps_required' => $row->program->stamps_required,
                'reward' => $row->program->reward,
                'rewards_available' => $row->rewards_available,
                'actions' => array_values(array_filter([
                    $row->program->is_active ? ['action' => 'stamp', 'label' => 'Add a stamp'] : null,
                    $row->rewards_available > 0 ? ['action' => 'redeem', 'label' => 'Give the reward'] : null,
                ])),
            ],
        };
    }
}
