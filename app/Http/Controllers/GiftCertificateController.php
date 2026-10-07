<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\GiftCertificate;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\WalletCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Gift certificates, paid for at the business. The merchant side (behind
 * business.manage_offers) issues and cancels them and switches selling on;
 * a shopper can request one from a business that sells them, which waits
 * as pending_payment until staff mark it paid at the till.
 */
class GiftCertificateController extends Controller
{
    private const MIN_CENTS = 500;
    private const MAX_CENTS = 100000;

    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $rows = GiftCertificate::query()
            ->where('business_id', $business->id)
            ->orderByRaw("CASE WHEN status = 'pending_payment' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (GiftCertificate $g) => $g->only(['id', 'code', 'initial_cents', 'balance_cents', 'status', 'recipient_email', 'recipient_name', 'from_name', 'expires_at', 'created_at']) + ['status_note' => $g->statusNote()]);

        return response()->json(['status' => 'success', 'data' => [
            'sells_gift_certificates' => (bool) $business->sells_gift_certificates,
            'certificates' => $rows,
            'outstanding_cents' => (int) GiftCertificate::query()->where('business_id', $business->id)->where('status', 'active')->sum('balance_cents'),
        ]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate(['sells_gift_certificates' => ['required', 'boolean']]);
        $business->update($data);

        return response()->json(['status' => 'success', 'message' => $data['sells_gift_certificates'] ? 'Shoppers can now ask for gift certificates on your page.' : 'Gift certificates are hidden from your page.']);
    }

    /** Issue one that has been paid for at the counter. */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $this->validatedGift($request, withExpiry: true);

        $gift = GiftCertificate::create($data + [
            'business_id' => $business->id,
            'code' => WalletCode::unique('GIFT', 'gift_certificates'),
            'balance_cents' => $data['initial_cents'],
            'status' => 'active',
            'issued_by_user_id' => $request->user()->id,
            'paid_at' => now(),
            'recipient_user_id' => $this->accountFor($data['recipient_email'] ?? null),
        ]);
        $this->tellRecipient($gift);

        return response()->json(['status' => 'success', 'message' => 'Gift certificate issued.', 'data' => $gift], 201);
    }

    public function void(Request $request, int $gift): JsonResponse
    {
        $business = $this->business($request);
        $row = GiftCertificate::query()->where('business_id', $business->id)->findOrFail($gift);
        $row->update(['status' => 'void']);

        return response()->json(['status' => 'success', 'message' => 'Gift certificate cancelled.']);
    }

    /** A shopper asks for one; they pay when they show the code at the counter. */
    public function request(Request $request, int $businessId): JsonResponse
    {
        $business = Business::query()->whereKey($businessId)->first()
            ?? Business::query()->where('owner_user_id', $businessId)->first();
        abort_unless($business && $business->status === 'active' && $business->sells_gift_certificates, 404, 'This business does not sell gift certificates on Thryft.');

        $user = $request->user();
        $data = $this->validatedGift($request, withExpiry: false);
        $forSomeoneElse = !empty($data['recipient_email']) && strcasecmp($data['recipient_email'], $user->email) !== 0;

        $gift = GiftCertificate::create($data + [
            'business_id' => $business->id,
            'code' => WalletCode::unique('GIFT', 'gift_certificates'),
            'balance_cents' => $data['initial_cents'],
            'status' => 'pending_payment',
            'purchaser_user_id' => $user->id,
            // Until it is paid, it sits in the buyer's wallet so they can show it.
            'recipient_user_id' => $forSomeoneElse ? null : $user->id,
            'recipient_email' => $forSomeoneElse ? $data['recipient_email'] : $user->email,
            'from_name' => $data['from_name'] ?? ($forSomeoneElse ? $user->display_name : null),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Show the code at the counter and pay there to activate it.',
            'data' => $gift->load('business')->toWallet(),
        ], 201);
    }

    /** Called from the till once staff have taken the money. */
    public static function markPaid(GiftCertificate $gift): void
    {
        $gift->update([
            'status' => 'active',
            'paid_at' => now(),
            'recipient_user_id' => $gift->recipient_user_id ?? (new self())->accountFor($gift->recipient_email),
        ]);
        (new self())->tellRecipient($gift->fresh());
    }

    private function accountFor(?string $email): ?int
    {
        return $email
            ? User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->whereNotNull('email_verified_at')->value('id')
            : null;
    }

    private function tellRecipient(GiftCertificate $gift): void
    {
        if (!$gift->recipient_user_id || $gift->recipient_user_id === $gift->purchaser_user_id) {
            return;
        }
        try {
            $amount = '$' . number_format($gift->initial_cents / 100, 2);
            app(NotificationService::class)->send(
                title: "A {$amount} gift certificate for you",
                message: ($gift->from_name ? "From {$gift->from_name}: " : '') . "spend it at {$gift->business->name}. It's in your wallet.",
                type: 'success',
                userIds: [$gift->recipient_user_id],
                data: ['kind' => 'gift_certificate', 'action_url' => '/user/coupons?tab=cards'],
            );
        } catch (\Throwable $e) {
            Log::warning('Gift certificate notification failed', ['gift_id' => $gift->id, 'error' => $e->getMessage()]);
        }
    }

    private function validatedGift(Request $request, bool $withExpiry): array
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:' . self::MIN_CENTS / 100, 'max:' . self::MAX_CENTS / 100],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:500'],
        ] + ($withExpiry ? ['expires_at' => ['nullable', 'date', 'after:today']] : []));

        $data['initial_cents'] = (int) round($data['amount'] * 100);
        unset($data['amount']);

        return $data;
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
