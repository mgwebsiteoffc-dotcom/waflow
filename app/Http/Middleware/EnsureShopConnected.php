<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureShopConnected {
 public function handle(Request $request, Closure $next): Response {
  if (!$request->attributes->get('currentShop')) return redirect()->route('dashboard')->with('error','Connect your Shopify store before using this feature.');
  return $next($request);
 }
}
