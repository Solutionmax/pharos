<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\PasskeyChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use lbuchs\WebAuthn\WebAuthn;

class Passkeys
{
    public function server(): WebAuthn
    {
        $origin = $this->origin();

        return new WebAuthn('Pharos', parse_url($origin, PHP_URL_HOST), ['none'], true);
    }

    public function origin(): string
    {
        $origin = rtrim((string) config('monitoring.webauthn_origin'), '/');
        $parts = parse_url($origin);
        if (! $parts || ! isset($parts['host']) || filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP) || (isset($parts['path']) && $parts['path'] !== '') || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || (! str_starts_with($origin, 'https://') && $parts['host'] !== 'localhost')) {
            throw ValidationException::withMessages(['passkey' => __('Passkeys require an HTTPS hostname, or localhost for testing.')]);
        }

        return $origin;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new \RuntimeException('Invalid encoding');
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false || self::encode($decoded) !== $text) {
            throw new \RuntimeException('Invalid encoding');
        }

        return $decoded;
    }

    public static function handle(User $user): string
    {
        return hash('sha256', 'pharos-user:'.$user->id, true);
    }

    public function options(Request $r, string $kind): object
    {
        $server = $this->server();
        if ($kind === 'create') {
            $user = $r->user();
            $ids = Passkey::where('user_id', $user->id)->pluck('credential_id')->map(fn ($id) => self::decode($id))->all();
            $args = $server->getCreateArgs(self::handle($user), $user->email, $user->name, 60, true, true, null, $ids);
        } else {
            $args = $server->getGetArgs([], 60, true, true, true, true, true, true);
        }
        $challenge = PasskeyChallenge::create(['id' => (string) Str::uuid(), 'user_id' => $kind === 'create' ? $r->user()->id : null, 'ceremony' => $kind, 'challenge' => self::encode($server->getChallenge()->getBinaryString()), 'expires_at' => now()->addMinutes(5)]);
        $r->session()->put('passkey.'.$kind, $challenge->id);

        return $args;
    }

    private function consume(Request $r, string $kind): string
    {
        $id = $r->session()->pull('passkey.'.$kind);
        $query = PasskeyChallenge::whereKey($id)->where('ceremony', $kind)->whereNull('consumed_at')->where('expires_at', '>', now());
        if ($kind === 'create') {
            $query->where('user_id', $r->user()->id);
        }
        $row = (clone $query)->first();
        if (! $row || $query->update(['consumed_at' => now()]) !== 1) {
            throw new \RuntimeException('Challenge expired');
        }

        return self::decode($row->challenge);
    }

    private function client(string $encoded): string
    {
        $json = self::decode($encoded);
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['origin'] ?? null) !== $this->origin() || ($data['crossOrigin'] ?? false) !== false || isset($data['topOrigin'])) {
            throw new \RuntimeException('Invalid origin');
        }

        return $json;
    }

    public function register(Request $r, array $data): Passkey
    {
        $challenge = $this->consume($r, 'create');
        $server = $this->server();
        $result = $server->processCreate($this->client($data['clientDataJSON']), self::decode($data['attestationObject']), $challenge, true, true);

        return Passkey::create(['user_id' => $r->user()->id, 'credential_id' => self::encode($result->credentialId), 'public_key' => $result->credentialPublicKey, 'name' => $data['name'], 'counter' => $result->signatureCounter ?? 0]);
    }

    public function authenticate(Request $r, array $data): User
    {
        $challenge = $this->consume($r, 'get');

        return DB::transaction(function () use ($data, $challenge) {
            $key = Passkey::where('credential_id', $data['id'])->lockForUpdate()->first();
            if (! $key || ! $key->user || ! hash_equals(self::handle($key->user), self::decode($data['userHandle']))) {
                throw new \RuntimeException('Invalid credential');
            }
            $server = $this->server();
            $server->processGet($this->client($data['clientDataJSON']), self::decode($data['authenticatorData']), self::decode($data['signature']), $key->public_key, $challenge, $key->counter, true, true);
            $key->update(['counter' => $server->getSignatureCounter() ?? $key->counter, 'last_used_at' => now()]);

            return $key->user;
        });
    }
}
