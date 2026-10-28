<?php
use App\Http\Controllers\ApiWebhookController;
use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;
Route::post('/webhooks/shopify',[ApiWebhookController::class,'shopify'])->middleware('throttle:300,1')->name('webhooks.shopify');
Route::post('/webhooks/whatify/inbound',[ApiWebhookController::class,'whatify'])->middleware('throttle:300,1')->name('webhooks.whatify');
// Public storefront widget: no session, identified by the shop domain in the URL.
Route::get('/widget/{shopDomain}/widget.js',[WidgetController::class,'script'])->middleware('throttle:120,1')->name('widget.script');
Route::post('/widget/{shopDomain}/capture',[WidgetController::class,'capture'])->middleware('throttle:30,1')->name('widget.capture');
