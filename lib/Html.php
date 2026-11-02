<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/** Everything the adapter prints goes through here. */
final class Html
{
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** A value for an inline script: JSON that cannot close the script tag or break out of it. */
    public static function js(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** At most $length characters of UTF-8 text (no mbstring needed); invalid UTF-8 becomes "". */
    public static function cut(string $text, int $length): string
    {
        return preg_match('/^.{0,' . $length . '}/su', $text, $m) === 1 ? $m[0] : '';
    }

    /** An http(s) URL, or null: nothing else is ever put in an href or a Location. */
    public static function url(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /** "https://pay.perly.io" from a URL, or null. */
    public static function origin(string $url): ?string
    {
        if (self::url($url) === null) {
            return null;
        }
        $parts = (array) parse_url($url);

        return strtolower((string) $parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
