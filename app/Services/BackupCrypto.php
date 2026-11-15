<?php

namespace App\Services;

use RuntimeException;

/**
 * Encrypts a remote backup archive with a passphrase of its own, not APP_KEY:
 * whoever can read the bucket must not be able to open the archive, and the
 * archive holds .env with APP_KEY in it.
 *
 * libsodium only. The key comes from the passphrase with Argon2id; the file is
 * a secretstream (XChaCha20 Poly1305) in chunks, so it is encrypted and checked
 * without holding the archive in memory, and a truncated or reordered file is
 * refused. Layout, all integers big endian:
 *
 *   "PHAROSBK"  8 bytes   magic
 *   version     1 byte    1
 *   algorithm   1 byte    1 = Argon2id13 + secretstream XChaCha20 Poly1305
 *   opslimit    4 bytes   Argon2id passes
 *   memlimit    4 bytes   Argon2id memory, bytes
 *   chunk       4 bytes   plaintext bytes per chunk
 *   salt        16 bytes
 *   header      24 bytes  secretstream header
 *   chunks      each one chunk of ciphertext plus 17 bytes; the last is tagged final
 *
 * The first 38 bytes (magic up to the salt) are authenticated with every chunk.
 */
class BackupCrypto
{
    public const MAGIC = 'PHAROSBK';

    public const EXTENSION = '.enc';

    private const VERSION = 1;

    private const ALGORITHM = 1;

    private const PREFIX_BYTES = 38;

    private const CHUNK_BYTES = 1048576;

    /** libsodium's floor for Argon2id (crypto_pwhash_OPSLIMIT_MIN, _MEMLIMIT_MIN); PHP has no constant. */
    public const MIN_OPSLIMIT = 1;

    public const MIN_MEMLIMIT = 8192;

    /** Argon2id cost: three passes over 64 MiB, which shared hosting can afford. */
    public function __construct(
        private int $opslimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        private int $memlimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
    ) {}

    /** @throws RuntimeException */
    public function encryptFile(string $source, string $target, string $passphrase): void
    {
        $this->guardPassphrase($passphrase);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $prefix = self::MAGIC.chr(self::VERSION).chr(self::ALGORITHM).pack('NNN', $this->opslimit, $this->memlimit, self::CHUNK_BYTES).$salt;
        $key = $this->key($passphrase, $salt, $this->opslimit, $this->memlimit);

        [$in, $out] = $this->open($source, $target);

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->write($out, $prefix.$header);

            $current = $this->read($in, self::CHUNK_BYTES);
            do {
                $next = $this->read($in, self::CHUNK_BYTES);
                $final = $next === '';
                $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $this->write($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, $prefix, $tag));
                $current = $next;
            } while (! $final);
        } catch (\Throwable $e) {
            $this->abandon($out, $target);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('The backup could not be encrypted.', 0, $e);
        } finally {
            fclose($in);
            sodium_memzero($key);
        }

        fclose($out);
    }

    /** @throws RuntimeException when the passphrase is wrong or the file is damaged or not ours */
    public function decryptFile(string $source, string $target, string $passphrase): void
    {
        [$in, $out] = $this->open($source, $target);
        $key = null;

        try {
            $prefix = $this->read($in, self::PREFIX_BYTES);
            [$opslimit, $memlimit, $chunk, $salt] = $this->parse($prefix);
            $header = $this->read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('This file is not a Pharos encrypted backup, or it is cut short.');
            }

            $key = $this->key($passphrase, $salt, $opslimit, $memlimit);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $first = true;

            while (true) {
                $block = $this->read($in, $chunk + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($block === '') {
                    throw new RuntimeException('The encrypted backup is cut short.');
                }

                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block, $prefix);
                if ($opened === false) {
                    throw new RuntimeException($first
                        ? 'The passphrase is wrong, or the file is damaged.'
                        : 'The encrypted backup is damaged.');
                }
                $first = false;

                [$plain, $tag] = $opened;
                $this->write($out, $plain);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($this->read($in, 1) !== '') {
                        throw new RuntimeException('The encrypted backup has data after its end.');
                    }

                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->abandon($out, $target);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('The backup could not be decrypted.', 0, $e);
        } finally {
            fclose($in);
            if (is_string($key)) {
                sodium_memzero($key);
            }
        }

        fclose($out);
    }

    /** Does the file start like one of ours? */
    public static function looksEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $start = (string) fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $start === self::MAGIC;
    }

    /**
     * The cost settings come from a file someone may have crafted, so they are
     * held to sane bounds before Argon2id is asked to honour them.
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function parse(string $prefix): array
    {
        if (strlen($prefix) !== self::PREFIX_BYTES || ! str_starts_with($prefix, self::MAGIC)) {
            throw new RuntimeException('This file is not a Pharos encrypted backup.');
        }
        if (ord($prefix[8]) !== self::VERSION || ord($prefix[9]) !== self::ALGORITHM) {
            throw new RuntimeException('This encrypted backup was made by a newer Pharos. Decrypt it with that version.');
        }

        /** @var array{ops: int, mem: int, chunk: int} $numbers */
        $numbers = unpack('Nops/Nmem/Nchunk', substr($prefix, 10, 12));
        if ($numbers['ops'] < self::MIN_OPSLIMIT || $numbers['ops'] > 16
            || $numbers['mem'] < self::MIN_MEMLIMIT || $numbers['mem'] > SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE
            || $numbers['chunk'] < 1024 || $numbers['chunk'] > 16777216) {
            throw new RuntimeException('The encrypted backup has settings out of range.');
        }

        return [$numbers['ops'], $numbers['mem'], $numbers['chunk'], substr($prefix, 22, SODIUM_CRYPTO_PWHASH_SALTBYTES)];
    }

    private function key(string $passphrase, string $salt, int $opslimit, int $memlimit): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private function guardPassphrase(string $passphrase): void
    {
        if ($passphrase === '') {
            throw new RuntimeException('A backup passphrase is required.');
        }
    }

    /** @return array{0: resource, 1: resource} */
    private function open(string $source, string $target): array
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException("Cannot read {$source}.");
        }

        $previous = umask(0077);
        $out = @fopen($target, 'xb');
        umask($previous);
        if ($out === false) {
            fclose($in);

            throw new RuntimeException("Cannot create {$target}; it may exist already.");
        }

        return [$in, $out];
    }

    /** Up to $length bytes, fewer only at the end of the file. */
    private function read($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && ! feof($handle)) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false) {
                throw new RuntimeException('Reading the backup failed.');
            }
            if ($part === '') {
                break;
            }
            $data .= $part;
        }

        return $data;
    }

    private function write($handle, string $data): void
    {
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('Writing the backup failed. Is the disk full?');
        }
    }

    /** A half written file is worse than none. */
    private function abandon($out, string $target): void
    {
        if (is_resource($out)) {
            fclose($out);
        }
        @unlink($target);
    }
}
