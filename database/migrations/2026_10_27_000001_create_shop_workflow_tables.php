<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('shops', function (Blueprint $t) { $t->id(); $t->string('shop_domain')->unique(); $t->longText('access_token')->nullable(); $t->longText('whatify_api_key')->nullable(); $t->text('granted_scopes')->nullable(); $t->boolean('installed')->default(true); $t->json('settings')->nullable(); $t->timestamp('installed_at')->nullable(); $t->timestamps(); });
  Schema::create('workflows', function (Blueprint $t) { $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->string('name'); $t->text('description')->nullable(); $t->boolean('enabled')->default(false); $t->json('draft_graph')->nullable(); $t->unsignedBigInteger('published_version_id')->nullable(); $t->timestamps(); $t->index(['shop_id','enabled']); });
  Schema::create('workflow_versions', function (Blueprint $t) { $t->id(); $t->foreignId('workflow_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('version'); $t->json('graph'); $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('published_at'); $t->timestamps(); $t->unique(['workflow_id','version']); });
  Schema::create('webhook_events', function (Blueprint $t) { $t->id(); $t->foreignId('shop_id')->nullable()->constrained()->nullOnDelete(); $t->string('webhook_id')->unique(); $t->string('topic',80); $t->longText('payload')->nullable(); $t->timestamp('processed_at')->nullable(); $t->text('processing_error')->nullable(); $t->timestamps(); $t->index(['shop_id','topic']); });
  Schema::create('workflow_executions', function (Blueprint $t) { $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->foreignId('workflow_id')->constrained()->cascadeOnDelete(); $t->foreignId('workflow_version_id')->constrained()->cascadeOnDelete(); $t->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete(); $t->string('trigger_topic',80); $t->string('shopify_customer_id')->nullable()->index(); $t->string('shopify_order_id')->nullable()->index(); $t->string('customer_phone_hash',64)->nullable()->index(); $t->longText('trigger_payload'); $t->string('current_node_id',100)->nullable(); $t->string('status',24)->default('queued')->index(); $t->timestamp('scheduled_at')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->text('last_error')->nullable(); $t->timestamps(); $t->index(['shop_id','status']); $t->unique(['workflow_id','webhook_event_id'],'workflow_webhook_execution_unique'); });
  Schema::create('execution_events', function (Blueprint $t) { $t->id(); $t->foreignId('workflow_execution_id')->constrained()->cascadeOnDelete(); $t->string('node_id',100)->nullable(); $t->string('event_type',40); $t->string('status',24); $t->json('details')->nullable(); $t->text('error')->nullable(); $t->timestamps(); $t->index(['workflow_execution_id','created_at']); });
 }
 public function down(): void { foreach(['execution_events','workflow_executions','webhook_events','workflow_versions','workflows','shops'] as $name) Schema::dropIfExists($name); }
};
