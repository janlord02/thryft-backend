<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Merchant-facing numbers: does Thryft actually bring people through the door?
 *
 * The existing admin dashboard counts users and notifications, which tells a
 * business owner nothing. The funnel that matters to them lives in
 * claimed_coupons — claims, redemptions, and the ratio between them — and
 * until now nothing read it. ClaimedCoupon::scopeByBusiness() has existed
 * since the table was created and had no callers.
 *
 * Aggregates are grouped in SQL rather than counted per row in PHP. The admin
 * dashboard's 12-month loop issues twelve sequential COUNT queries; this does
 * one per series.
 */
class BusinessAnalytics
{
    public function __construct(
        private readonly Business $business,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {
    }

    public static function for(Business $business, ?string $from = null, ?string $to = null): self
    {
        // Default to the trailing 30 days, inclusive of today.
        $end = $to ? CarbonImmutable::parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $end->subDays(29)->startOfDay();

        // Tolerate a reversed range rather than silently returning nothing.
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self($business, $start, $end);
    }

    public function toArray(): array
    {
        $totals = $this->totals();

        return [
            'range' => [
                'from' => $this->from->toDateString(),
                'to' => $this->to->toDateString(),
            ],
            'totals' => $totals,
            'timeseries' => $this->timeseries(),
            'top_offers' => $this->topOffers(),
            'repeat_customers' => $this->repeatCustomers(),
        ];
    }

    /**
     * Claims, redemptions and the conversion between them.
     *
     * Redemptions are counted by used_at, not created_at: a coupon claimed in
     * January and redeemed in March is March's redemption. Counting both by
     * claim date would make the current period's rate look artificially low,
     * since its newest claims have not had time to be redeemed.
     */
    private function totals(): array
    {
        $claims = $this->scopedClaims()->whereBetween('created_at', [$this->from, $this->to])->count();

        $redemptions = $this->scopedClaims()
            ->where('status', 'used')
            ->whereBetween('used_at', [$this->from, $this->to])
            ->count();

        $activeOffers = Coupon::where('business_id', $this->business->id)
            ->active()
            ->valid()
            ->count();

        return [
            'claims' => $claims,
            'redemptions' => $redemptions,
            // Null rather than 0 when there were no claims: "no data" and
            // "nobody redeemed" are different things and a 0% badge on a brand
            // new business is just discouraging.
            'redemption_rate' => $claims > 0 ? round($redemptions / $claims * 100, 1) : null,
            'unique_customers' => (int) $this->scopedClaims()
                ->whereBetween('created_at', [$this->from, $this->to])
                ->distinct()
                ->count('user_id'),
            'active_offers' => $activeOffers,
        ];
    }

    /**
     * Daily claims and redemptions, zero-filled so the chart has no gaps.
     */
    private function timeseries(): array
    {
        $claims = $this->countByDay('created_at');
        $redemptions = $this->countByDay('used_at', fn ($q) => $q->where('status', 'used'));

        $series = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $key = $day->toDateString();

            $series[] = [
                'date' => $key,
                'claims' => $claims[$key] ?? 0,
                'redemptions' => $redemptions[$key] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * @return array<string, int> date => count
     */
    private function countByDay(string $column, ?callable $filter = null): array
    {
        $query = $this->scopedClaims()->whereBetween($column, [$this->from, $this->to]);

        if ($filter) {
            $filter($query);
        }

        // DATE() is portable across MySQL, MariaDB and SQLite, unlike the
        // DATE_FORMAT / YEAR()+MONTH() used elsewhere in this codebase.
        return $query
            ->selectRaw("DATE({$column}) as day, COUNT(*) as aggregate")
            ->groupBy('day')
            ->pluck('aggregate', 'day')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Which offers actually get used, not merely taken.
     */
    private function topOffers(int $limit = 5): array
    {
        return $this->scopedClaims()
            ->whereBetween('created_at', [$this->from, $this->to])
            ->select('coupon_id', 'coupon_title')
            ->selectRaw('COUNT(*) as claims')
            ->selectRaw("SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as redemptions")
            ->groupBy('coupon_id', 'coupon_title')
            ->orderByDesc('claims')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'coupon_id' => (int) $row->coupon_id,
                'title' => $row->coupon_title,
                'claims' => (int) $row->claims,
                'redemptions' => (int) $row->redemptions,
                'redemption_rate' => $row->claims > 0
                    ? round($row->redemptions / $row->claims * 100, 1)
                    : null,
            ])
            ->all();
    }

    /**
     * Customers who have REDEEMED more than once — the "are they coming back"
     * number. Counted on redemptions rather than claims because claiming is
     * free and proves nothing about a visit.
     *
     * Deliberately not limited to the date range: whether someone is a repeat
     * customer is a property of their whole history with the business.
     */
    private function repeatCustomers(): array
    {
        $counts = ClaimedCoupon::byBusiness($this->business->id)
            ->where('status', 'used')
            ->select('user_id')
            ->selectRaw('COUNT(*) as visits')
            ->groupBy('user_id')
            ->pluck('visits', 'user_id')
            ->map(fn ($v) => (int) $v);

        $repeat = $counts->filter(fn ($visits) => $visits > 1);

        return [
            'total_redeeming_customers' => $counts->count(),
            'repeat_customers' => $repeat->count(),
            'repeat_rate' => $counts->count() > 0
                ? round($repeat->count() / $counts->count() * 100, 1)
                : null,
        ];
    }

    /**
     * Claims belonging to this business.
     *
     * Uses the long-dormant scopeByBusiness(). business_id on claimed_coupons
     * already equals the business id — the Phase 3a backfill seeded
     * businesses.id from users.id precisely so values like this stayed valid.
     */
    private function scopedClaims()
    {
        return ClaimedCoupon::byBusiness($this->business->id);
    }
}
