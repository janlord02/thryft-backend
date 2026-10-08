<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Support\WalletCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loyalty programs: the merchant side (behind business.manage_offers), a
 * business's programs as a shopper or guest sees them, and joining one.
 * Stamping happens at the till; see TillController.
 */
class LoyaltyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $rows = LoyaltyProgram::query()
            ->where('business_id', $business->id)
            ->withCount('cards')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $row = LoyaltyProgram::create(['business_id' => $business->id] + $this->validated($request));

        return response()->json(['status' => 'success', 'message' => 'Loyalty card created.', 'data' => $row], 201);
    }

    public function update(Request $request, int $program): JsonResponse
    {
        $row = $this->rowOf($this->business($request), $program);
        $data = $this->validated($request);

        // Lowering the target under cards already part-way would strand them.
        if ($data['stamps_required'] < $row->stamps_required && $row->cards()->where('stamps', '>=', $data['stamps_required'])->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Some cards already have that many stamps. Keep the target, or start a new card.',
            ], 422);
        }

        $row->update($data);

        return response()->json(['status' => 'success', 'message' => 'Saved.', 'data' => $row->fresh()]);
    }

    /** Programs are switched off rather than deleted, so nobody's stamps vanish. */
    public function destroy(Request $request, int $program): JsonResponse
    {
        $row = $this->rowOf($this->business($request), $program);
        $row->cards()->exists() ? $row->update(['is_active' => false]) : $row->delete();

        return response()->json(['status' => 'success', 'message' => 'Loyalty card ended.']);
    }

    /** A business's live programs, with the caller's card on each when they have one. */
    public function forBusiness(Request $request, int $businessId): JsonResponse
    {
        $business = Business::fromAppId($businessId);

        if (!$business || $business->status !== 'active') {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $user = $request->user();
        $programs = LoyaltyProgram::query()->where('business_id', $business->id)->where('is_active', true)->get();
        $cards = $user
            ? LoyaltyCard::query()->where('user_id', $user->id)->whereIn('program_id', $programs->pluck('id'))->get()->keyBy('program_id')
            : collect();

        return response()->json(['status' => 'success', 'data' => $programs->map(fn (LoyaltyProgram $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'reward' => $p->reward,
            'stamps_required' => $p->stamps_required,
            'terms' => $p->terms,
            'card' => ($card = $cards->get($p->id)) ? [
                'code' => $card->code,
                'stamps' => $card->stamps,
                'rewards_available' => $card->rewards_available,
            ] : null,
        ])->values()]);
    }

    public function join(Request $request, int $program): JsonResponse
    {
        $row = LoyaltyProgram::query()->where('is_active', true)->findOrFail($program);
        abort_unless($row->business?->status === 'active', 404);

        $card = LoyaltyCard::query()->where('program_id', $row->id)->where('user_id', $request->user()->id)->first();
        if (!$card) {
            try {
                $card = LoyaltyCard::create([
                    'program_id' => $row->id,
                    'user_id' => $request->user()->id,
                    'code' => WalletCode::unique('LOY', 'loyalty_cards'),
                ]);
            } catch (QueryException) {
                // A double tap raced us to the unique (program, user) row.
                $card = LoyaltyCard::query()->where('program_id', $row->id)->where('user_id', $request->user()->id)->firstOrFail();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Card added to your wallet.',
            'data' => $card->load('program.business')->toWallet(),
        ], 201);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'reward' => ['required', 'string', 'max:160'],
            'stamps_required' => ['required', 'integer', 'min:2', 'max:50'],
            'terms' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function rowOf(Business $business, int $id): LoyaltyProgram
    {
        return LoyaltyProgram::query()->where('business_id', $business->id)->findOrFail($id);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
