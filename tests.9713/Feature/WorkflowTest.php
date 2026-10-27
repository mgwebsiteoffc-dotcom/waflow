<?php
namespace Tests\Feature;
use App\Models\Shop;
use App\Models\Workflow;
use App\Models\WhatsAppConsent;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use App\Models\MessageLog;
use App\Services\WorkflowRunner;
use App\Services\PhoneIdentity;
use App\Services\ShopifyAdmin;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
class WorkflowTest extends TestCase {
 use RefreshDatabase;
 private function shop():Shop{return Shop::create(['shop_domain'=>'demo.myshopify.com','access_token'=>'test-token','installed'=>true]);}
 public function test_dashboard_renders_the_laravel_application():void{$this->get('/')->assertOk()->assertSee('Whatify Flow')->assertSee('Install from Shopify')->assertDontSee('your-store.myshopify.com');}
 public function test_workflow_draft_is_saved_and_published_as_an_immutable_version():void{
  $shop=$this->shop();$workflow=$shop->workflows()->create(['name'=>'COD verification']);$this->withSession(['shop_id'=>$shop->id]);
  $graph=['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Order created','x'=>20,'y'=>20,'config'=>['topic'=>'ORDERS_CREATE']],['id'=>'stop','type'=>'stop','title'=>'Stop','x'=>20,'y'=>150,'config'=>[]]],'edges'=>[['source'=>'trigger','target'=>'stop']]];
  $this->putJson(route('automations.update',$workflow),['name'=>'COD verification','description'=>'Verify COD orders','graph'=>$graph])->assertOk()->assertJson(['saved'=>true]);
  $this->post(route('automations.publish',$workflow))->assertRedirect();$workflow->refresh();$this->assertTrue($workflow->enabled);$this->assertDatabaseHas('workflow_versions',['workflow_id'=>$workflow->id,'version'=>1]);
  $draft=$graph;$draft['nodes'][0]['title']='Changed draft';$this->putJson(route('automations.update',$workflow),['name'=>'COD verification','description'=>'Updated draft','graph'=>$draft])->assertOk();
  $this->assertSame('Order created',$workflow->publishedVersion()->first()->graph['nodes'][0]['title']);
 }
 public function test_workflow_builder_view_renders_for_the_current_shop():void{$shop=$this->shop();$workflow=$shop->workflows()->create(['name'=>'Builder test','draft_graph'=>['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Order created','x'=>20,'y'=>20,'config'=>['topic'=>'ORDERS_CREATE']]],'edges'=>[]]]);$this->withSession(['shop_id'=>$shop->id])->get(route('automations.edit',$workflow))->assertOk()->assertSee('Step settings')->assertSee('Publish workflow');}
 public function test_workflow_will_not_send_a_whatsapp_template_without_recorded_opt_in():void{
  Http::fake();$shop=$this->shop();$workflow=$shop->workflows()->create(['name'=>'Opt-in gate']);$graph=['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Order created','config'=>['topic'=>'ORDERS_CREATE']],['id'=>'send','type'=>'send_template','title'=>'Send','config'=>['template_name'=>'order_notice','phone'=>'{{order.customer.phone}}']],['id'=>'stop','type'=>'stop','title'=>'Stop','config'=>[]]],'edges'=>[['source'=>'trigger','target'=>'send'],['source'=>'send','target'=>'stop']]];$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>$graph,'published_at'=>now()]);
  $execution=WorkflowExecution::create(['shop_id'=>$shop->id,'workflow_id'=>$workflow->id,'workflow_version_id'=>$version->id,'trigger_topic'=>'ORDERS_CREATE','trigger_payload'=>['customer'=>['phone'=>'+12025550198']],'current_node_id'=>'trigger','status'=>'queued']);
  app(WorkflowRunner::class)->run($execution->id);$this->assertDatabaseHas('message_logs',['workflow_execution_id'=>$execution->id,'status'=>'skipped_no_opt_in']);$this->assertSame('completed',$execution->fresh()->status);Http::assertNothingSent();
 }
 public function test_whatify_stop_reply_records_opt_out_and_stops_waiting_runs():void{
  config(['services.whatify.webhook_secret'=>'test-whatify-secret']);$shop=$this->shop();$phone='+1 202 555 0198';$hash=PhoneIdentity::hash($phone);
  $consent=WhatsAppConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'test','consented_at'=>now()]);
  $workflow=$shop->workflows()->create(['name'=>'Test']);$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>['nodes'=>[],'edges'=>[]],'published_at'=>now()]);
  $execution=WorkflowExecution::create(['shop_id'=>$shop->id,'workflow_id'=>$workflow->id,'workflow_version_id'=>$version->id,'trigger_topic'=>'ORDERS_CREATE','customer_phone_hash'=>$hash,'trigger_payload'=>[],'status'=>'waiting']);
  $body=json_encode(['event_id'=>'stop-1','phone'=>$phone,'text'=>'STOP']);$signature=hash_hmac('sha256',$body,'test-whatify-secret');
  $this->call('POST','/api/webhooks/whatify/inbound',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WHATIFY_SIGNATURE'=>$signature],$body)->assertOk()->assertJson(['received'=>true,'opted_out'=>1]);
  $this->assertDatabaseHas('whatsapp_consents',['id'=>$consent->id,'status'=>'opted_out','source'=>'whatsapp_stop']);$this->assertDatabaseHas('workflow_executions',['id'=>$execution->id,'status'=>'cancelled']);
 }
 public function test_shopify_compliance_webhooks_are_accepted_after_uninstall():void{
  config(['services.shopify.api_secret'=>'shopify-webhook-secret']);$shop=$this->shop();$shop->update(['installed'=>false]);$body=json_encode(['shop_id'=>42,'shop_domain'=>$shop->shop_domain,'customer'=>['id'=>123],'orders_to_redact'=>[]]);$signature=base64_encode(hash_hmac('sha256',$body,'shopify-webhook-secret',true));
  $this->call('POST','/api/webhooks/shopify',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_SHOPIFY_HMAC_SHA256'=>$signature,'HTTP_X_SHOPIFY_TOPIC'=>'customers/redact','HTTP_X_SHOPIFY_SHOP_DOMAIN'=>$shop->shop_domain,'HTTP_X_SHOPIFY_WEBHOOK_ID'=>'compliance-1'],$body)->assertOk()->assertJson(['received'=>true]);
  $this->assertDatabaseHas('webhook_events',['webhook_id'=>'compliance-1','topic'=>'CUSTOMERS_REDACT']);
 }
 public function test_shopify_oauth_starts_from_store_parameter_and_requests_expiring_tokens():void{
  config(['services.shopify.api_key'=>'client-id','services.shopify.api_secret'=>'client-secret','services.shopify.scopes'=>'read_orders,write_orders','app.url'=>'https://flow.example.com']);
  $this->get('/integrations/shopify/install?shop=demo.myshopify.com')->assertRedirect();$state=session('shopify_oauth_state');$this->assertNotEmpty($state);
  $query=['code'=>'auth-code','shop'=>'demo.myshopify.com','state'=>$state,'timestamp'=>'1793120000'];$signed=$query;ksort($signed);$query['hmac']=hash_hmac('sha256',urldecode(http_build_query($signed)),'client-secret');
  Http::fake(['https://demo.myshopify.com/admin/oauth/access_token'=>Http::response(['access_token'=>'offline-access','refresh_token'=>'offline-refresh','expires_in'=>86400,'refresh_token_expires_in'=>7776000,'scope'=>'read_orders,write_orders'],200)]);
  $this->get('/integrations/shopify/callback?'.http_build_query($query))->assertRedirect(route('dashboard'));
  $shop=Shop::where('shop_domain','demo.myshopify.com')->firstOrFail();$this->assertSame('offline-refresh',$shop->refresh_token);$this->assertSame('offline-access',$shop->access_token);$this->assertTrue($shop->access_token_expires_at->isFuture());
  Http::assertSent(fn($request)=>(string)$request['expiring']==='1'&&(string)$request['code']==='auth-code');
 }
 public function test_install_rejects_non_shopify_domains():void{$this->get('/integrations/shopify/install?shop=attacker.example')->assertBadRequest();}
 public function test_legacy_offline_token_is_migrated_to_expiring_token_without_reinstall():void{
  config(['services.shopify.api_key'=>'client-id','services.shopify.api_secret'=>'client-secret']);$shop=$this->shop();$shop->update(['refresh_token'=>null,'access_token_expires_at'=>null]);
  Http::fake(['https://demo.myshopify.com/admin/oauth/access_token'=>Http::response(['access_token'=>'access-expiring','refresh_token'=>'refresh-expiring','expires_in'=>3600,'refresh_token_expires_in'=>7776000],200)]);
  $this->assertTrue(app(ShopifyAdmin::class)->refreshIfNeeded($shop));$shop->refresh();$this->assertSame('access-expiring',$shop->access_token);$this->assertSame('refresh-expiring',$shop->refresh_token);
  Http::assertSent(fn($request)=>$request['grant_type']==='urn:ietf:params:oauth:grant-type:token-exchange'&&$request['expiring']==1&&$request['subject_token']==='test-token');
 }
 public function test_expiring_shopify_offline_token_is_rotated_and_saved():void{
  config(['services.shopify.api_key'=>'client-id','services.shopify.api_secret'=>'client-secret']);$shop=$this->shop();$shop->update(['refresh_token'=>'refresh-old','access_token_expires_at'=>now()->addMinute(),'refresh_token_expires_at'=>now()->addDays(60)]);
  Http::fake(['https://demo.myshopify.com/admin/oauth/access_token'=>Http::response(['access_token'=>'access-new','refresh_token'=>'refresh-new','expires_in'=>86400,'refresh_token_expires_in'=>7776000],200)]);
  $this->assertTrue(app(ShopifyAdmin::class)->refreshIfNeeded($shop));$shop->refresh();$this->assertSame('access-new',$shop->access_token);$this->assertSame('refresh-new',$shop->refresh_token);$this->assertTrue($shop->access_token_expires_at->isFuture());Http::assertSent(fn($request)=>$request['grant_type']==='refresh_token'&&$request['refresh_token']==='refresh-old');
 }
}
