<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Codes a shopper shows at the till for things they hold: a loyalty card
 * (LOY-), a gift certificate (GIFT-) or a membership (MEM-). The prefix tells
 * the Scan page what it is looking at; the rest avoids look-alike characters
 * so it can be read out and typed.
 */
class WalletCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(string $prefix, int $length = 8): string
    {
        $body = '';
        for ($i = 0; $i < $length; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $prefix . '-' . $body;
    }

    /** "loy-7k3q xp2m " → "LOY-7K3QXP2M" */
    public static function normalize(string $code): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($code)));
    }

    public static function prefixOf(string $code): ?string
    {
        return preg_match('/^(LOY|GIFT|MEM)-[A-Z0-9]+$/', $code, $m) ? $m[1] : null;
    }

    /** A fresh code not yet used in $table.$column. */
    public static function unique(string $prefix, string $table, string $column = 'code'): string
    {
        do {
            $code = self::make($prefix);
        } while (\DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}
