<?php

namespace App\Support;

use App\Models\StatusPage;
use App\Services\License;
use Illuminate\Support\Carbon;

/**
 * What the installation holds, read once for the screens that explain it.
 * Nothing here decides access: License and the controllers do. This only puts
 * the same answers side by side so a page can say them in plain words.
 */
final class LicencePlan
{
    /**
     * The four plans as sold, cheapest first. Wording only: which features a
     * key carries decides what works, and prices live in the shop.
     *
     * @var array<string, array{name: string, term: string, slug: ?string, includes: list<string>}>
     */
    public const PLANS = [
        'free' => ['name' => 'Free', 'term' => 'Always', 'slug' => null, 'includes' => ['1 status page', 'Pharos branding']],
        'brand_pack' => ['name' => 'Brand pack', 'term' => 'One time', 'slug' => 'brand-pack', 'includes' => ['Your own branding', '1 status page']],
        'supported' => ['name' => 'Supported', 'term' => 'Yearly', 'slug' => 'supported', 'includes' => ['Brand pack', 'Support', 'Up to 5 status pages']],
        'commercial' => ['name' => 'Commercial', 'term' => 'Yearly', 'slug' => 'commercial', 'includes' => ['Everything in Supported', 'Unlimited status pages']],
    ];

    /** @param list<string> $signedFeatures */
    private function __construct(
        public readonly bool $hasKey,
        public readonly array $signedFeatures,
        public readonly bool $brandPack,
        public readonly bool $multiPage,
        public readonly ?int $pageLimit,
        public readonly ?int $signedPageLimit,
        public readonly int $activePages,
        public readonly bool $expired,
        public readonly ?Carbon $expiresAt,
        public readonly ?int $daysLeft,
        public readonly ?string $issuedTo,
        public readonly ?string $boundTo,
    ) {}

    public static function read(License $license): self
    {
        $payload = $license->payload();
        $features = array_values(array_filter((array) ($payload['features'] ?? []), 'is_string'));
        $signedLimit = $payload['limits']['status_pages'] ?? null;

        return new self(
            hasKey: $payload !== null,
            signedFeatures: $features,
            brandPack: $license->has(License::FEATURE_BRAND_PACK),
            multiPage: $license->has(License::FEATURE_MULTI_PAGES),
            pageLimit: $license->statusPageLimit(),
            signedPageLimit: is_int($signedLimit) ? $signedLimit : null,
            activePages: StatusPage::query()->whereNull('archived_at')->count(),
            expired: $license->expired(),
            expiresAt: $license->expiresAt(),
            daysLeft: $license->daysLeft(),
            issuedTo: $license->issuedTo(),
            boundTo: $payload !== null ? $license->boundTo($payload) : null,
        );
    }

    /** The key names the feature, whether or not its term still runs. */
    public function signed(string $feature): bool
    {
        return in_array($feature, $this->signedFeatures, true);
    }

    /** Multi page was bought but the term is over. */
    public function multiPageEnded(): bool
    {
        return $this->expired && $this->signed(License::FEATURE_MULTI_PAGES);
    }

    /** Another page can be created or an archived one brought back. */
    public function hasRoom(): bool
    {
        return $this->pageLimit === null || $this->activePages < $this->pageLimit;
    }

    /**
     * The plan this installation matches today. A lapsed Supported key keeps its
     * Brand pack, so it reads as Brand pack; a Multi page key with a ceiling
     * reads as Supported, one without as Commercial.
     */
    public function current(): string
    {
        return match (true) {
            $this->multiPage && $this->pageLimit === null => 'commercial',
            $this->multiPage => 'supported',
            $this->brandPack => 'brand_pack',
            default => 'free',
        };
    }

    /** A plan above the current one, worth a button. */
    public function isUpgrade(string $plan): bool
    {
        $order = array_keys(self::PLANS);

        return array_search($plan, $order, true) > array_search($this->current(), $order, true);
    }

    public static function buyUrl(string $plan): ?string
    {
        $slug = self::PLANS[$plan]['slug'] ?? null;

        return $slug ? rtrim((string) config('pharos.portal_buy_url'), '/').'/'.$slug : null;
    }

    public function name(): string
    {
        return self::PLANS[$this->current()]['name'];
    }
}
