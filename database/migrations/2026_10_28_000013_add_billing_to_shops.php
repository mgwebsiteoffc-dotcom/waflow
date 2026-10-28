<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('shops', function (Blueprint $t) {
   $t->string('billing_status', 12)->default('pending')->after('installed');
   $t->string('billing_charge_id')->nullable()->after('billing_status');
   $t->decimal('billing_amount', 8, 2)->nullable()->after('billing_charge_id');
   $t->string('billing_currency', 3)->default('USD')->after('billing_amount');
   $t->timestamp('billing_confirmed_at')->nullable()->after('billing_currency');
   $t->index('billing_status');
  });
 }
 public function down(): void {
  Schema::table('shops', function (Blueprint $t) {
   $t->dropIndex(['billing_status']);
   $t->dropColumn(['billing_status','billing_charge_id','billing_amount','billing_currency','billing_confirmed_at']);
  });
 }
};
