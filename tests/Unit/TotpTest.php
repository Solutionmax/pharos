<?php

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** The RFC 6238 secret, "12345678901234567890" in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public static function rfcVectors(): array
    {
        // Appendix B, SHA-1 column, truncated to the six digits we use.
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_test_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, (new Totp)->at(self::RFC_SECRET, intdiv($time, 30)));
    }

    public function test_a_code_from_the_current_step_verifies(): void
    {
        $totp = new Totp;
        $now = 1234567890;
        $code = $totp->at(self::RFC_SECRET, intdiv($now, 30));

        $this->assertSame(intdiv($now, 30), $totp->verify(self::RFC_SECRET, $code, null, $now));
    }

    public function test_one_step_of_clock_drift_is_tolerated(): void
    {
        $totp = new Totp;
        $now = 1234567890;

        foreach ([-1, 1] as $drift) {
            $code = $totp->at(self::RFC_SECRET, intdiv($now, 30) + $drift);
            $this->assertNotNull($totp->verify(self::RFC_SECRET, $code, null, $now));
        }

        $tooOld = $totp->at(self::RFC_SECRET, intdiv($now, 30) - 2);
        $this->assertNull($totp->verify(self::RFC_SECRET, $tooOld, null, $now));
    }

    public function test_a_code_cannot_be_replayed_within_its_own_window(): void
    {
        $totp = new Totp;
        $now = 1234567890;
        $step = intdiv($now, 30);
        $code = $totp->at(self::RFC_SECRET, $step);

        $this->assertSame($step, $totp->verify(self::RFC_SECRET, $code, null, $now));
        $this->assertNull($totp->verify(self::RFC_SECRET, $code, $step, $now));
    }

    public function test_rubbish_is_refused(): void
    {
        $totp = new Totp;

        $this->assertNull($totp->verify(self::RFC_SECRET, '000000', null, 1234567890));
        $this->assertNull($totp->verify(self::RFC_SECRET, 'abcdef', null, 1234567890));
        $this->assertNull($totp->verify(self::RFC_SECRET, '', null, 1234567890));
    }

    public function test_a_generated_secret_round_trips(): void
    {
        $totp = new Totp;
        $secret = $totp->secret();

        $this->assertSame(32, strlen($secret));
        $this->assertSame(20, strlen($totp->base32Decode($secret)));
        $this->assertStringContainsString('secret='.$secret, $totp->uri($secret, 'you@example.net', 'Pharos'));
    }
}
