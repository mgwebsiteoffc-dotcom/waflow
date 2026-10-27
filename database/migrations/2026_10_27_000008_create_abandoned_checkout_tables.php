<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void
 {
  Schema::create('whatsapp_marketing_consents', function (Blueprint $t) {
   $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->string('shopify_customer_id')->nullable();
   $t->string('phone_hash',64); $t->longText('phone'); $t->string('status',20)->default('opted_in'); $t->string('source',100);
   $t->string('source_reference')->nullable(); $t->timestamp('consented_at')->nullable(); $t->timestamp('opted_out_at')->nullable(); $t->timestamps();
   $t->unique(['shop_id','phone_hash']); $t->index(['shop_id','status']);
  });
  Schema::create('abandoned_checkouts', function (Blueprint $t) {
   $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->string('checkout_token_hash',64);
   $t->string('shopify_customer_id')->nullable()->index(); $t->string('phone_hash',64)->nullable()->index();
   $t->longText('phone')->nullable(); $t->longText('customer_first_name')->nullable(); $t->longText('item_summary')->nullable();
   $t->longText('recovery_url')->nullable(); $t->decimal('total_price',12,2)->nullable(); $t->string('currency',3)->nullable();
   $t->string('status',32)->default('open')->index(); $t->foreignId('creation_webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
   $t->timestamp('last_activity_at')->nullable()->index(); $t->timestamp('completed_at')->nullable(); $t->timestamp('abandoned_at')->nullable();
   $t->timestamp('marketing_claimed_at')->nullable(); $t->timestamp('marketing_sent_at')->nullable(); $t->timestamps();
   $t->unique(['shop_id','checkout_token_hash']); $t->index(['shop_id','status']);
  });
 }
 public function down(): void { Schema::dropIfExists('abandoned_checkouts'); Schema::dropIfExists('whatsapp_marketing_consents'); }
};
