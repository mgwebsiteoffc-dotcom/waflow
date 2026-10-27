<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('whatsapp_consents', function (Blueprint $t) { $t->string('source_reference')->nullable(); }); }
 public function down(): void { Schema::table('whatsapp_consents', function (Blueprint $t) { $t->dropColumn('source_reference'); }); }
};
