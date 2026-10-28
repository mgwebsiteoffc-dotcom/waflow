<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PrivacyRequestController;
use App\Http\Controllers\WebhookEventController;
use App\Http\Controllers\WidgetController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/integrations', [IntegrationController::class,'index'])->name('integrations.index');
Route::get('/integrations/shopify/install', [IntegrationController::class,'startShopify'])->middleware('throttle:10,1')->name('integrations.shopify.install');
Route::get('/integrations/shopify/callback', [IntegrationController::class,'shopifyCallback'])->middleware('throttle:20,1')->name('integrations.shopify.callback');
Route::post('/integrations/whatify/connect', [IntegrationController::class,'connectWhatify'])->middleware('shop.connected')->middleware('throttle:10,1')->name('integrations.whatify.connect');
Route::post('/integrations/shopify/test', [IntegrationController::class,'testShopify'])->middleware('shop.connected')->middleware('throttle:10,1')->name('integrations.shopify.test');
Route::delete('/integrations/whatify', [IntegrationController::class,'disconnectWhatify'])->middleware('shop.connected')->name('integrations.whatify.disconnect');
Route::delete('/integrations/shopify', [IntegrationController::class,'disconnectShopify'])->middleware('shop.connected')->name('integrations.shopify.disconnect');

Route::middleware('shop.connected')->group(function(){
 Route::get('/automations/presets',[WorkflowController::class,'presets'])->name('automations.presets');
 Route::post('/automations/from-preset',[WorkflowController::class,'fromPreset'])->name('automations.from-preset');
 Route::get('/automations/health',[WorkflowController::class,'health'])->name('automations.health');
 Route::get('/automations',[WorkflowController::class,'index'])->name('automations.index');
 Route::post('/automations',[WorkflowController::class,'create'])->name('automations.create');
 Route::get('/automations/{workflow}/edit',[WorkflowController::class,'edit'])->name('automations.edit');
 Route::put('/automations/{workflow}',[WorkflowController::class,'update'])->name('automations.update');
 Route::post('/automations/{workflow}/publish',[WorkflowController::class,'publish'])->name('automations.publish');
 Route::post('/automations/{workflow}/toggle',[WorkflowController::class,'toggle'])->name('automations.toggle');
 Route::delete('/automations/{workflow}',[WorkflowController::class,'destroy'])->name('automations.destroy');
 Route::get('/executions',[WorkflowController::class,'executions'])->name('executions.index');
 Route::get('/privacy-requests/{privacyRequest}/export',[PrivacyRequestController::class,'download'])->name('privacy.export');
 Route::get('/widget',[WidgetController::class,'edit'])->name('widget.edit');
 Route::put('/widget',[WidgetController::class,'update'])->name('widget.update');
 Route::get('/orders',[OrderController::class,'index'])->name('orders.index');
 Route::post('/orders/sync',[OrderController::class,'sync'])->name('orders.sync');
 Route::get('/webhooks',[WebhookEventController::class,'index'])->name('webhooks.index');
 Route::post('/orders/{order}/decision',[OrderController::class,'decide'])->name('orders.decide');
 Route::get('/analytics',function(\Illuminate\Http\Request $r){$shop=$r->attributes->get('currentShop');$total=$shop->verifications()->count();$confirmed=$shop->verifications()->where('status','confirmed')->count();$pending=$shop->verifications()->whereIn('status',['pending','reminded'])->count();$runs=$shop->executions()->whereDate('created_at',today())->count();$messages=$shop->messageLogs()->whereDate('created_at',today())->count();return view('analytics',compact('shop','total','confirmed','pending','runs','messages'));})->name('analytics');
});
