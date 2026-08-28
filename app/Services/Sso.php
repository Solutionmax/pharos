<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OpenID Connect, authorization code flow with PKCE, confidential client.
 *
 * Signs in people who already have an account; it never creates one. See
 * docs/sso.md for why there is no JWT library here.
 */
class Sso
{
    public function __construct(protected SafeHttp $safe) {}

    public function enabled(): bool
    {
        return (bool) Setting::get('sso.enabled', false)
            && $this->issuer() && $this->clientId() && $this->clientSecret();
    }

    public function providerName(): string
    {
        return Setting::get('sso.provider_name') ?: 'single sign-on';
    }

    public function issuer(): ?string
    {
        return Setting::get('sso.issuer') ?: null;
    }

    public function clientId(): ?string
    {
        return Setting::get('sso.client_id') ?: null;
    }

    public function clientSecret(): ?string
    {
        $stored = Setting::get('sso.client_secret') ?: null;

        if ($stored === null) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($stored);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return $stored; // saved before secrets were encrypted; re-saving the form fixes it
        }
    }

    /**
     * The provider's own description of its endpoints. Cached: it changes about
     * never, and a slow provider must not make our login page slow.
     *
     * @return array<string, mixed>
     */
    public function discover(?string $issuer = null): array
    {
        $issuer = rtrim($issuer ?: (string) $this->issuer(), '/');

        return Cache::remember('sso.discovery.'.md5($issuer), 3600, function () use ($issuer) {
            $response = $this->safe->to($issuer.'/.well-known/openid-configuration')
                ->get($issuer.'/.well-known/openid-configuration');

            if (! $response->successful()) {
                throw new \RuntimeException('The provider answered '.$response->status().' on its discovery URL.');
            }

            $doc = $response->json();

            foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $key) {
                if (! is_array($doc) || ! is_string($doc[$key] ?? null)) {
                    throw new \RuntimeException("The discovery document has no {$key}.");
                }
            }

            return $doc;
        });
    }

    /**
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function begin(string $redirectUri): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => $this->discover()['authorization_endpoint'].'?'.$query,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
        ];
    }

    /**
     * Exchanges the code and returns the verified claims.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on anything that does not add up
     */
    public function claims(string $code, string $verifier, string $nonce, string $redirectUri): array
    {
        $discovery = $this->discover();

        // Through the same guard as discovery. The document is the provider's
        // word, not ours, and a token_endpoint on 169.254.169.254 would post the
        // client secret to the cloud metadata service. Demanding the issuer's
        // host instead is not an option: Google's token endpoint lives elsewhere.
        $response = $this->safe->to($discovery['token_endpoint'])->timeout(8)->asForm()->post($discovery['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('The provider refused the sign-in.');
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken)) {
            throw new \RuntimeException('The provider returned no id_token.');
        }

        return $this->verify($this->payload($idToken), $nonce, $discovery['issuer']);
    }

    /**
     * The claims arrived over the back channel, so the transport already proves
     * the issuer and the signature needs no separate check. Everything the token
     * says about *who* and *for whom* still does.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public function verify(array $claims, string $nonce, string $issuer): array
    {
        $audience = (array) ($claims['aud'] ?? []);

        return match (true) {
            rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($issuer, '/') => throw new \RuntimeException('The token came from a different issuer.'),
            ! in_array($this->clientId(), $audience, true) => throw new \RuntimeException('The token was not meant for this application.'),
            ! hash_equals($nonce, (string) ($claims['nonce'] ?? '')) => throw new \RuntimeException('The token does not answer this sign-in.'),
            ((int) ($claims['exp'] ?? 0)) < time() => throw new \RuntimeException('The token has expired.'),
            ! filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL) => throw new \RuntimeException('The provider sent no usable email address.'),
            // The email decides which account you become, so an unverified one
            // would let anyone who can register at the provider claim it.
            ($claims['email_verified'] ?? false) !== true => throw new \RuntimeException('The provider has not verified that email address.'),
            default => $claims,
        };
    }

    /** @return array<string, mixed> */
    protected function payload(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new \RuntimeException('The id_token is malformed.');
        }

        $decoded = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('The id_token carries no readable claims.');
        }

        return $decoded;
    }
}
