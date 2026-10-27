<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('whatsapp_consents', function (Blueprint $t) {
  $t->id(); $t->foreignId('shop_id')->constrained()->cascadeOnDelete(); $t->string('shopify_customer_id')->nullable();
  $t->string('phone_hash',64); $t->text('phone'); $t->string('status',20)->default('opted_in'); $t->string('source',80);
  $t->timestamp('consented_at')->nullable(); $t->timestamp('opted_out_at')->nullable(); $t->timestamps();
  $t->unique(['shop_id','phone_hash']); $t->index(['shop_id','status']);
 }); }
 public function down(): void { Schema::dropIfExists('whatsapp_consents'); }
};
