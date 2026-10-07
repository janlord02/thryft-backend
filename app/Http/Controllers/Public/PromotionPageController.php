<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PromotionController;
use App\Models\Promotion;
use Illuminate\Support\Str;

/**
 * The crawlable page for a joint promotion: /p/{slug}. Live and ended
 * promotions render; drafts are a 404.
 */
class PromotionPageController extends Controller
{
    public function show(string $slug)
    {
        $promotion = Promotion::query()->where('slug', $slug)->whereIn('status', ['live', 'ended'])->firstOrFail();
        abort_if($promotion->organizer?->status !== 'active', 404);

        $data = PromotionController::detail($promotion, null);

        return view('public.promotion', [
            'promotion' => $data,
            'metaTitle' => $promotion->title . ' — Thryft',
            'metaDescription' => Str::limit(trim(($promotion->description ?: $promotion->title) . ' With ' . implode(', ', $data['business_names']) . '.'), 160),
            'ogType' => 'website',
            'ogImage' => $data['image_url'],
            'appUrl' => rtrim(config('app.frontend_url'), '/') . '/user/promotions/' . $promotion->slug,
        ]);
    }
}
