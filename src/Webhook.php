<?php
declare(strict_types=1);
namespace Waix;
final class Webhook {
    public static function verify(string $rawBody, string $timestamp, string $signature, string $secret, int $tolerance = 300, ?int $now = null): bool {
        if ($tolerance < 0 || $secret === '' || !preg_match('/^[0-9]{10,12}$/D', $timestamp) || !preg_match('/^v1=[a-f0-9]{64}$/iD', $signature) || abs(($now ?? time()) - (int)$timestamp) > $tolerance) return false;
        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), strtolower(substr($signature, 3)));
    }
}
