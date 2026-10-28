<?php
namespace App\Http\Controllers;
use App\Models\Shop;
use App\Services\ShopifyBilling;
use Illuminate\Http\Request;
class BillingController extends Controller {
 public function index(Request $r, ShopifyBilling $billing){
  $shop = $r->attributes->get('currentShop');
  $price = number_format((float) config('services.shopify.one_time_price'), 2);
  $currency = config('services.shopify.billing_currency');
  $testMode = (bool) config('services.shopify.billing_test_mode');
  $localDriver = config('services.shopify.billing_driver') === 'local';
  return view('billing.index', compact('shop','price','currency','testMode','localDriver'));
 }

 public function start(Request $r, ShopifyBilling $billing){
  $shop = $r->attributes->get('currentShop');
  if ($shop->billing_status === 'active' && $shop->billing_confirmed_at) {
   return redirect()->route('dashboard')->with('success','Payment already confirmed — the app is fully unlocked.');
  }
  try {
   $charge = $billing->createCharge($shop, route('billing.callback', absolute: true));
   $shop->update([
    'billing_status' => 'pending',
    'billing_charge_id' => $charge['charge_id'] ?? $shop->billing_charge_id,
    'billing_amount' => config('services.shopify.one_time_price'),
    'billing_currency' => config('services.shopify.billing_currency'),
   ]);
   return redirect()->away($charge['confirmation_url']);
  } catch (\Throwable $e) {
   return back()->with('error','Could not start the Shopify payment: '.mb_substr($e->getMessage(),0,300));
  }
 }

 public function callback(Request $r, ShopifyBilling $billing){
  $shop = $r->attributes->get('currentShop');
  try {
   $paid = $billing->verifyPayment($shop, forceRefresh: true);
  } catch (\Throwable $e) {
   return redirect()->route('billing.index')->with('error','We could not confirm your payment with Shopify yet. Please try again — you will not be charged twice.');
  }
  if (!$paid) {
   return redirect()->route('billing.index')->with('warning','Payment is still pending. Complete the Shopify approval to unlock the app.');
  }
  return redirect()->route('dashboard')->with('success','Payment confirmed. Whatify Flow is unlocked for this store — one-time charge, no subscription.');
 }
}
