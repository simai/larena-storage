<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * The stored form of one encrypted field value:
 * `{"larena_enc":1,"a":"xc20p","k":<key id>,"n":<nonce>,"c":<ciphertext>}`, nonce and ciphertext as unpadded,
 * canonical base64url. `larena_enc` is the format of the envelope, data and not a version of any name.
 *
 * The shape is strict: exactly these five members, the one algorithm, a key id of the storage's data keys, a
 * 24-byte nonce and a ciphertext of a padded plaintext (a multiple of 32 bytes) plus the 16-byte tag. Anything else
 * is `storage_field_envelope_invalid`. The envelope says nothing about the value but its padded length.
 */
final readonly class StorageFieldEnvelope
{
    public const MARKER = 'larena_enc';
    public const FORMAT = 1;
    public const ALGORITHM = 'xc20p';
    public const NONCE_BYTES = 24;
    public const TAG_BYTES = 16;
    public const KEY_ID_PATTERN = '/\Adk-[a-f0-9]{24}\z/D';
    public const REASON_INVALID = 'storage_field_envelope_invalid';

    /** A record holds at most 1 MiB of values; the padded plaintext of one never needs more than this. */
    private const MAX_CIPHERTEXT_BYTES = 2_097_152;
    private const MIN_PLAINTEXT_BYTES = 32;
    private const MEMBERS = ['a', 'c', 'k', 'larena_enc', 'n'];

    public function __construct(
        public string $keyId,
        public string $nonce,
        public string $ciphertext,
    ) {
        $length = strlen($ciphertext);
        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1
            || strlen($nonce) !== self::NONCE_BYTES
            || $length < self::MIN_PLAINTEXT_BYTES + self::TAG_BYTES
            || $length > self::MAX_CIPHERTEXT_BYTES
            || ($length - self::TAG_BYTES) % self::MIN_PLAINTEXT_BYTES !== 0) {
            throw new StorageRejected(self::REASON_INVALID);
        }
    }

    /** Whether a stored value claims to be an envelope (it carries the marker); fromValue() checks the rest. */
    public static function isEnvelope(mixed $value): bool
    {
        return is_array($value) && array_key_exists(self::MARKER, $value);
    }

    /**
     * Accepts exactly the shape toArray() produces.
     *
     * @throws StorageRejected storage_field_envelope_invalid
     */
    public static function fromValue(mixed $value): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new StorageRejected(self::REASON_INVALID);
        }
        $members = array_map('strval', array_keys($value));
        sort($members, SORT_STRING);
        if ($members !== self::MEMBERS
            || ($value[self::MARKER] ?? null) !== self::FORMAT
            || ($value['a'] ?? null) !== self::ALGORITHM
            || !is_string($value['k'] ?? null)
            || !is_string($value['n'] ?? null)
            || !is_string($value['c'] ?? null)) {
            throw new StorageRejected(self::REASON_INVALID);
        }

        return new self($value['k'], self::decode($value['n']), self::decode($value['c']));
    }

    /** @return array{larena_enc: int, a: string, k: string, n: string, c: string} */
    public function toArray(): array
    {
        return [
            self::MARKER => self::FORMAT,
            'a' => self::ALGORITHM,
            'k' => $this->keyId,
            'n' => self::encode($this->nonce),
            'c' => self::encode($this->ciphertext),
        ];
    }

    private static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function decode(string $text): string
    {
        if ($text === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $text) !== 1 || strlen($text) % 4 === 1) {
            throw new StorageRejected(self::REASON_INVALID);
        }
        $binary = base64_decode(strtr($text, '-_', '+/'), true);
        // A non-canonical encoding (stray low bits in the last character) is refused.
        if ($binary === false || self::encode($binary) !== $text) {
            throw new StorageRejected(self::REASON_INVALID);
        }

        return $binary;
    }
}
