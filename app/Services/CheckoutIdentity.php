<?php
namespace App\Services;

class CheckoutIdentity
{
    public static function hash(?string $token): ?string
    {
        $token = trim((string) $token);
        if ($token === '') return null;
        $key = (string) config('services.data_hash_key');
        if ($key === '') $key = (string) config('app.key');
        return hash_hmac('sha256', $token, $key);
    }
}
