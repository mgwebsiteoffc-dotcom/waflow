<?php
namespace App\Services;
class PhoneIdentity {
 public static function normalize(?string $phone): string { return preg_replace('/\D+/', '', (string) $phone) ?? ''; }
 public static function hash(?string $phone): ?string {
  $normalized = self::normalize($phone);
  if ($normalized === '') return null;
  $key = (string) config('services.data_hash_key');
  if ($key === '') $key = (string) config('app.key');
  return hash_hmac('sha256', $normalized, $key);
 }
 /** Mask a stored number for screen display, e.g. 9198•••••1234. */
 public static function mask(?string $phone): string {
  $normalized = self::normalize($phone);
  if ($normalized === '') return '—';
  if (strlen($normalized) <= 4) return str_repeat('•', strlen($normalized));
  $head = substr($normalized, 0, max(2, strlen($normalized) - 7));
  $tail = substr($normalized, -4);
  return $head.str_repeat('•', max(3, strlen($normalized) - strlen($head) - 4)).$tail;
 }
}
