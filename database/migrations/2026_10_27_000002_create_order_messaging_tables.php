<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('order_verifications', function (Blueprint $t) { $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->string('shopify_order_id'); $t->string('shopify_customer_id')->nullable()->index(); $t->string('order_name')->nullable(); $t->string('customer_name')->nullable(); $t->longText('customer_phone')->nullable(); $t->string('customer_phone_hash',64)->nullable()->index(); $t->string('payment_method')->nullable(); $t->decimal('order_total',12,2)->nullable(); $t->string('currency',3)->nullable(); $t->string('status',32)->default('pending')->index(); $t->unsignedTinyInteger('risk_score')->default(0); $t->json('risk_reasons')->nullable(); $t->string('response')->nullable(); $t->timestamp('response_at')->nullable(); $t->timestamps(); $t->unique(['shop_id','shopify_order_id']); $t->index(['shop_id','status']); });
  Schema::create('message_logs', function (Blueprint $t) { $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->foreignId('workflow_execution_id')->nullable()->constrained()->nullOnDelete(); $t->string('node_id',100)->nullable(); $t->string('shopify_customer_id')->nullable()->index(); $t->string('provider_message_id')->nullable()->index(); $t->string('phone_hash',64)->nullable()->index(); $t->longText('phone')->nullable(); $t->string('template_name')->nullable(); $t->string('status',24)->default('queued')->index(); $t->longText('request_payload')->nullable(); $t->longText('response_payload')->nullable(); $t->text('error')->nullable(); $t->timestamps(); $t->unique(['workflow_execution_id','node_id'],'message_execution_node_unique'); });
  Schema::create('customer_replies', function (Blueprint $t) { $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->foreignId('workflow_execution_id')->nullable()->constrained()->nullOnDelete(); $t->string('shopify_customer_id')->nullable()->index(); $t->string('provider_event_id')->nullable()->unique(); $t->string('phone_hash',64)->index(); $t->longText('phone'); $t->longText('message_body'); $t->string('mapped_order_id')->nullable(); $t->timestamp('received_at')->index(); $t->timestamps(); });
 }
 public function down(): void { foreach(['customer_replies','message_logs','order_verifications'] as $name) Schema::dropIfExists($name); }
};
