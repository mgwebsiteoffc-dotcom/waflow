<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('widget_settings', function (Blueprint $t) {
   $t->id();
   $t->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
   // Entry popup
   $t->boolean('entry_enabled')->default(false);
   $t->string('entry_title',150)->nullable();
   $t->string('entry_text',500)->nullable();
   $t->string('entry_button_label',60)->nullable();
   $t->unsignedSmallInteger('entry_delay_seconds')->default(5);
   $t->unsignedSmallInteger('entry_frequency_days')->default(7);
   // Exit-intent popup
   $t->boolean('exit_enabled')->default(false);
   $t->string('exit_title',150)->nullable();
   $t->string('exit_text',500)->nullable();
   $t->string('exit_button_label',60)->nullable();
   $t->unsignedSmallInteger('exit_frequency_days')->default(7);
   // Floating WhatsApp button
   $t->boolean('float_enabled')->default(false);
   $t->string('float_phone',30)->nullable();
   $t->string('float_message',500)->nullable();
   $t->string('float_position',10)->default('right');
   $t->string('float_color',10)->default('#25d366');
   $t->timestamps();
  });
 }
 public function down(): void { Schema::dropIfExists('widget_settings'); }
};
