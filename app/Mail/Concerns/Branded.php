<?php

namespace App\Mail\Concerns;

use App\Services\Branding;
use Illuminate\Mail\Mailables\Address;

/**
 * What every mail from this install shares: who it is from and how it looks.
 * Phase 2 (customer-editable templates) swaps the view bodies, not this.
 */
trait Branded
{
    protected function branding(): Branding
    {
        return app(Branding::class);
    }

    /** The sender. MAIL_FROM_NAME left blank means the brand name, not "Laravel". */
    protected function brandedFrom(): Address
    {
        return new Address(
            (string) config('mail.from.address'),
            (string) (config('mail.from.name') ?: $this->branding()->name()),
        );
    }

    /**
     * The layout's variables. url() rather than the stored path: a mail is read
     * away from the site, so a relative logo path shows a broken image.
     *
     * @return array<string, mixed>
     */
    protected function brandVars(): array
    {
        $branding = $this->branding();
        $logo = $branding->logoUrl();

        return [
            'brand' => $branding->name(),
            'accent' => $branding->accent(),
            'logo' => $logo ? url($logo) : null,
            'link' => route('status'),
        ];
    }
}
