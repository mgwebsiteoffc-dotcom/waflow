<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('widget_settings', function (Blueprint $t) {
   // Popup images (relative paths on the public disk)
   $t->string('entry_image_path')->nullable()->after('entry_frequency_days');
   $t->string('exit_image_path')->nullable()->after('exit_frequency_days');
   // Show only after the visitor has made this many visits (1 = first visit)
   $t->unsignedSmallInteger('entry_show_after_visits')->default(1)->after('entry_image_path');
   $t->unsignedSmallInteger('exit_show_after_visits')->default(1)->after('exit_image_path');
   // Page targeting: all | home | custom
   $t->string('entry_pages', 10)->default('all')->after('entry_show_after_visits');
   $t->string('exit_pages', 10)->default('all')->after('exit_show_after_visits');
   // Newline-separated path rules used when pages = custom (e.g. /collections/*)
   $t->text('entry_paths')->nullable()->after('entry_pages');
   $t->text('exit_paths')->nullable()->after('exit_pages');
  });
 }
 public function down(): void {
  Schema::table('widget_settings', function (Blueprint $t) {
   $t->dropColumn([
    'entry_image_path','exit_image_path',
    'entry_show_after_visits','exit_show_after_visits',
    'entry_pages','exit_pages','entry_paths','exit_paths',
   ]);
  });
 }
};
