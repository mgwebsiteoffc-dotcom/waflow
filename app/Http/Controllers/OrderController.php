<?php
namespace App\Http\Controllers;
use App\Models\OrderVerification;
use App\Services\ShopifyAdmin;
use Illuminate\Http\Request;
class OrderController extends Controller {
 public function index(Request $r){
  $shop=$r->attributes->get('currentShop');
  $q=$shop->verifications()->latest();
  if($term=$r->query('q'))$q->where(fn($x)=>$x->where('order_name','like','%'.$term.'%')->orWhere('customer_name','like','%'.$term.'%'));
  $orders=$q->paginate(25)->withQueryString();
  $webhookCount=$shop->webhookEvents()->count();
  $lastWebhookAt=$shop->webhookEvents()->latest('created_at')->value('created_at');
  $lastOrderWebhookAt=$shop->webhookEvents()->where('topic','ORDERS_CREATE')->latest('created_at')->value('created_at');
  return view('orders.index',compact('shop','orders','webhookCount','lastWebhookAt','lastOrderWebhookAt'));
 }
 public function decide(Request $r,OrderVerification $order,ShopifyAdmin $admin){$shop=$r->attributes->get('currentShop');abort_unless($order->shop_id===$shop->id,404);$data=$r->validate(['decision'=>'required|in:confirmed,review']);$gid=str_starts_with($order->shopify_order_id,'gid://')?$order->shopify_order_id:'gid://shopify/Order/'.$order->shopify_order_id;$tag=$data['decision']==='confirmed'?'whatify_cod_confirmed':'whatify_needs_review';$result=$admin->addOrderTag($shop,$gid,$tag);$errors=$result['tagsAdd']['userErrors']??[];if($errors)return back()->with('error',$errors[0]['message']??'Shopify rejected order tag.');$order->update(['status'=>$data['decision'],'response'=>'staff_'.$data['decision'],'response_at'=>now()]);return back()->with('success',$data['decision']==='confirmed'?'Order marked confirmed and tagged in Shopify. Payment status was not changed.':'Order flagged for staff review.');}
}
