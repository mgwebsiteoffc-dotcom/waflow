<?php
namespace App\Providers;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider {
 public function register():void{}
 public function boot():void{
  // The app ships its own CSS (no Tailwind), so use the styled custom paginator.
  Paginator::defaultView('vendor.pagination.default');
 }
}
