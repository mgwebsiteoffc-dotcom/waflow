<?php
namespace App\Http\Controllers;
use App\Models\WorkflowExecution;
use Illuminate\Http\Request;
class DashboardController extends Controller {
 public function __invoke(Request $request){
  $shop=$request->attributes->get('currentShop');if(!$shop&&$request->query('shop'))return redirect()->route('integrations.shopify.install',['shop'=>$request->query('shop')]);$stats=['orders'=>0,'confirmed'=>0,'pending'=>0,'review'=>0,'executions'=>0,'messages'=>0];$orders=collect();$workflows=collect();$recentRuns=collect();
  if($shop){$stats['orders']=$shop->verifications()->count();$stats['confirmed']=$shop->verifications()->where('status','confirmed')->count();$stats['pending']=$shop->verifications()->whereIn('status',['pending','reminded'])->count();$stats['review']=$shop->verifications()->where('status','review')->count();$stats['executions']=$shop->executions()->whereDate('created_at',today())->count();$stats['messages']=$shop->hasMany(\App\Models\MessageLog::class)->whereDate('created_at',today())->count();$orders=$shop->verifications()->latest()->limit(6)->get();$workflows=$shop->workflows()->withCount('executions')->latest()->limit(5)->get();$recentRuns=$shop->executions()->with('workflow')->latest()->limit(6)->get();}
  $rate=$stats['orders']?round(($stats['confirmed']/$stats['orders'])*100,1):0;
  $health=$shop?app(\App\Support\SetupHealth::class)->build($shop):null;
  return view('dashboard',compact('shop','stats','orders','workflows','recentRuns','rate','health'));
 }
}
