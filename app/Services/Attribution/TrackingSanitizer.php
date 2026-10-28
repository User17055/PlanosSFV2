<?php

namespace App\Services\Attribution;

class TrackingSanitizer
{
    public const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public const SOURCES = ['direct', 'referral', 'shared_link', 'instagram', 'facebook', 'google', 'other'];

    public static function sourceLabels(): array
    {
        return ['unknown' => 'Não informado', 'direct' => 'Acesso direto', 'referral' => 'Indicação', 'shared_link' => 'Link compartilhado', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'google' => 'Google', 'other' => 'Outra origem'];
    }

    public static function text(mixed $value, int $length = 120): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags(mb_scrub((string) $value, 'UTF-8')))) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    public static function utm(mixed $value): ?string
    {
        $value = self::text($value);
        if ($value === null || preg_match('/(?:[?&](?:token|key|secret|password|code|session|gclid|fbclid)=|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $value)) {
            return null;
        }

        return $value;
    }

    public static function referrerHost(?string $url): ?string
    {
        if (! $url || strlen($url) > 4096) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && ! filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^[a-z0-9.\-]{1,253}$/i', $host) ? strtolower($host) : null;
    }

    public static function source(?string $utmSource, ?string $referrerHost): string
    {
        $value = strtolower($utmSource ?? '');
        if (in_array($value, ['instagram', 'ig'], true)) {
            return 'instagram';
        }
        if (in_array($value, ['facebook', 'fb', 'meta'], true)) {
            return 'facebook';
        }
        if (in_array($value, ['google', 'google_ads', 'adwords'], true)) {
            return 'google';
        }
        if ($value === '') {
            if ($referrerHost === 'instagram.com' || str_ends_with($referrerHost ?? '', '.instagram.com')) {
                return 'instagram';
            }
            if ($referrerHost === 'facebook.com' || str_ends_with($referrerHost ?? '', '.facebook.com')) {
                return 'facebook';
            }
            foreach (['google.com', 'google.com.br', 'google.pt', 'google.es', 'google.co.uk', 'google.com.ar'] as $domain) {
                if ($referrerHost === $domain || str_ends_with($referrerHost ?? '', '.'.$domain)) {
                    return 'google';
                }
            }
        }

        return $value !== '' || $referrerHost !== null ? 'other' : 'direct';
    }
}
