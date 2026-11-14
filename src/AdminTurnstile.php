<?php
declare(strict_types=1);
namespace Iluma\Gateway;

/**
 * Admin-only Cloudflare Turnstile validation. Never trust a client token
 * until Siteverify confirms success, gateway hostname and login action.
 */
final class AdminTurnstile
{
    private const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const ACTION = 'admin_login';

    public static function siteKey(): string
    {
        return trim(Config::get('CLOUDFLARE_TURNSTILE_SITE_KEY'));
    }

    public static function configured(): bool
    {
        $site = self::siteKey();
        $secret = trim(Config::get('CLOUDFLARE_TURNSTILE_SECRET_KEY'));
        return $site !== '' && $secret !== ''
            && !str_starts_with($site, 'REPLACE')
            && !str_starts_with($secret, 'REPLACE');
    }

    public static function action(): string
    {
        return self::ACTION;
    }

    public static function validResponse(string $json, string $expectedHost): bool
    {
        if ($json === '' || strlen($json) > 8192 || $expectedHost === '') return false;
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        return is_array($data)
            && ($data['success'] ?? null) === true
            && ($data['hostname'] ?? null) === strtolower($expectedHost)
            && ($data['action'] ?? null) === self::ACTION;
    }

    public static function verify(string $token, string $remoteIp = ''): bool
    {
        if (!self::configured()
            || $token === ''
            || strlen($token) > 2048
            || preg_match('/[\x00-\x20\x7f]/', $token)) return false;

        $payload = [
            'secret' => Config::get('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
            'response' => $token,
        ];
        if (filter_var($remoteIp, FILTER_VALIDATE_IP)) $payload['remoteip'] = $remoteIp;

        $curl = curl_init(self::URL);
        if ($curl === false) return false;
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status === 200 && is_string($response)
            && self::validResponse($response, strtolower(Config::get('GATEWAY_HOST')));
    }
}
