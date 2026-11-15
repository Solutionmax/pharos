<?php

namespace Tests\Unit;

use App\Services\Branding;
use App\Support\Contrast;
use PHPUnit\Framework\TestCase;

class ContrastTest extends TestCase
{
    public function test_the_ratio_follows_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#777', '#777777'), 0.001);
        // The accent the review measured: Pharos blue on the page background.
        $this->assertEqualsWithDelta(4.38, Contrast::ratio('#0079d2', '#fbfcfe'), 0.01);
    }

    public function test_a_light_page_gets_a_darker_link_that_reads_at_aa(): void
    {
        $link = Contrast::readable('#0079d2', Branding::LIGHT_SURFACES, darken: true);

        $this->assertNotSame('#0079d2', $link);
        foreach (Branding::LIGHT_SURFACES as $surface) {
            $this->assertGreaterThanOrEqual(Contrast::AA, Contrast::ratio($link, $surface));
        }
        // Only as much darker as it takes: still clearly the same blue.
        $this->assertLessThan(5.2, Contrast::ratio($link, '#fbfcfe'));
    }

    public function test_a_dark_page_gets_a_lighter_link_that_reads_at_aa(): void
    {
        $link = Contrast::readable('#0079d2', Branding::DARK_SURFACES, darken: false);

        $this->assertNotSame('#0079d2', $link);
        foreach (Branding::DARK_SURFACES as $surface) {
            $this->assertGreaterThanOrEqual(Contrast::AA, Contrast::ratio($link, $surface));
        }
    }

    public function test_an_accent_that_already_reads_is_left_alone(): void
    {
        $this->assertSame('#0e1726', Contrast::readable('#0E1726', Branding::LIGHT_SURFACES, darken: true));
        $this->assertSame('#ffd166', Contrast::readable('#ffd166', Branding::DARK_SURFACES, darken: false));
    }

    public function test_a_pale_accent_on_light_still_ends_readable_and_junk_is_returned_untouched(): void
    {
        $link = Contrast::readable('#ffff00', Branding::LIGHT_SURFACES, darken: true);
        $this->assertGreaterThanOrEqual(Contrast::AA, Contrast::ratio($link, '#fbfcfe'));

        $this->assertSame('not-a-colour', Contrast::readable('not-a-colour', Branding::LIGHT_SURFACES, darken: true));
    }
}
