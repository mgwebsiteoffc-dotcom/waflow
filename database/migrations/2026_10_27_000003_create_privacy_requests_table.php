<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('privacy_requests',function(Blueprint $t){$t->id();$t->foreignId('shop_id')->constrained()->cascadeOnDelete();$t->foreignId('webhook_event_id')->nullable()->constrained()->nullOnDelete();$t->string('shopify_customer_id')->index();$t->string('status',20)->default('ready');$t->longText('data_export')->nullable();$t->timestamp('received_at');$t->timestamp('fulfilled_at')->nullable();$t->timestamps();$t->unique('webhook_event_id');});}
 public function down():void{Schema::dropIfExists('privacy_requests');}
};
