<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\GiftCertificate;
use App\Models\LoyaltyCard;
use App\Models\Membership;
use App\Support\WalletCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Scan page's second job: a code a shopper shows for something they
 * hold — a loyalty card, a gift certificate or a membership.
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
            ['GIFT', 'paid'] => $this->giftPaid($row),
            ['GIFT', 'use'] => $this->giftUse($row, (int) $request->validate(['amount_cents' => ['required', 'integer', 'min:1']])['amount_cents'], $request->user()->id),
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

    private function giftPaid(GiftCertificate $gift): string
    {
        $moved = GiftCertificate::query()->whereKey($gift->id)->where('status', 'pending_payment')->update(['status' => 'active', 'updated_at' => now()]);
        abort_unless($moved, 422, 'This gift certificate is already paid for.');

        GiftCertificateController::markPaid($gift->fresh());

        return 'Paid. The gift certificate is live' . ($gift->recipient_name ? " and on its way to {$gift->recipient_name}." : '.');
    }

    private function giftUse(GiftCertificate $gift, int $cents, int $staffId): string
    {
        return DB::transaction(function () use ($gift, $cents, $staffId) {
            $gift = GiftCertificate::query()->lockForUpdate()->findOrFail($gift->id);
            abort_unless($gift->isUsable(), 422, $gift->statusNote() . '.');
            abort_if($cents > $gift->balance_cents, 422, 'That is more than is left on it ($' . number_format($gift->balance_cents / 100, 2) . ').');

            $gift->update(['balance_cents' => $gift->balance_cents - $cents]);
            DB::table('gift_certificate_uses')->insert(['gift_certificate_id' => $gift->id, 'amount_cents' => $cents, 'staff_user_id' => $staffId, 'created_at' => now()]);

            return '$' . number_format($cents / 100, 2) . ' taken off. $' . number_format($gift->balance_cents / 100, 2) . ' left.';
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
            'GIFT' => GiftCertificate::query()->where('code', $code)->where('business_id', $business->id)->with(['recipient', 'purchaser'])->first(),
            'MEM' => Membership::query()->where('code', $code)->where('business_id', $business->id)->with(['plan', 'user'])->first(),
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
            'GIFT' => [
                'kind' => 'gift',
                'code' => $row->code,
                'title' => ($row->recipient_name ?: 'Gift certificate') . ($row->from_name ? " · from {$row->from_name}" : ''),
                'customer' => ($row->recipient ?? $row->purchaser)?->display_name ?? $row->recipient_name,
                'initial_cents' => $row->initial_cents,
                'balance_cents' => $row->balance_cents,
                'status_note' => $row->status === 'pending_payment'
                    ? 'Take $' . number_format($row->initial_cents / 100, 2) . ', then mark it paid.'
                    : $row->statusNote(),
                'actions' => array_values(array_filter([
                    $row->status === 'pending_payment' ? ['action' => 'paid', 'label' => 'Payment taken — activate'] : null,
                    $row->isUsable() ? ['action' => 'use', 'label' => 'Take it off the bill', 'needs_amount' => true] : null,
                ])),
            ],
            'MEM' => [
                'kind' => 'membership',
                'code' => $row->code,
                'title' => $row->plan->name,
                'customer' => $row->user?->display_name,
                'is_active' => $row->isCurrent(),
                'status_note' => $row->statusNote(),
                'benefits' => $row->plan->benefits,
                'actions' => [],
            ],
        };
    }
}
