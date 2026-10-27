<?php
use App\Http\Controllers\ApiWebhookController;
use Illuminate\Support\Facades\Route;
Route::post('/webhooks/shopify',[ApiWebhookController::class,'shopify'])->middleware('throttle:300,1')->name('webhooks.shopify');
Route::post('/webhooks/whatify/inbound',[ApiWebhookController::class,'whatify'])->middleware('throttle:300,1')->name('webhooks.whatify');
