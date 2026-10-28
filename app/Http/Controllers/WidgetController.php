<?php
namespace App\Http\Controllers;
use App\Models\Shop;
use App\Models\WhatsAppConsent;
use App\Models\WidgetSetting;
use App\Services\PhoneIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class WidgetController extends Controller {
 private const SHOP_DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';
 private const IMAGE_RULE = ['nullable','image','mimes:jpeg,png,webp,gif','max:2048'];

 public function edit(Request $r){
  $shop = $r->attributes->get('currentShop');
  $setting = WidgetSetting::firstOrNew(['shop_id' => $shop->id], WidgetSetting::defaults());
  $leads = $shop->whatsappConsents()
   ->where('source','like','website_%')
   ->latest('consented_at')->latest('id')->limit(15)->get();
  $scriptUrl = url('/api/widget/'.$shop->shop_domain.'/widget.js');
  $snippet = '<script defer src="'.$scriptUrl.'"></script>';
  $entryImageUrl = $this->imageUrl($shop, $setting->entry_image_path);
  $exitImageUrl = $this->imageUrl($shop, $setting->exit_image_path);
  return view('widget.edit', compact('setting','leads','scriptUrl','snippet','entryImageUrl','exitImageUrl'));
 }

 public function update(Request $r){
  $shop = $r->attributes->get('currentShop');
  $d = WidgetSetting::defaults();
  $r->validate([
   'entry_title' => ['nullable','string','max:150'],
   'entry_text' => ['nullable','string','max:500'],
   'entry_button_label' => ['nullable','string','max:60'],
   'entry_delay_seconds' => ['integer','min:0','max:300'],
   'entry_frequency_days' => ['integer','min:0','max:90'],
   'entry_show_after_visits' => ['integer','min:1','max:50'],
   'entry_pages' => ['in:all,home,custom'],
   'entry_paths' => ['nullable','string','max:1000'],
   'entry_image' => self::IMAGE_RULE,
   'exit_title' => ['nullable','string','max:150'],
   'exit_text' => ['nullable','string','max:500'],
   'exit_button_label' => ['nullable','string','max:60'],
   'exit_frequency_days' => ['integer','min:0','max:90'],
   'exit_show_after_visits' => ['integer','min:1','max:50'],
   'exit_pages' => ['in:all,home,custom'],
   'exit_paths' => ['nullable','string','max:1000'],
   'exit_image' => self::IMAGE_RULE,
   'float_phone' => ['nullable','string','max:30'],
   'float_message' => ['nullable','string','max:500'],
   'float_position' => ['in:left,right'],
   'float_color' => ['string','regex:/^#[0-9a-fA-F]{6}$/'],
  ]);
  $floatEnabled = $r->boolean('float_enabled');
  $floatDigits = PhoneIdentity::normalize((string) $r->input('float_phone',''));
  if ($floatEnabled && strlen($floatDigits) < 8) {
   throw ValidationException::withMessages(['float_phone' => 'Enter a valid WhatsApp number with country code when the floating button is enabled.']);
  }
  $setting = WidgetSetting::firstOrNew(['shop_id' => $shop->id]);
  $entryImage = $this->storeImage($r, 'entry_image', $shop, (string) $setting->entry_image_path, $r->boolean('entry_image_remove'));
  $exitImage = $this->storeImage($r, 'exit_image', $shop, (string) $setting->exit_image_path, $r->boolean('exit_image_remove'));

  $payload = array_merge($d, [
   'shop_id' => $shop->id,
   // Explicit normalized values below deliberately replace the raw validated input.
   'entry_enabled' => $r->boolean('entry_enabled'),
   'entry_title' => trim((string) $r->input('entry_title', $d['entry_title'])) ?: $d['entry_title'],
   'entry_text' => trim((string) $r->input('entry_text', $d['entry_text'])) ?: $d['entry_text'],
   'entry_button_label' => trim((string) $r->input('entry_button_label', $d['entry_button_label'])) ?: $d['entry_button_label'],
   'entry_delay_seconds' => (int) $r->input('entry_delay_seconds', $d['entry_delay_seconds']),
   'entry_frequency_days' => (int) $r->input('entry_frequency_days', $d['entry_frequency_days']),
   'entry_image_path' => $entryImage,
   'entry_show_after_visits' => (int) $r->input('entry_show_after_visits', $d['entry_show_after_visits']),
   'entry_pages' => in_array($r->input('entry_pages'), ['all','home','custom'], true) ? $r->input('entry_pages') : 'all',
   'entry_paths' => $r->input('entry_pages') === 'custom' ? trim((string) $r->input('entry_paths')) : null,
   'exit_enabled' => $r->boolean('exit_enabled'),
   'exit_title' => trim((string) $r->input('exit_title', $d['exit_title'])) ?: $d['exit_title'],
   'exit_text' => trim((string) $r->input('exit_text', $d['exit_text'])) ?: $d['exit_text'],
   'exit_button_label' => trim((string) $r->input('exit_button_label', $d['exit_button_label'])) ?: $d['exit_button_label'],
   'exit_frequency_days' => (int) $r->input('exit_frequency_days', $d['exit_frequency_days']),
   'exit_image_path' => $exitImage,
   'exit_show_after_visits' => (int) $r->input('exit_show_after_visits', $d['exit_show_after_visits']),
   'exit_pages' => in_array($r->input('exit_pages'), ['all','home','custom'], true) ? $r->input('exit_pages') : 'all',
   'exit_paths' => $r->input('exit_pages') === 'custom' ? trim((string) $r->input('exit_paths')) : null,
   'float_enabled' => $floatEnabled,
   'float_phone' => $floatDigits,
   'float_message' => trim((string) $r->input('float_message', $d['float_message'])) ?: $d['float_message'],
   'float_position' => $r->input('float_position') === 'left' ? 'left' : 'right',
   'float_color' => strtoupper((string) $r->input('float_color', $d['float_color'])),
  ]);
  WidgetSetting::updateOrCreate(['shop_id' => $shop->id], $payload);
  return back()->with('success','Website widget settings saved. Add the snippet to your Shopify theme (or any website) to turn it on for visitors.');
 }

 public function script(string $shopDomain){
  if (!preg_match(self::SHOP_DOMAIN_PATTERN, $shopDomain)) abort(404);
  $shop = Shop::where('shop_domain', $shopDomain)->where('installed', true)->firstOrFail();
  $setting = WidgetSetting::firstWhere('shop_id', $shop->id);
  $config = $setting ? $setting->publicConfig() : WidgetSetting::disabledConfig();
  return response()->view('widget.script', compact('config'))
   ->header('Content-Type','application/javascript; charset=UTF-8')
   ->header('Cache-Control','public, max-age=60')
   ->header('X-Content-Type-Options','nosniff');
 }

 public function image(string $shopDomain, string $path){
  if (!preg_match(self::SHOP_DOMAIN_PATTERN, $shopDomain) || str_contains($path, '..')) abort(404);
  $shop = Shop::where('shop_domain', $shopDomain)->where('installed', true)->firstOrFail();
  $prefix = 'widget/'.$shop->id.'/';
  if (!str_starts_with($path, $prefix) || !Storage::disk('public')->exists($path)) abort(404);
  return Storage::disk('public')->response($path, null, [
   'Cache-Control' => 'public, max-age=3600',
   'X-Content-Type-Options' => 'nosniff',
  ]);
 }

 public function capture(Request $r, string $shopDomain){
  if (!preg_match(self::SHOP_DOMAIN_PATTERN, $shopDomain)) abort(404);
  $shop = Shop::where('shop_domain', $shopDomain)->where('installed', true)->firstOrFail();
  $data = $r->validate([
   'name' => ['required','string','max:120'],
   'phone' => ['required','string','max:20'],
   'source' => ['nullable','string','in:entry,exit'],
  ]);
  $digits = PhoneIdentity::normalize($data['phone']);
  if (strlen($digits) < 8) {
   throw ValidationException::withMessages(['phone' => 'Enter a valid WhatsApp number with country code.']);
  }
  $hash = PhoneIdentity::hash($digits);
  $source = 'website_'.($data['source'] ?? 'entry');
  $values = [
   'phone' => $digits,
   'contact_name' => mb_substr(trim($data['name']), 0, 120),
   'status' => 'opted_in',
   'source' => $source,
   'consented_at' => now(),
   'opted_out_at' => null,
  ];
  $consent = WhatsAppConsent::where('shop_id', $shop->id)->where('phone_hash', $hash)->first();
  if ($consent) {
   // An explicit new form submission is fresh consent and can reverse a previous STOP.
   $consent->update($values);
  } else {
   WhatsAppConsent::create(array_merge($values, ['shop_id' => $shop->id, 'phone_hash' => $hash]));
  }
  return response()->json(['ok' => true]);
 }

 private function storeImage(Request $r, string $field, Shop $shop, string $oldPath, bool $remove): ?string {
  $disk = Storage::disk('public');
  if ($r->hasFile($field)) {
   $file = $r->file($field);
   $name = Str::random(24).'.'.strtolower($file->getClientOriginalExtension() ?: 'png');
   $path = $file->storeAs('widget/'.$shop->id, $name, 'public');
   if ($oldPath && $oldPath !== $path && $disk->exists($oldPath)) $disk->delete($oldPath);
   return $path;
  }
  if ($remove && $oldPath) {
   if ($disk->exists($oldPath)) $disk->delete($oldPath);
   return null;
  }
  return $oldPath ?: null;
 }

 private function imageUrl(Shop $shop, ?string $path): ?string {
  if (!$path || !Storage::disk('public')->exists($path)) return null;
  return url('/api/widget/'.$shop->shop_domain.'/image/'.$path);
 }
}
