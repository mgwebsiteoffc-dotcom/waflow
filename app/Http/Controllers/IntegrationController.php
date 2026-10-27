<?php
namespace App\Http\Controllers;
use App\Models\Shop;
use App\Services\WhatifyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
class IntegrationController extends Controller {
 public function index(Request $request){$shop=$request->attributes->get('currentShop');if(!$shop&&$request->query('shop'))return redirect()->route('integrations.shopify.install',['shop'=>$request->query('shop')]);$accounts=$templates=[];$privacyRequests=collect();$whatifyError=null;if($shop?->whatify_api_key){try{$client=new WhatifyClient($shop);$accounts=$client->accounts();$templates=$client->templates();}catch(\Throwable $e){$whatifyError=$e->getMessage();}}if($shop)$privacyRequests=$shop->privacyRequests()->latest()->limit(10)->get();return view('integrations.index',compact('shop','accounts','templates','whatifyError','privacyRequests'));}
 public function startShopify(Request $request){
  $shop=strtolower((string)$request->query('shop',''));
  if(!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/',$shop))abort(400,'Open Whatify Flow from Shopify to begin installation.');
  if(!config('services.shopify.api_key')||!config('services.shopify.api_secret'))return redirect()->route('integrations.index')->with('error','Add SHOPIFY_API_KEY and SHOPIFY_API_SECRET to the server environment first.');
  $state=Str::random(48);$request->session()->put('shopify_oauth_state',$state);$request->session()->put('shopify_oauth_shop',$shop);
  $query=http_build_query(['client_id'=>config('services.shopify.api_key'),'scope'=>config('services.shopify.scopes'),'redirect_uri'=>rtrim(config('app.url'),'/').'/integrations/shopify/callback','state'=>$state]);
  return Redirect::away("https://{$shop}/admin/oauth/authorize?{$query}");
 }
 public function shopifyCallback(Request $request){
  if(!config('services.shopify.api_secret'))abort(503,'Shopify app credentials are missing.');
  $query=$request->query();$received=$query['hmac']??'';unset($query['hmac'],$query['signature']);ksort($query);$message=urldecode(http_build_query($query));$expected=hash_hmac('sha256',$message,config('services.shopify.api_secret'));
  if(!$received||!hash_equals($expected,$received))abort(401,'Invalid Shopify OAuth signature.');
  $shopDomain=strtolower((string)$request->query('shop'));if(!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/',$shopDomain))abort(400,'Invalid Shopify shop domain.');
  if($request->session()->pull('shopify_oauth_state')!==$request->query('state')||$request->session()->pull('shopify_oauth_shop')!==$shopDomain)abort(419,'Shopify OAuth state mismatch. Please retry installation.');
  $token=Http::asForm()->acceptJson()->timeout(20)->post("https://{$shopDomain}/admin/oauth/access_token",['client_id'=>config('services.shopify.api_key'),'client_secret'=>config('services.shopify.api_secret'),'code'=>$request->query('code'),'expiring'=>1]);
  if(!$token->successful()||!$token->json('access_token'))return redirect()->route('integrations.index')->with('error','Shopify access token exchange failed. Check app credentials and OAuth callback URL.');
  if(!$token->json('refresh_token')||!$token->json('expires_in')||!$token->json('refresh_token_expires_in'))return redirect()->route('integrations.index')->with('error','Shopify did not issue an expiring offline token. Confirm `expiring=1` is supported for this app and retry installation.');
  $tokenBody=$token->json();
  $shop=Shop::updateOrCreate(['shop_domain'=>$shopDomain],['access_token'=>$tokenBody['access_token'],'refresh_token'=>$tokenBody['refresh_token']??null,'access_token_expires_at'=>isset($tokenBody['expires_in'])?now()->addSeconds((int)$tokenBody['expires_in']):null,'refresh_token_expires_at'=>isset($tokenBody['refresh_token_expires_in'])?now()->addSeconds((int)$tokenBody['refresh_token_expires_in']):null,'granted_scopes'=>$tokenBody['scope']??null,'installed'=>true,'installed_at'=>now()]);
  $request->session()->regenerate();$request->session()->put('shop_id',$shop->id);
  return redirect()->route('dashboard')->with('success','Shopify connected. Webhooks are managed by the deployed Shopify app configuration.');
 }
 public function connectWhatify(Request $request){
  $data=$request->validate(['api_key'=>['required','string','max:500']]);$shop=$request->attributes->get('currentShop');abort_unless($shop,403,'Connect Shopify first.');
  $shop->whatify_api_key=$data['api_key'];
  try{$client=new WhatifyClient($shop);$accounts=$client->accounts();$client->templates();$shop->save();return back()->with('success','Whatify connected. Found '.count($accounts).' WhatsApp sender account(s).');}
  catch(\Throwable $e){throw ValidationException::withMessages(['api_key'=>'Whatify connection failed: '.$e->getMessage()]);}
 }
 public function disconnectWhatify(Request $request){$shop=$request->attributes->get('currentShop');if($shop){$shop->whatify_api_key=null;$shop->save();}return back()->with('success','Whatify credentials removed.');}
 public function testShopify(Request $request,\App\Services\OrderSync $sync){
  $shop=$request->attributes->get('currentShop');
  try{
   $info=$sync->testConnection($shop);
   return back()->with('success','Shopify connection OK — authenticated as "'.$info['name'].'" ('.$info['domain'].'). GraphQL Admin API is reachable with the stored token.');
  }catch(\Throwable $e){
   return back()->with('error','Shopify connection test failed: '.mb_substr($e->getMessage(),0,300).' Re-install the app from Shopify if the token was revoked or expired.');
  }
 }
 public function disconnectShopify(Request $request){$shop=$request->attributes->get('currentShop');if($shop){$shop->installed=false;$shop->access_token=null;$shop->refresh_token=null;$shop->access_token_expires_at=null;$shop->refresh_token_expires_at=null;$shop->whatify_api_key=null;$shop->save();$request->session()->forget('shop_id');}return redirect()->route('dashboard')->with('success','App connection removed. Uninstall the app from Shopify as well to revoke Shopify-side installation.');}
}
