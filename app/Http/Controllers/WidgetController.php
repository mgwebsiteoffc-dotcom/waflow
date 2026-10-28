<?php
namespace App\Http\Controllers;
use App\Models\Shop;
use App\Models\WhatsAppConsent;
use App\Models\WidgetSetting;
use App\Services\PhoneIdentity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class WidgetController extends Controller {
 private const SHOP_DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

 public function edit(Request $r){
  $shop = $r->attributes->get('currentShop');
  $setting = WidgetSetting::firstOrNew(['shop_id' => $shop->id], WidgetSetting::defaults());
  $leads = $shop->whatsappConsents()
   ->where('source','like','website_%')
   ->latest('consented_at')->latest('id')->limit(15)->get();
  $scriptUrl = url('/api/widget/'.$shop->shop_domain.'/widget.js');
  $snippet = '<script defer src="'.$scriptUrl.'"></script>';
  return view('widget.edit', compact('setting','leads','scriptUrl','snippet'));
 }

 public function update(Request $r){
  $shop = $r->attributes->get('currentShop');
  $d = WidgetSetting::defaults();
  $data = $r->validate([
   'entry_title' => ['nullable','string','max:150'],
   'entry_text' => ['nullable','string','max:500'],
   'entry_button_label' => ['nullable','string','max:60'],
   'entry_delay_seconds' => ['integer','min:0','max:300'],
   'entry_frequency_days' => ['integer','min:0','max:90'],
   'exit_title' => ['nullable','string','max:150'],
   'exit_text' => ['nullable','string','max:500'],
   'exit_button_label' => ['nullable','string','max:60'],
   'exit_frequency_days' => ['integer','min:0','max:90'],
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
  $payload = array_merge($d, [
   'shop_id' => $shop->id,
   // Explicit normalized values below deliberately replace the raw validated input.
   'entry_enabled' => $r->boolean('entry_enabled'),
   'entry_title' => trim((string) $r->input('entry_title', $d['entry_title'])) ?: $d['entry_title'],
   'entry_text' => trim((string) $r->input('entry_text', $d['entry_text'])) ?: $d['entry_text'],
   'entry_button_label' => trim((string) $r->input('entry_button_label', $d['entry_button_label'])) ?: $d['entry_button_label'],
   'entry_delay_seconds' => (int) $r->input('entry_delay_seconds', $d['entry_delay_seconds']),
   'entry_frequency_days' => (int) $r->input('entry_frequency_days', $d['entry_frequency_days']),
   'exit_enabled' => $r->boolean('exit_enabled'),
   'exit_title' => trim((string) $r->input('exit_title', $d['exit_title'])) ?: $d['exit_title'],
   'exit_text' => trim((string) $r->input('exit_text', $d['exit_text'])) ?: $d['exit_text'],
   'exit_button_label' => trim((string) $r->input('exit_button_label', $d['exit_button_label'])) ?: $d['exit_button_label'],
   'exit_frequency_days' => (int) $r->input('exit_frequency_days', $d['exit_frequency_days']),
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
}
