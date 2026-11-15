<?php

namespace App\Support;

/**
 * WCAG contrast for the operator's brand colour. The accent stays as chosen
 * for fills and buttons; text in that colour (links) gets a shade of it that
 * can be read: darker on the light theme, lighter on the dark one.
 */
final class Contrast
{
    /** WCAG AA for body text. */
    public const AA = 4.5;

    private const STEP = 0.02;

    /** Relative luminance of a #rgb or #rrggbb colour, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex) ?? [0, 0, 0];

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** Contrast ratio between two colours, from 1 (none) to 21 (black on white). */
    public static function ratio(string $a, string $b): float
    {
        [$light, $dark] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    /**
     * The colour, mixed step by step toward black (darken) or white (lighten)
     * until it reaches $target against every background. Unchanged when it
     * already does; unchanged as well when it is not a hex colour at all.
     *
     * @param  list<string>  $backgrounds
     */
    public static function readable(string $color, array $backgrounds, bool $darken, float $target = self::AA): string
    {
        $rgb = self::rgb($color);
        if ($rgb === null || $backgrounds === []) {
            return $color;
        }

        $toward = $darken ? 0 : 255;
        for ($share = 0.0; $share <= 1.0 + 1e-9; $share += self::STEP) {
            $hex = sprintf('#%02x%02x%02x', ...array_map(fn (int $c) => (int) round($c + ($toward - $c) * min(1.0, $share)), $rgb));
            $worst = min(array_map(fn (string $background) => self::ratio($hex, $background), $backgrounds));
            if ($worst >= $target) {
                return $share === 0.0 ? strtolower($color) : $hex;
            }
        }

        return $darken ? '#000000' : '#ffffff';
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    private static function rgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-f]{3}$/i', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            return null;
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
