<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Partner logos: an official file in public/brand/partners is shown unaltered
 * (full colour, no recolouring, as the brand guidelines ask); without one the
 * tile falls back to letters instead of a homemade logo.
 */
class BrandMarkTest extends TestCase
{
    public function test_an_official_partner_file_is_shown_unaltered(): void
    {
        $html = view('partials.brand-mark', ['mark' => 'teams'])->render();

        $this->assertStringContainsString('lg-official', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*/brand/partners/teams\.svg\?v=\w+" alt=""#', $html);
    }

    public function test_both_official_partner_files_are_used(): void
    {
        foreach (['slack', 'teams'] as $mark) {
            $html = view('partials.brand-mark', ['mark' => $mark])->render();
            $this->assertStringContainsString("brand/partners/{$mark}.svg", $html, $mark);
            $this->assertStringContainsString('lg-official', $html, $mark);
        }
    }
}
