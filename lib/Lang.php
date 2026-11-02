<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * The adapter's own words for clients, in English and Ukrainian
 * (lang/en.php, lang/uk.php), picked by FOSSBilling's active locale
 * (uk_UA → Ukrainian). Anything else falls back to English, word by word.
 *
 * Only what clients read on the payment page is translated. The settings
 * form and the notes on transactions and invoices stay in English, like
 * FOSSBilling's own payment adapters.
 */
final class Lang
{
    /** @var array<string, array<string, string>> */
    private static array $files = [];

    /**
     * @param array<string, string> $strings
     * @param array<string, string> $fallback
     */
    private function __construct(public readonly string $name, private readonly array $strings, private readonly array $fallback)
    {
    }

    /** "uk_UA", "uk", "en_US" … → the file for it. */
    public static function named(?string $locale): self
    {
        $name = str_starts_with(strtolower(trim((string) $locale)), 'uk') ? 'uk' : 'en';

        return new self($name, self::file($name), self::file('en'));
    }

    /** The text for a key, with sprintf placeholders filled; the key itself if neither file has it. */
    public function t(string $key, string ...$values): string
    {
        $text = $this->strings[$key] ?? $this->fallback[$key] ?? $key;

        return $values === [] ? $text : vsprintf($text, $values);
    }

    /** @return array<string, string> */
    private static function file(string $name): array
    {
        if (!isset(self::$files[$name])) {
            $path = dirname(__DIR__) . '/lang/' . $name . '.php';
            $strings = is_readable($path) ? require $path : [];
            self::$files[$name] = is_array($strings) ? $strings : [];
        }

        return self::$files[$name];
    }
}
