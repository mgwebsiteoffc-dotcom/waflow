<?php
namespace Tests\Feature;
use App\Jobs\ProcessShopifyWebhook;
use App\Models\AbandonedCheckout;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Models\WhatsAppMarketingConsent;
use App\Services\CheckoutIdentity;
use App\Models\Workflow;
use App\Models\WhatsAppConsent;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use App\Models\MessageLog;
use App\Services\WorkflowRunner;
use App\Services\PhoneIdentity;
use App\Services\ShopifyAdmin;
use Illuminate\Support\Facades\Bus;
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
  $marketingConsent=WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'checkout_note_attribute:whatsapp_marketing_opt_in','source_reference'=>'checkout:test','consented_at'=>now()]);
  $checkout=AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>CheckoutIdentity::hash('checkout-stop-token'),'phone_hash'=>$hash,'phone'=>$phone,'recovery_url'=>'https://demo.myshopify.com/recover','item_summary'=>'Kurta','status'=>'open','last_activity_at'=>now()]);
  $workflow=$shop->workflows()->create(['name'=>'Test']);$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>['nodes'=>[],'edges'=>[]],'published_at'=>now()]);
  $execution=WorkflowExecution::create(['shop_id'=>$shop->id,'workflow_id'=>$workflow->id,'workflow_version_id'=>$version->id,'trigger_topic'=>'ORDERS_CREATE','customer_phone_hash'=>$hash,'trigger_payload'=>[],'status'=>'waiting']);
  $body=json_encode(['event_id'=>'stop-1','phone'=>$phone,'text'=>'STOP']);$signature=hash_hmac('sha256',$body,'test-whatify-secret');
  $this->call('POST','/api/webhooks/whatify/inbound',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WHATIFY_SIGNATURE'=>$signature],$body)->assertOk()->assertJson(['received'=>true,'opted_out'=>1]);
  $this->assertDatabaseHas('whatsapp_consents',['id'=>$consent->id,'status'=>'opted_out','source'=>'whatsapp_stop']);$this->assertDatabaseHas('whatsapp_marketing_consents',['id'=>$marketingConsent->id,'status'=>'opted_out','source'=>'whatsapp_stop']);$this->assertDatabaseHas('abandoned_checkouts',['id'=>$checkout->id,'status'=>'suppressed_opt_out','recovery_url'=>null]);$this->assertDatabaseHas('workflow_executions',['id'=>$execution->id,'status'=>'cancelled']);
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
 public function test_checkout_webhook_records_recovery_data_only_after_explicit_marketing_opt_in():void{
  Bus::fake();$shop=$this->shop();$workflow=$shop->workflows()->create(['name'=>'Cart recovery','enabled'=>true]);$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>['nodes'=>[['id'=>'checkout','type'=>'trigger','title'=>'Checkout inactivity','config'=>['topic'=>'CHECKOUTS_CREATE']]],'edges'=>[]],'published_at'=>now()]);$workflow->update(['published_version_id'=>$version->id]);
  $event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'checkout-event-1','topic'=>'CHECKOUTS_CREATE','payload'=>['token'=>'private-checkout-token','customer'=>['id'=>123,'first_name'=>'Asha','phone'=>'+12025550198'],'abandoned_checkout_url'=>'https://demo.myshopify.com/recover/secret','line_items'=>[['title'=>'Blue kurta','quantity'=>1]],'total_price'=>'1599.00','currency'=>'INR','updated_at'=>now()->toIso8601String(),'note_attributes'=>[['name'=>'whatsapp_marketing_opt_in','value'=>'yes']]]]);
  (new ProcessShopifyWebhook($event->id))->handle(app(ShopifyAdmin::class));
  $checkout=AbandonedCheckout::where('shop_id',$shop->id)->where('checkout_token_hash',CheckoutIdentity::hash('private-checkout-token'))->firstOrFail();
  $this->assertSame('open',$checkout->status);$this->assertSame('Blue kurta',$checkout->item_summary);$this->assertSame('https://demo.myshopify.com/recover/secret',$checkout->recovery_url);$this->assertSame($event->id,$checkout->creation_webhook_event_id);
  $this->assertDatabaseHas('whatsapp_marketing_consents',['shop_id'=>$shop->id,'phone_hash'=>PhoneIdentity::hash('+12025550198'),'status'=>'opted_in']);$this->assertNull($event->fresh()->payload);$this->assertNotNull($event->fresh()->processed_at);Bus::assertDispatched(\App\Jobs\ProcessAbandonedCheckout::class);
 }
 public function test_checkout_webhook_does_not_retain_personal_data_without_marketing_consent():void{
  Bus::fake();$shop=$this->shop();$workflow=$shop->workflows()->create(['name'=>'Cart recovery','enabled'=>true]);$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>['nodes'=>[['id'=>'checkout','type'=>'trigger','title'=>'Checkout inactivity','config'=>['topic'=>'CHECKOUTS_CREATE']]],'edges'=>[]],'published_at'=>now()]);$workflow->update(['published_version_id'=>$version->id]);
  $event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'checkout-event-no-consent','topic'=>'CHECKOUTS_CREATE','payload'=>['token'=>'private-checkout-token-no-consent','customer'=>['phone'=>'+12025550198'],'abandoned_checkout_url'=>'https://demo.myshopify.com/recover/secret']]);
  (new ProcessShopifyWebhook($event->id))->handle(app(ShopifyAdmin::class));
  $this->assertDatabaseCount('abandoned_checkouts',0);$this->assertNull($event->fresh()->payload);Bus::assertNotDispatched(\App\Jobs\ProcessAbandonedCheckout::class);
 }
 public function test_order_update_consent_is_not_enough_for_marketing_cart_recovery():void{
  Http::fake();$shop=$this->shop();$phone='+12025550198';$hash=PhoneIdentity::hash($phone);WhatsAppConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'order note','consented_at'=>now()]);
  $checkout=AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>CheckoutIdentity::hash('checkout-marketing-gate'),'phone_hash'=>$hash,'phone'=>$phone,'recovery_url'=>'https://demo.myshopify.com/recover/secret','status'=>'abandoned','abandoned_at'=>now()]);
  $workflow=$shop->workflows()->create(['name'=>'Marketing gate']);$graph=['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Checkout','config'=>['topic'=>'CHECKOUTS_CREATE']],['id'=>'send','type'=>'send_template','title'=>'Recovery','config'=>['message_purpose'=>'marketing','template_name'=>'cart_abandonment_reminder','phone'=>'{{checkout.customer.phone}}','body_params'=>[]]]],'edges'=>[['source'=>'trigger','target'=>'send']]];$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>$graph,'published_at'=>now()]);
  $execution=WorkflowExecution::create(['shop_id'=>$shop->id,'workflow_id'=>$workflow->id,'workflow_version_id'=>$version->id,'trigger_topic'=>'CHECKOUTS_CREATE','customer_phone_hash'=>$hash,'trigger_payload'=>['checkout'=>['customer'=>['phone'=>$phone]],'_whatify'=>['abandoned_checkout_id'=>$checkout->id]],'current_node_id'=>'trigger','status'=>'queued']);
  app(WorkflowRunner::class)->run($execution->id);$this->assertDatabaseHas('message_logs',['workflow_execution_id'=>$execution->id,'status'=>'skipped_no_marketing']);Http::assertNothingSent();$this->assertSame('abandoned',$checkout->fresh()->status);
 }
 public function test_marketing_workflow_cannot_publish_without_checkout_trigger():void{
  $shop=$this->shop();$workflow=$shop->workflows()->create(['name'=>'Invalid marketing flow','draft_graph'=>['nodes'=>[['id'=>'order','type'=>'trigger','title'=>'Order','config'=>['topic'=>'ORDERS_CREATE']],['id'=>'send','type'=>'send_template','title'=>'Marketing','config'=>['message_purpose'=>'marketing']]],'edges'=>[]]]);$this->withSession(['shop_id'=>$shop->id]);
  $this->post(route('automations.publish',$workflow))->assertSessionHasErrors('graph');$this->assertFalse($workflow->fresh()->enabled);
 }

 public function test_inactive_consented_checkout_starts_the_published_marketing_workflow():void{
  Bus::fake();$shop=$this->shop();$phone='+12025550198';$hash=PhoneIdentity::hash($phone);WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'checkout_note_attribute:whatsapp_marketing_opt_in','source_reference'=>'checkout:job','consented_at'=>now()]);
  $event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'checkout-job-event','topic'=>'CHECKOUTS_CREATE','payload'=>null]);
  $workflow=$shop->workflows()->create(['name'=>'Delayed recovery','enabled'=>true]);$graph=['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Checkout inactivity','config'=>['topic'=>'CHECKOUTS_CREATE']],['id'=>'send','type'=>'send_template','title'=>'Marketing reminder','config'=>['message_purpose'=>'marketing','template_name'=>'cart_abandonment_reminder','phone'=>'{{checkout.customer.phone}}','body_params'=>[]]]],'edges'=>[['source'=>'trigger','target'=>'send']]];$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>$graph,'published_at'=>now()]);$workflow->update(['published_version_id'=>$version->id]);
  $checkout=AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>CheckoutIdentity::hash('eligible-checkout'),'shopify_customer_id'=>'123','phone_hash'=>$hash,'phone'=>$phone,'customer_first_name'=>'Asha','item_summary'=>'Blue kurta','recovery_url'=>'https://demo.myshopify.com/recover/eligible','status'=>'open','creation_webhook_event_id'=>$event->id,'last_activity_at'=>now()->subMinutes(61)]);
  (new \App\Jobs\ProcessAbandonedCheckout($checkout->id))->handle();
  $this->assertSame('abandoned',$checkout->fresh()->status);$execution=WorkflowExecution::where('webhook_event_id',$event->id)->firstOrFail();$this->assertSame('queued',$execution->status);$this->assertSame($checkout->id,data_get($execution->trigger_payload,'_whatify.abandoned_checkout_id'));Bus::assertDispatched(\App\Jobs\RunWorkflowExecution::class);
 }

 public function test_order_webhook_marks_checkout_recovered_and_cancels_pending_reminder():void{
  Bus::fake();$shop=$this->shop();$phone='+12025550198';$hash=PhoneIdentity::hash($phone);WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'checkout_note_attribute:whatsapp_marketing_opt_in','source_reference'=>'checkout:recovery','consented_at'=>now()]);
  $source=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'checkout-recovery-source','topic'=>'CHECKOUTS_CREATE','payload'=>null]);$workflow=$shop->workflows()->create(['name'=>'Recovery','enabled'=>true]);$graph=['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Checkout','config'=>['topic'=>'CHECKOUTS_CREATE']],['id'=>'send','type'=>'send_template','title'=>'Recovery message','config'=>['message_purpose'=>'marketing','template_name'=>'cart_abandonment_reminder']]],'edges'=>[['source'=>'trigger','target'=>'send']]];$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>$graph,'published_at'=>now()]);$workflow->update(['published_version_id'=>$version->id]);
  $checkout=AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>CheckoutIdentity::hash('recovered-checkout-token'),'phone_hash'=>$hash,'phone'=>$phone,'customer_first_name'=>'Asha','item_summary'=>'Blue kurta','recovery_url'=>'https://demo.myshopify.com/recover/recovered','status'=>'open','creation_webhook_event_id'=>$source->id,'last_activity_at'=>now()->subMinutes(70)]);
  $execution=WorkflowExecution::create(['shop_id'=>$shop->id,'workflow_id'=>$workflow->id,'workflow_version_id'=>$version->id,'webhook_event_id'=>$source->id,'trigger_topic'=>'CHECKOUTS_CREATE','customer_phone_hash'=>$hash,'trigger_payload'=>['checkout'=>['customer'=>['phone'=>$phone],'abandoned_checkout_url'=>$checkout->recovery_url],'_whatify'=>['abandoned_checkout_id'=>$checkout->id]],'current_node_id'=>'send','status'=>'queued']);
  $orderEvent=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'order-recovery-complete','topic'=>'ORDERS_CREATE','payload'=>['id'=>456,'checkout_token'=>'recovered-checkout-token','customer'=>['id'=>123,'phone'=>$phone],'total_price'=>'1599.00','currency'=>'INR','payment_gateway_names'=>['Prepaid']]]);
  (new ProcessShopifyWebhook($orderEvent->id))->handle(app(ShopifyAdmin::class));
  $this->assertSame('recovered',$checkout->fresh()->status);$this->assertNull($checkout->fresh()->recovery_url);$this->assertSame('cancelled',$execution->fresh()->status);$this->assertSame(['_whatify'=>['abandoned_checkout_id'=>$checkout->id,'recovered'=>true]],$execution->fresh()->trigger_payload);Bus::assertNotDispatched(\App\Jobs\RunWorkflowExecution::class);
 }

 public function test_marketing_purpose_rejects_a_utility_template_category():void{
  Http::fake(['*whatify.in/api/v1/external/templates*'=>Http::response(['success'=>true,'templates'=>[['name'=>'cart_abandonment_reminder','status'=>'approved','category'=>'UTILITY']]],200)]);$shop=$this->shop();$shop->update(['whatify_api_key'=>'whatify-test-key']);$phone='+12025550198';$hash=PhoneIdentity::hash($phone);WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'checkout_note_attribute:whatsapp_marketing_opt_in','source_reference'=>'checkout:category','consented_at'=>now()]);
  $checkout=AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>CheckoutIdentity::hash('checkout-category-gate'),'phone_hash'=>$hash,'phone'=>$phone,'recovery_url'=>'https://demo.myshopify.com/recover/category','status'=>'abandoned','abandoned_at'=>now()]);$workflow=$shop->workflows()->create(['name'=>'Category gate']);$graph=['nodes'=>[['id'=>'trigger','type'=>'trigger','title'=>'Checkout','config'=>['topic'=>'CHECKOUTS_CREATE']],['id'=>'send','type'=>'send_template','title'=>'Recovery','config'=>['message_purpose'=>'marketing','template_name'=>'cart_abandonment_reminder','phone'=>'{{checkout.customer.phone}}','body_params'=>[]]]],'edges'=>[['source'=>'trigger','target'=>'send']]];$version=WorkflowVersion::create(['workflow_id'=>$workflow->id,'version'=>1,'graph'=>$graph,'published_at'=>now()]);
  $execution=WorkflowExecution::create(['shop_id'=>$shop->id,'workflow_id'=>$workflow->id,'workflow_version_id'=>$version->id,'trigger_topic'=>'CHECKOUTS_CREATE','customer_phone_hash'=>$hash,'trigger_payload'=>['checkout'=>['customer'=>['phone'=>$phone]],'_whatify'=>['abandoned_checkout_id'=>$checkout->id]],'current_node_id'=>'trigger','status'=>'queued']);app(WorkflowRunner::class)->run($execution->id);
  $this->assertDatabaseHas('message_logs',['workflow_execution_id'=>$execution->id,'status'=>'skipped_not_approved']);$this->assertNull($checkout->fresh()->marketing_claimed_at);Http::assertNotSent(fn($request)=>str_contains($request->url(),'/send-template'));
 }

 public function test_completed_checkout_update_suppresses_without_recovery_url_or_consent():void{
  Bus::fake();$shop=$this->shop();$event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'completed-checkout-no-link','topic'=>'CHECKOUTS_UPDATE','payload'=>['token'=>'completed-no-link-token','completed_at'=>now()->toIso8601String(),'customer'=>['phone'=>'+12025550198']]]);(new ProcessShopifyWebhook($event->id))->handle(app(ShopifyAdmin::class));
  $checkout=AbandonedCheckout::where('checkout_token_hash',CheckoutIdentity::hash('completed-no-link-token'))->firstOrFail();$this->assertSame('recovered',$checkout->status);$this->assertNull($checkout->phone);$this->assertNull($checkout->recovery_url);$this->assertNull($event->fresh()->payload);Bus::assertNotDispatched(\App\Jobs\ProcessAbandonedCheckout::class);
 }

 public function test_stale_checkout_opt_in_attribute_cannot_reverse_a_stop_reply():void{
  Bus::fake();config(['services.whatify.webhook_secret'=>'test-whatify-secret']);$shop=$this->shop();$phone='+12025550198';$hash=PhoneIdentity::hash($phone);$token='same-checkout-after-stop';$source='checkout:'.substr(CheckoutIdentity::hash($token),0,16);$consent=WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_in','source'=>'checkout_note_attribute:whatsapp_marketing_opt_in','source_reference'=>$source,'consented_at'=>now()]);$checkout=AbandonedCheckout::create(['shop_id'=>$shop->id,'checkout_token_hash'=>CheckoutIdentity::hash($token),'phone_hash'=>$hash,'phone'=>$phone,'recovery_url'=>'https://demo.myshopify.com/recover/same','item_summary'=>'Blue kurta','status'=>'open','last_activity_at'=>now()]);
  $body=json_encode(['event_id'=>'stop-before-update','phone'=>$phone,'text'=>'STOP']);$signature=hash_hmac('sha256',$body,'test-whatify-secret');$this->call('POST','/api/webhooks/whatify/inbound',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_WHATIFY_SIGNATURE'=>$signature],$body)->assertOk();
  $event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'stale-consent-checkout-update','topic'=>'CHECKOUTS_UPDATE','payload'=>['token'=>$token,'customer'=>['phone'=>$phone],'updated_at'=>now()->toIso8601String(),'abandoned_checkout_url'=>'https://demo.myshopify.com/recover/same','note_attributes'=>[['name'=>'whatsapp_marketing_opt_in','value'=>'yes']]]]);(new ProcessShopifyWebhook($event->id))->handle(app(ShopifyAdmin::class));
  $this->assertSame('opted_out',$consent->fresh()->status);$this->assertSame('suppressed_opt_out',$checkout->fresh()->status);$this->assertNull($checkout->fresh()->recovery_url);Bus::assertNotDispatched(\App\Jobs\ProcessAbandonedCheckout::class);
 }

 public function test_marketing_opt_out_can_only_be_reversed_by_a_later_checkout_opt_in():void{
  Bus::fake();$shop=$this->shop();$phone='+12025550198';$hash=PhoneIdentity::hash($phone);$consent=WhatsAppMarketingConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_out','source'=>'whatsapp_stop','source_reference'=>'checkout:old','consented_at'=>now()->subDays(2),'opted_out_at'=>now()->subHour()]);
  $event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'fresh-checkout-opt-in','topic'=>'CHECKOUTS_CREATE','payload'=>['token'=>'new-checkout-after-stop','created_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String(),'customer'=>['phone'=>$phone],'abandoned_checkout_url'=>'https://demo.myshopify.com/recover/new','note_attributes'=>[['name'=>'whatsapp_marketing_opt_in','value'=>'yes']]]]);(new ProcessShopifyWebhook($event->id))->handle(app(ShopifyAdmin::class));
  $this->assertSame('opted_in',$consent->fresh()->status);$this->assertSame('checkout:'.substr(CheckoutIdentity::hash('new-checkout-after-stop'),0,16),$consent->fresh()->source_reference);
 }

 public function test_stale_order_opt_in_attribute_cannot_reverse_a_stop_reply():void{
  Bus::fake();$shop=$this->shop();$phone='+12025550198';$hash=PhoneIdentity::hash($phone);$consent=WhatsAppConsent::create(['shop_id'=>$shop->id,'phone_hash'=>$hash,'phone'=>$phone,'status'=>'opted_out','source'=>'whatsapp_stop','source_reference'=>'order:old','consented_at'=>now()->subDays(2),'opted_out_at'=>now()->subHour()]);$event=WebhookEvent::create(['shop_id'=>$shop->id,'webhook_id'=>'stale-order-opt-in','topic'=>'ORDERS_CREATE','payload'=>['id'=>998,'name'=>'#998','created_at'=>now()->subHours(2)->toIso8601String(),'customer'=>['id'=>456,'phone'=>$phone],'note_attributes'=>[['name'=>'whatsapp_opt_in','value'=>'yes']],'payment_gateway_names'=>['Cash on Delivery'],'total_price'=>'800.00','currency'=>'INR']]);(new ProcessShopifyWebhook($event->id))->handle(app(ShopifyAdmin::class));
  $this->assertSame('opted_out',$consent->fresh()->status);$this->assertDatabaseHas('order_verifications',['shop_id'=>$shop->id,'shopify_order_id'=>'998','status'=>'not_contacted']);
 }

}
