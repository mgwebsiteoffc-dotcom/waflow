<?php

namespace Tests\Feature;

use App\Jobs\ProcessAbandonedCheckout;
use App\Models\AbandonedCheckout;
use App\Models\Shop;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\CheckoutIdentity;
use App\Support\AutomationPresets;
use App\Support\SetupHealth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PresetAndHealthTest extends TestCase
{
    use RefreshDatabase;

    private function shop(bool $withWhatify = false): Shop
    {
        return Shop::create([
            'shop_domain' => 'demo.myshopify.com',
            'access_token' => 'test-token',
            'installed' => true,
            'whatify_api_key' => $withWhatify ? 'whatify-test-key' : null,
        ]);
    }

    public function test_preset_gallery_lists_all_four_predefined_automations(): void
    {
        $shop = $this->shop();
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('automations.presets'))
            ->assertOk()
            ->assertSee('COD order verification')
            ->assertSee('Order confirmation')
            ->assertSee('Abandoned cart recovery')
            ->assertSee('Order shipped / delivery update');
    }

    public function test_every_predefined_graph_is_publish_valid(): void
    {
        $shop = $this->shop();
        foreach (AutomationPresets::all() as $preset) {
            $workflow = $shop->workflows()->create([
                'name' => $preset['name'],
                'preset_key' => $preset['key'],
                'draft_graph' => AutomationPresets::graph($preset),
            ]);
            $this->withSession(['shop_id' => $shop->id])
                ->post(route('automations.publish', $workflow))
                ->assertRedirect();
            $this->assertTrue($workflow->fresh()->enabled, 'Preset '.$preset['key'].' should publish and enable.');
        }
    }

    public function test_from_preset_creates_workflow_with_mapped_template_and_publishes(): void
    {
        $shop = $this->shop();
        $response = $this->withSession(['shop_id' => $shop->id])
            ->post(route('automations.from-preset'), [
                'preset_key' => 'cod_verification',
                'name' => 'My COD flow',
                'templates' => ['send-cod' => 'my_approved_cod_template'],
            ]);
        $response->assertRedirect();
        $workflow = Workflow::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('cod_verification', $workflow->preset_key);
        $this->assertSame('My COD flow', $workflow->name);
        $sendNode = collect($workflow->draft_graph['nodes'])->firstWhere('id', 'send-cod');
        $this->assertSame('my_approved_cod_template', $sendNode['config']['template_name']);

        $this->withSession(['shop_id' => $shop->id])
            ->post(route('automations.publish', $workflow))
            ->assertRedirect();
        $this->assertDatabaseHas('workflow_versions', ['workflow_id' => $workflow->id, 'version' => 1]);
        $this->assertTrue($workflow->fresh()->enabled);
    }

    public function test_unknown_preset_key_is_rejected(): void
    {
        $shop = $this->shop();
        $this->withSession(['shop_id' => $shop->id])
            ->post(route('automations.from-preset'), ['preset_key' => 'does_not_exist'])
            ->assertSessionHasErrors('preset_key');
    }

    public function test_health_flags_a_template_that_is_not_approved_in_whatify(): void
    {
        Http::fake([
            '*whatify.in*/whatsapp-accounts' => Http::response(['success' => true, 'whatsapp_accounts' => []], 200),
            '*whatify.in*/templates' => Http::response(['success' => true, 'templates' => [
                ['name' => 'some_other_template', 'status' => 'approved', 'category' => 'UTILITY'],
            ]], 200),
        ]);
        $shop = $this->shop(true);
        $shop->workflows()->create([
            'name' => 'Broken mapping',
            'draft_graph' => ['nodes' => [
                ['id' => 't', 'type' => 'trigger', 'title' => 'Order', 'config' => ['topic' => 'ORDERS_CREATE']],
                ['id' => 's', 'type' => 'send_template', 'title' => 'Send', 'config' => ['template_name' => 'missing_template', 'message_purpose' => 'order_update']],
            ], 'edges' => []],
        ]);
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('automations.health'))
            ->assertOk()
            ->assertSee('Broken WhatsApp template mapping')
            ->assertSee('missing_template');
    }

    public function test_health_accepts_a_correctly_mapped_approved_template(): void
    {
        Http::fake([
            '*whatify.in*/whatsapp-accounts' => Http::response(['success' => true, 'whatsapp_accounts' => []], 200),
            '*whatify.in*/templates' => Http::response(['success' => true, 'templates' => [
                ['name' => 'order_confirmation', 'status' => 'approved', 'category' => 'UTILITY'],
                ['name' => 'cart_abandonment_reminder', 'status' => 'approved', 'category' => 'MARKETING'],
            ]], 200),
        ]);
        $shop = $this->shop(true);
        $shop->workflows()->create([
            'name' => 'Mapped flow',
            'draft_graph' => ['nodes' => [
                ['id' => 't', 'type' => 'trigger', 'title' => 'Order', 'config' => ['topic' => 'ORDERS_CREATE']],
                ['id' => 's', 'type' => 'send_template', 'title' => 'Send', 'config' => ['template_name' => 'order_confirmation', 'message_purpose' => 'order_update']],
            ], 'edges' => []],
        ]);
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('automations.health'))
            ->assertOk()
            ->assertSee('Workflow template mappings valid');
    }

    public function test_health_reports_message_skip_reasons_in_plain_language(): void
    {
        $shop = $this->shop();
        $workflow = $shop->workflows()->create(['name' => 'Opt-in gate']);
        $version = WorkflowVersion::create(['workflow_id' => $workflow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'published_at' => now()]);
        $shop->messageLogs()->create([
            'workflow_execution_id' => null,
            'node_id' => 's',
            'phone_hash' => 'abc',
            'phone' => '+12025550198',
            'template_name' => 'order_confirmation',
            'status' => 'skipped_no_opt_in',
        ]);
        $health = app(SetupHealth::class)->build($shop);
        $outcome = collect($health['outcomes'])->firstWhere('status', 'skipped_no_opt_in');
        $this->assertNotNull($outcome);
        $this->assertStringContainsString('opt-in', $outcome['label']);
    }

    public function test_recover_command_redispatches_stalled_open_checkouts(): void
    {
        Bus::fake();
        $shop = $this->shop();
        AbandonedCheckout::create([
            'shop_id' => $shop->id,
            'checkout_token_hash' => CheckoutIdentity::hash('stalled-token'),
            'phone_hash' => 'hash',
            'phone' => '+12025550198',
            'recovery_url' => 'https://demo.myshopify.com/recover/stalled',
            'item_summary' => 'Blue kurta',
            'status' => 'open',
            'last_activity_at' => now()->subMinutes(90),
        ]);
        $this->artisan('whatify:recover-stalled-checkouts')->assertExitCode(0);
        Bus::assertDispatched(ProcessAbandonedCheckout::class);
    }

    public function test_recover_command_ignores_recent_open_checkouts(): void
    {
        Bus::fake();
        $shop = $this->shop();
        AbandonedCheckout::create([
            'shop_id' => $shop->id,
            'checkout_token_hash' => CheckoutIdentity::hash('fresh-token'),
            'phone_hash' => 'hash2',
            'phone' => '+12025550199',
            'recovery_url' => 'https://demo.myshopify.com/recover/fresh',
            'item_summary' => 'Red kurta',
            'status' => 'open',
            'last_activity_at' => now()->subMinutes(5),
        ]);
        $this->artisan('whatify:recover-stalled-checkouts')->assertExitCode(0);
        Bus::assertNotDispatched(ProcessAbandonedCheckout::class);
    }

    public function test_heartbeat_command_records_scheduler_heartbeat(): void
    {
        Cache::forget(SetupHealth::HEARTBEAT_KEY);
        $this->artisan('whatify:health-heartbeat')->assertExitCode(0);
        $this->assertNotNull(Cache::get(SetupHealth::HEARTBEAT_KEY));
    }

    public function test_destroy_deletes_workflow_versions_and_executions_but_keeps_message_logs(): void
    {
        $shop = $this->shop();
        $workflow = $shop->workflows()->create(['name' => 'Doomed', 'enabled' => true]);
        $version = WorkflowVersion::create(['workflow_id' => $workflow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'published_at' => now()]);
        $workflow->update(['published_version_id' => $version->id]);
        $execution = \App\Models\WorkflowExecution::create([
            'shop_id' => $shop->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id,
            'trigger_topic' => 'ORDERS_CREATE', 'trigger_payload' => [], 'current_node_id' => 't', 'status' => 'waiting',
        ]);
        $log = \App\Models\MessageLog::create([
            'shop_id' => $shop->id, 'workflow_execution_id' => $execution->id, 'node_id' => 's',
            'phone_hash' => 'h', 'phone' => '+12025550198', 'template_name' => 'tpl', 'status' => 'sent',
        ]);

        $this->withSession(['shop_id' => $shop->id])
            ->delete(route('automations.destroy', $workflow))
            ->assertRedirect(route('automations.index'));

        $this->assertDatabaseMissing('workflows', ['id' => $workflow->id]);
        $this->assertDatabaseMissing('workflow_versions', ['workflow_id' => $workflow->id]);
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
        $this->assertDatabaseHas('message_logs', ['id' => $log->id]);
    }

    public function test_destroy_rejects_a_workflow_owned_by_another_shop(): void
    {
        $shop = $this->shop();
        $other = Shop::create(['shop_domain' => 'other.myshopify.com', 'access_token' => 't', 'installed' => true]);
        $workflow = $other->workflows()->create(['name' => 'Foreign']);

        $this->withSession(['shop_id' => $shop->id])
            ->delete(route('automations.destroy', $workflow))
            ->assertNotFound();
        $this->assertDatabaseHas('workflows', ['id' => $workflow->id]);
    }

    public function test_orders_page_shows_setup_diagnostics_when_no_webhooks_arrived(): void
    {
        $shop = $this->shop();
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('No Shopify webhooks have ever reached this app')
            ->assertSee('Check integrations');
    }

    public function test_orders_page_distinguishes_non_order_webhooks(): void
    {
        $shop = $this->shop();
        \App\Models\WebhookEvent::create([
            'shop_id' => $shop->id, 'webhook_id' => 'w1', 'topic' => 'CHECKOUTS_CREATE',
            'payload' => [], 'processed_at' => now(),
        ]);
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('none of them are order events')
            ->assertDontSee('No Shopify webhooks have ever reached this app');
    }

    public function test_integrations_page_renders_approved_templates_as_grid_cards(): void
    {
        Http::fake([
            '*whatify.in*/whatsapp-accounts' => Http::response(['success' => true, 'whatsapp_accounts' => []], 200),
            '*whatify.in*/templates' => Http::response(['success' => true, 'templates' => [
                ['name' => 'order_confirmation', 'status' => 'approved', 'category' => 'UTILITY', 'language' => 'en', 'body' => 'Thanks for your order'],
                ['name' => 'draft_one', 'status' => 'pending', 'category' => 'UTILITY', 'language' => 'en'],
            ]], 200),
        ]);
        $shop = $this->shop(true);
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('tpl-grid')
            ->assertSee('order_confirmation')
            ->assertDontSee('draft_one');
    }

    public function test_manual_graphql_sync_ingests_orders_without_starting_workflows(): void
    {
        Http::fake(['*graphql.json' => Http::response(['data' => ['orders' => ['edges' => [
            ['node' => ['id' => 'gid://shopify/Order/7001', 'name' => '#7001', 'createdAt' => now()->toIso8601String(),
                'paymentGatewayNames' => ['razorpay'], 'tags' => [],
                'totalPriceSet' => ['shopMoney' => ['amount' => '599.00', 'currencyCode' => 'INR']],
                'customAttributes' => [],
                'customer' => ['id' => 'gid://shopify/Customer/56', 'firstName' => 'Ravi', 'lastName' => 'K', 'phone' => '+919876543211']]],
        ]]]], 200)]);
        $shop = Shop::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'test-token', 'refresh_token' => 'r', 'access_token_expires_at' => now()->addHour(), 'refresh_token_expires_at' => now()->addDays(80), 'installed' => true]);
        $result = app(\App\Services\OrderSync::class)->syncRecentOrders($shop, 25);
        $this->assertSame(1, $result['synced']);
        $this->assertDatabaseHas('order_verifications', ['shop_id' => $shop->id, 'shopify_order_id' => '7001', 'status' => 'not_required']);
        $this->assertDatabaseCount('workflow_executions', 0);
        $this->assertDatabaseCount('message_logs', 0);
    }

    public function test_test_connection_reports_shop_identity(): void
    {
        Http::fake(['*graphql.json' => Http::response(['data' => ['shop' => ['name' => 'Demo Store', 'myshopifyDomain' => 'demo.myshopify.com', 'email' => 'o@demo.test']]], 200)]);
        $shop = Shop::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'test-token', 'refresh_token' => 'r', 'access_token_expires_at' => now()->addHour(), 'refresh_token_expires_at' => now()->addDays(80), 'installed' => true]);
        $this->withSession(['shop_id' => $shop->id])
            ->post(route('integrations.shopify.test'))
            ->assertRedirect();
        $this->assertSame('Demo Store', app(\App\Services\OrderSync::class)->testConnection($shop)['name']);
    }

    public function test_webhook_events_page_renders_empty_state_and_rows(): void
    {
        $shop = $this->shop();
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('webhooks.index'))
            ->assertOk()
            ->assertSee('No webhooks have ever arrived');

        \App\Models\WebhookEvent::create(['shop_id' => $shop->id, 'webhook_id' => 'evt-1', 'topic' => 'ORDERS_CREATE', 'payload' => [], 'processed_at' => now()]);
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('webhooks.index'))
            ->assertOk()
            ->assertSee('ORDERS CREATE')
            ->assertSee('Processed');
    }

    public function test_builder_exposes_connected_whatsapp_sender_accounts(): void
    {
        Http::fake([
            '*whatify.in*/whatsapp-accounts' => Http::response(['success' => true, 'whatsapp_accounts' => [
                ['id' => 501, 'display_name' => 'Sales Line', 'phone_number' => '+919876543210', 'status' => 'connected'],
            ]], 200),
            '*whatify.in*/templates' => Http::response(['success' => true, 'templates' => []], 200),
        ]);
        $shop = $this->shop(true);
        $workflow = $shop->workflows()->create(['name' => 'W', 'draft_graph' => ['nodes' => [], 'edges' => []]]);
        $this->withSession(['shop_id' => $shop->id])
            ->get(route('automations.edit', $workflow))
            ->assertOk()
            ->assertSee('Sales Line')
            ->assertSee('WhatsApp sender account');
    }

    public function test_draft_save_persists_whatsapp_account_id_including_numeric_strings(): void
    {
        $shop = $this->shop();
        $workflow = $shop->workflows()->create(['name' => 'W', 'draft_graph' => ['nodes' => [], 'edges' => []]]);
        $graph = ['nodes' => [
            ['id' => 't', 'type' => 'trigger', 'title' => 'Order', 'x' => 0, 'y' => 0, 'config' => ['topic' => 'ORDERS_CREATE']],
            ['id' => 's', 'type' => 'send_template', 'title' => 'Send', 'x' => 0, 'y' => 1, 'config' => ['template_name' => 'tpl', 'whatsapp_account_id' => '501']],
        ], 'edges' => []];
        $this->withSession(['shop_id' => $shop->id])
            ->putJson(route('automations.update', $workflow), ['name' => 'W', 'graph' => $graph])
            ->assertOk();
        $this->assertSame(501, $workflow->fresh()->draft_graph['nodes'][1]['config']['whatsapp_account_id']);
    }

    public function test_app_registers_the_custom_pagination_view(): void
    {
        $this->assertSame('vendor.pagination.default', \Illuminate\Pagination\AbstractPaginator::$defaultView);
        $this->assertViewExists('vendor.pagination.default');
    }

    public function test_health_flags_waiting_runs_past_their_reply_timeout(): void
    {
        $shop = $this->shop();
        $workflow = $shop->workflows()->create(['name' => 'Wait']);
        $version = WorkflowVersion::create(['workflow_id' => $workflow->id, 'version' => 1, 'published_at' => now(), 'graph' => [
            'nodes' => [['id' => 'wait', 'type' => 'wait_for_reply', 'title' => 'Wait', 'config' => ['timeout_minutes' => 30]]],
            'edges' => [],
        ]]);
        $execution = \App\Models\WorkflowExecution::create(['shop_id' => $shop->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'trigger_topic' => 'ORDERS_CREATE', 'customer_phone_hash' => 'h1', 'trigger_payload' => [], 'current_node_id' => 'wait', 'status' => 'waiting']);
        \Illuminate\Support\Facades\DB::table('workflow_executions')->where('id', $execution->id)->update(['created_at' => now()->subHours(3), 'updated_at' => now()->subHours(2)]);

        $health = app(SetupHealth::class)->build($shop);
        $check = collect($health['checks'])->first(fn ($c) => str_contains($c['title'], 'past their timeout'));
        $this->assertNotNull($check);
        $this->assertSame('warn', $check['level']);
    }

    public function test_health_flags_a_phone_with_multiple_waiting_automations(): void
    {
        $shop = $this->shop();
        foreach (range(1, 2) as $i) {
            $workflow = $shop->workflows()->create(['name' => 'Wait '.$i]);
            $version = WorkflowVersion::create(['workflow_id' => $workflow->id, 'version' => 1, 'published_at' => now(), 'graph' => [
                'nodes' => [['id' => 'wait', 'type' => 'wait_for_reply', 'title' => 'Wait', 'config' => ['timeout_minutes' => 120]]],
                'edges' => [],
            ]]);
            \App\Models\WorkflowExecution::create(['shop_id' => $shop->id, 'workflow_id' => $workflow->id, 'workflow_version_id' => $version->id, 'trigger_topic' => 'ORDERS_CREATE', 'customer_phone_hash' => 'same-hash', 'trigger_payload' => [], 'current_node_id' => 'wait', 'status' => 'waiting']);
        }
        $health = app(SetupHealth::class)->build($shop);
        $check = collect($health['checks'])->first(fn ($c) => str_contains($c['title'], 'multiple automations waiting'));
        $this->assertNotNull($check);
        $this->assertSame('warn', $check['level']);
    }

    private function assertViewExists(string $view): void
    {
        $this->assertTrue(view()->exists($view), "View [$view] should exist.");
    }
}
