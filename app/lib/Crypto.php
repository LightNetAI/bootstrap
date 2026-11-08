<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * Client side of the BootStrapServer transport envelope.
 *
 * Mirrors BootStrapServer\Security\Crypto. Changing either side requires
 * changing both.
 */
final class Crypto
{
    public const ENVELOPE_VERSION = 1;
    public const KDF_ALGORITHM = 'pbkdf2-sha256';
    public const CIPHER_ALGORITHM = 'aes-256-gcm';
    public const KEY_BYTES = 32;
    public const NONCE_BYTES = 12;
    public const TAG_BYTES = 16;
    public const SALT_BYTES = 16;

    public static function deriveKey(string $password, string $salt, int $iterations): string
    {
        $key = hash_pbkdf2('sha256', $password, $salt, $iterations, self::KEY_BYTES, true);
        if (strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('Key derivation failed.');
        }

        return $key;
    }

    public static function b64d(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new RuntimeException('Invalid base64 data in key envelope.');
        }

        return $raw;
    }

    /**
     * Decrypt the SSH private key returned by BootStrapServer and prove that it
     * matches the advertised public key.
     *
     * @param array<string, mixed> $envelope
     */
    public static function openEnvelope(string $password, array $envelope, string $username): string
    {
        return self::openEnvelopeSecrets($password, $envelope, $username)['private_key'];
    }

    /**
     * Open the envelope and return both secrets: the SSH private key and the
     * optional GitHub token stored by BootStrapServer.
     *
     * @param array<string, mixed> $envelope
     * @return array{private_key: string, github_token: string}
     */
    public static function openEnvelopeSecrets(string $password, array $envelope, string $username): array
    {
        if (!isset($envelope['v'], $envelope['kdf'], $envelope['cipher'], $envelope['key'], $envelope['sealed'])) {
            throw new RuntimeException('Key envelope is incomplete.');
        }
        if ((int) $envelope['v'] !== self::ENVELOPE_VERSION) {
            throw new RuntimeException('Unsupported key envelope version.');
        }

        $kdf = $envelope['kdf'];
        $cipher = $envelope['cipher'];
        $keyInfo = $envelope['key'];

        if (!is_array($kdf) || ($kdf['alg'] ?? '') !== self::KDF_ALGORITHM) {
            throw new RuntimeException('Unsupported key derivation algorithm.');
        }
        if (!is_array($cipher) || ($cipher['alg'] ?? '') !== self::CIPHER_ALGORITHM) {
            throw new RuntimeException('Unsupported envelope cipher.');
        }
        if (!is_array($keyInfo) || !isset($keyInfo['public'], $keyInfo['fingerprint'])) {
            throw new RuntimeException('Key envelope has no public key.');
        }

        $iterations = (int) ($kdf['iterations'] ?? 0);
        if ($iterations < 10000 || $iterations > 2000000) {
            throw new RuntimeException('Refusing an unreasonable KDF iteration count.');
        }

        $salt = self::b64d((string) $kdf['salt']);
        if (strlen($salt) !== self::SALT_BYTES) {
            throw new RuntimeException('Bad salt length in key envelope.');
        }

        $aad = (string) $cipher['aad'];
        $expectedAad = self::transportAad($username, (string) $keyInfo['fingerprint']);
        if (!hash_equals($expectedAad, $aad)) {
            throw new RuntimeException('Key envelope is not bound to this account.');
        }

        $key = self::deriveKey($password, $salt, $iterations);
        $privateKey = openssl_decrypt(
            self::b64d((string) $envelope['sealed']),
            self::CIPHER_ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            self::b64d((string) $cipher['nonce']),
            self::b64d((string) $cipher['tag']),
            $aad
        );

        if ($privateKey === false) {
            throw new RuntimeException('Could not decrypt the SSH key. Check the password and backend integrity.');
        }

        $derived = SshKey::publicFromPrivate($privateKey);
        if ($derived === null || !hash_equals(self::publicBlob($derived), self::publicBlob((string) $keyInfo['public']))) {
            throw new RuntimeException('Decrypted private key does not match the advertised public key.');
        }

        $fingerprint = self::fingerprint((string) $keyInfo['public']);
        if (!hash_equals($fingerprint, (string) $keyInfo['fingerprint'])) {
            throw new RuntimeException('Public key fingerprint mismatch.');
        }

        $githubToken = '';
        if (isset($envelope['token'])) {
            $tokenBlock = $envelope['token'];
            if (!is_array($tokenBlock)
                || ($tokenBlock['alg'] ?? '') !== self::CIPHER_ALGORITHM
                || !isset($tokenBlock['nonce'], $tokenBlock['tag'], $tokenBlock['aad'], $tokenBlock['sealed'])) {
                throw new RuntimeException('Malformed GitHub token block in the envelope.');
            }

            $expectedTokenAad = self::transportTokenAad($username, (string) $keyInfo['fingerprint']);
            if (!hash_equals($expectedTokenAad, (string) $tokenBlock['aad'])) {
                throw new RuntimeException('GitHub token block is not bound to this account.');
            }

            $decryptedToken = openssl_decrypt(
                self::b64d((string) $tokenBlock['sealed']),
                self::CIPHER_ALGORITHM,
                $key,
                OPENSSL_RAW_DATA,
                self::b64d((string) $tokenBlock['nonce']),
                self::b64d((string) $tokenBlock['tag']),
                $expectedTokenAad
            );
            if ($decryptedToken === false) {
                throw new RuntimeException('Could not decrypt the GitHub token.');
            }
            $githubToken = trim($decryptedToken);
        }

        return ['private_key' => $privateKey, 'github_token' => $githubToken];
    }

    public static function transportAad(string $username, string $fingerprint): string
    {
        return 'bskey:transport:v1|' . strtolower(trim($username)) . '|' . $fingerprint;
    }

    public static function transportTokenAad(string $username, string $fingerprint): string
    {
        return 'bskey:transport-token:v1|' . strtolower(trim($username)) . '|' . $fingerprint;
    }

    public static function fingerprint(string $publicKeyLine): string
    {
        $blob = self::b64d(self::publicBlob($publicKeyLine));

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public static function publicBlob(string $publicKeyLine): string
    {
        $parts = preg_split('/\s+/', trim($publicKeyLine)) ?: [];
        if (count($parts) < 2) {
            throw new RuntimeException('Malformed SSH public key.');
        }

        return $parts[1];
    }

    public static function timingSafeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
