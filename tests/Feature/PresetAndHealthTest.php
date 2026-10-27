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
}
