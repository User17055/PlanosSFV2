<?php

namespace App\Services\Domain;

class Fingerprint
{
    // MySQL's JSON type reorders object keys on write, so hashes use sorted keys to match what is read back.
    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    public static function json(mixed $value): string
    {
        return json_encode(self::canonical(json_decode(json_encode($value, JSON_THROW_ON_ERROR), true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
