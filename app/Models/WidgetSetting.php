<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WidgetSetting extends Model {
 protected $fillable = [
  'shop_id','entry_enabled','entry_title','entry_text','entry_button_label','entry_delay_seconds','entry_frequency_days',
  'entry_image_path','entry_show_after_visits','entry_pages','entry_paths',
  'exit_enabled','exit_title','exit_text','exit_button_label','exit_frequency_days',
  'exit_image_path','exit_show_after_visits','exit_pages','exit_paths',
  'float_enabled','float_phone','float_message','float_position','float_color',
 ];
 protected $casts = [
  'entry_enabled' => 'boolean','exit_enabled' => 'boolean','float_enabled' => 'boolean',
  'entry_delay_seconds' => 'integer','entry_frequency_days' => 'integer','entry_show_after_visits' => 'integer',
  'exit_frequency_days' => 'integer','exit_show_after_visits' => 'integer',
 ];
 public static function defaults(): array {
  return [
   'entry_enabled' => false,
   'entry_title' => 'Get order updates on WhatsApp',
   'entry_text' => 'Leave your name and WhatsApp number and we will keep you updated about your order and offers.',
   'entry_button_label' => 'Subscribe',
   'entry_delay_seconds' => 5,
   'entry_frequency_days' => 7,
   'entry_image_path' => null,
   'entry_show_after_visits' => 1,
   'entry_pages' => 'all',
   'entry_paths' => null,
   'exit_enabled' => false,
   'exit_title' => 'Wait! Before you go…',
   'exit_text' => 'Connect with us on WhatsApp for quick answers, order help and exclusive offers.',
   'exit_button_label' => 'Chat on WhatsApp',
   'exit_frequency_days' => 7,
   'exit_image_path' => null,
   'exit_show_after_visits' => 1,
   'exit_pages' => 'all',
   'exit_paths' => null,
   'float_enabled' => false,
   'float_phone' => '',
   'float_message' => 'Hi, I have a question about your products.',
   'float_position' => 'right',
   'float_color' => '#25d366',
  ];
 }
 public function shop(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Shop::class); }

 public static function normalizePaths(?string $raw): array {
  return collect(preg_split('/[\r\n]+/', (string) $raw))
   ->map(fn($p) => '/'.ltrim(trim((string) $p), '/'))
   ->filter(fn($p) => $p !== '/' && $p !== '')
   ->unique()->values()->all();
 }
 private function imageUrl(?string $path): ?string {
  if (!$path) return null;
  return url('/api/widget/'.$this->shop->shop_domain.'/image/'.$path);
 }
 /**
  * Safe public config embedded in the storefront widget script. Never contains secrets.
  */
 public function publicConfig(): array {
  return [
   'entry' => [
    'enabled' => (bool) $this->entry_enabled,
    'title' => (string) $this->entry_title,
    'text' => (string) $this->entry_text,
    'button' => (string) $this->entry_button_label,
    'delay' => (int) $this->entry_delay_seconds,
    'frequencyDays' => (int) $this->entry_frequency_days,
    'image' => $this->imageUrl($this->entry_image_path),
    'showAfterVisits' => max(1, (int) $this->entry_show_after_visits),
    'pages' => in_array($this->entry_pages, ['all','home','custom'], true) ? $this->entry_pages : 'all',
    'paths' => self::normalizePaths($this->entry_paths),
   ],
   'exit' => [
    'enabled' => (bool) $this->exit_enabled,
    'title' => (string) $this->exit_title,
    'text' => (string) $this->exit_text,
    'button' => (string) $this->exit_button_label,
    'frequencyDays' => (int) $this->exit_frequency_days,
    'image' => $this->imageUrl($this->exit_image_path),
    'showAfterVisits' => max(1, (int) $this->exit_show_after_visits),
    'pages' => in_array($this->exit_pages, ['all','home','custom'], true) ? $this->exit_pages : 'all',
    'paths' => self::normalizePaths($this->exit_paths),
   ],
   'float' => [
    'enabled' => (bool) $this->float_enabled,
    'phone' => preg_replace('/\D+/', '', (string) $this->float_phone) ?: '',
    'message' => (string) $this->float_message,
    'position' => $this->float_position === 'left' ? 'left' : 'right',
    'color' => (string) $this->float_color,
   ],
  ];
 }
 public static function disabledConfig(): array {
  $d = self::defaults();
  return [
   'entry' => ['enabled' => false,'title' => $d['entry_title'],'text' => $d['entry_text'],'button' => $d['entry_button_label'],'delay' => $d['entry_delay_seconds'],'frequencyDays' => $d['entry_frequency_days'],'image' => null,'showAfterVisits' => 1,'pages' => 'all','paths' => []],
   'exit' => ['enabled' => false,'title' => $d['exit_title'],'text' => $d['exit_text'],'button' => $d['exit_button_label'],'frequencyDays' => $d['exit_frequency_days'],'image' => null,'showAfterVisits' => 1,'pages' => 'all','paths' => []],
   'float' => ['enabled' => false,'phone' => '' ,'message' => $d['float_message'],'position' => 'right','color' => $d['float_color']],
  ];
 }
}
