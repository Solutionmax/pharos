<?php

namespace App\Services;

/** From rendered Markdown to what a mail client can show: inline styles, or plain text. */
class MailMarkup
{
    /** Mail clients ignore stylesheets, so every tag carries its own style. */
    public static function style(string $html, string $accent): string
    {
        // A paragraph that is nothing but a link becomes a button — the one
        // thing Markdown has no word for.
        $html = preg_replace(
            '/<p><a href="([^"]*)">([^<]*)<\/a><\/p>/',
            '<p style="margin:0 0 22px"><a href="$1" style="display:inline-block;background:'.$accent.';color:#ffffff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:10px">$2</a></p>',
            $html,
        );

        $styles = [
            'h1' => 'margin:0 0 14px;font-size:20px;font-weight:700;letter-spacing:-.02em',
            'h2' => 'margin:0 0 12px;font-size:17px;font-weight:700;letter-spacing:-.02em',
            'h3' => 'margin:0 0 10px;font-size:15px;font-weight:700',
            'p' => 'margin:0 0 14px',
            'ul' => 'margin:0 0 14px;padding-left:22px',
            'ol' => 'margin:0 0 14px;padding-left:22px',
            'blockquote' => 'margin:0 0 14px;padding:14px 16px;border-left:3px solid '.$accent.';background:#f2f6fb;border-radius:0 10px 10px 0',
            'a' => 'color:'.$accent,
            'code' => 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;background:#f2f6fb;padding:1px 5px;border-radius:5px',
            'hr' => 'border:0;border-top:1px solid #e8edf4;margin:18px 0',
        ];

        foreach ($styles as $tag => $style) {
            // Only bare tags: the button above already carries its own style.
            $html = preg_replace('/<'.$tag.'(?=[\s>\/])(?![^>]*\bstyle=)/', '<'.$tag.' style="'.$style.'"', $html);
        }

        return $html;
    }

    /** The plain-text alternative. Links go out raw: an &amp; here breaks them in a text-only client. */
    public static function text(string $html): string
    {
        $text = preg_replace_callback('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', function ($m) {
            $label = trim(strip_tags($m[2]));

            return $label === '' || $label === $m[1] ? $m[1] : $label.': '.$m[1];
        }, $html);

        $text = preg_replace('/<br\s*\/?>\n?/i', "\n", $text);
        $text = preg_replace('/<hr\s*\/?>\n?/i', "---\n\n", $text);
        $text = preg_replace('/<li[^>]*>/i', '- ', $text);
        $text = preg_replace('/<\/li>\n?/i', "\n", $text);
        $text = preg_replace('/<\/(?:p|h[1-6]|blockquote|ul|ol|pre|div)>\n?/i', "\n\n", $text);

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
