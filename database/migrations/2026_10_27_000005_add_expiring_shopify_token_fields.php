<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('shops', function (Blueprint $t) {
  $t->longText('refresh_token')->nullable();
  $t->timestamp('access_token_expires_at')->nullable();
  $t->timestamp('refresh_token_expires_at')->nullable();
 }); }
 public function down(): void { Schema::table('shops', function (Blueprint $t) {
  $t->dropColumn(['refresh_token','access_token_expires_at','refresh_token_expires_at']);
 }); }
};
