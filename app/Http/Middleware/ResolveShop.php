<?php
namespace App\Http\Middleware;
use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class ResolveShop {
 public function handle(Request $request, Closure $next): Response {
  $shop = null;
  if ($id = $request->session()->get('shop_id')) $shop = Shop::whereKey($id)->where('installed',true)->first();
  if ($shop) { $request->attributes->set('currentShop',$shop); if ($request->session()->get('shop_id') !== $shop->id) $request->session()->put('shop_id',$shop->id); }
  \Illuminate\Support\Facades\View::share('currentShop',$shop);
  return $next($request);
 }
}
