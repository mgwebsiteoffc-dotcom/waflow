<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureBilled {
 public function handle(Request $request, Closure $next): Response {
  if (!config('services.shopify.billing_enabled')) return $next($request);
  $shop = $request->attributes->get('currentShop');
  if (!$shop) return $next($request);
  if ($shop->billing_status === 'active' && $shop->billing_confirmed_at) return $next($request);
  if ($request->expectsJson()) {
   return response()->json(['message' => 'Complete the one-time app payment to continue.'], 402);
  }
  return redirect()->route('billing.index')->with('warning', 'Complete the one-time $1 payment to unlock the app.');
 }
}
