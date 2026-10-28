<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WidgetSetting extends Model {
 protected $fillable = [
  'shop_id','entry_enabled','entry_title','entry_text','entry_button_label','entry_delay_seconds','entry_frequency_days',
  'exit_enabled','exit_title','exit_text','exit_button_label','exit_frequency_days',
  'float_enabled','float_phone','float_message','float_position','float_color',
 ];
 protected $casts = [
  'entry_enabled' => 'boolean','exit_enabled' => 'boolean','float_enabled' => 'boolean',
  'entry_delay_seconds' => 'integer','entry_frequency_days' => 'integer','exit_frequency_days' => 'integer',
 ];
 public static function defaults(): array {
  return [
   'entry_enabled' => false,
   'entry_title' => 'Get order updates on WhatsApp',
   'entry_text' => 'Leave your name and WhatsApp number and we will keep you updated about your order and offers.',
   'entry_button_label' => 'Subscribe',
   'entry_delay_seconds' => 5,
   'entry_frequency_days' => 7,
   'exit_enabled' => false,
   'exit_title' => 'Wait! Before you go…',
   'exit_text' => 'Connect with us on WhatsApp for quick answers, order help and exclusive offers.',
   'exit_button_label' => 'Chat on WhatsApp',
   'exit_frequency_days' => 7,
   'float_enabled' => false,
   'float_phone' => '',
   'float_message' => 'Hi, I have a question about your products.',
   'float_position' => 'right',
   'float_color' => '#25d366',
  ];
 }
 public function shop(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Shop::class); }
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
   ],
   'exit' => [
    'enabled' => (bool) $this->exit_enabled,
    'title' => (string) $this->exit_title,
    'text' => (string) $this->exit_text,
    'button' => (string) $this->exit_button_label,
    'frequencyDays' => (int) $this->exit_frequency_days,
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
   'entry' => ['enabled' => false,'title' => $d['entry_title'],'text' => $d['entry_text'],'button' => $d['entry_button_label'],'delay' => $d['entry_delay_seconds'],'frequencyDays' => $d['entry_frequency_days']],
   'exit' => ['enabled' => false,'title' => $d['exit_title'],'text' => $d['exit_text'],'button' => $d['exit_button_label'],'frequencyDays' => $d['exit_frequency_days']],
   'float' => ['enabled' => false,'phone' => '' ,'message' => $d['float_message'],'position' => 'right','color' => $d['float_color']],
  ];
 }
}
