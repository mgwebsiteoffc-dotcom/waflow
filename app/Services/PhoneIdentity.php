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
}
