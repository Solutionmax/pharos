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

    public function test_a_partner_without_an_official_file_keeps_its_letters(): void
    {
        $html = view('partials.brand-mark', ['mark' => 'slack'])->render();

        if (is_file(public_path('brand/partners/slack.svg'))) {
            $this->markTestSkipped('The official Slack mark has been added.');
        }

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('>Sl<', $html);
    }
}
