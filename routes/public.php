<?php

use App\Http\Controllers\Public\BusinessPageController;
use App\Http\Controllers\Public\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public, crawlable pages
|--------------------------------------------------------------------------
|
| Server-rendered on the apex domain. Unauthenticated by design — these are
| the pages search engines index and that people share.
|
| Short prefixes (/b, /e, /c) keep shared URLs tidy and leave the top-level
| namespace free for marketing pages.
|
*/

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('public.sitemap');

Route::prefix('b')->group(function () {
    Route::get('/{business}', [BusinessPageController::class, 'show'])->name('public.business');
    Route::get('/{business}/deals/{couponSlug}', [BusinessPageController::class, 'deal'])->name('public.deal');
});
