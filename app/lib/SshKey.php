<?php
declare(strict_types=1);

namespace Bootstrap;

use RuntimeException;

/**
 * SSH public key helpers. The private key is only ever written to a 0600 temp
 * file for the duration of a git/ssh operation.
 */
final class SshKey
{
    public static function publicFromPrivate(string $privateKey): ?string
    {
        $trimmed = trim($privateKey);

        if (str_contains($trimmed, 'BEGIN OPENSSH PRIVATE KEY')) {
            return self::ed25519PublicFromOpenSsh($trimmed);
        }

        if (str_contains($trimmed, 'BEGIN') && str_contains($trimmed, 'PRIVATE KEY')) {
            // RSA (PKCS#8 or PKCS#1) - convert with OpenSSL and rebuild the SSH blob.
            $resource = openssl_pkey_get_private($trimmed);
            if ($resource === false) {
                return null;
            }
            $details = openssl_pkey_get_details($resource);
            if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
                return null;
            }

            $blob = self::sshString('ssh-rsa')
                . self::mpint($details['rsa']['e'])
                . self::mpint($details['rsa']['n']);

            return 'ssh-rsa ' . base64_encode($blob);
        }

        return null;
    }

    private static function ed25519PublicFromOpenSsh(string $pem): ?string
    {
        if (!preg_match('/-----BEGIN OPENSSH PRIVATE KEY-----(.+?)-----END OPENSSH PRIVATE KEY-----/s', $pem, $match)) {
            return null;
        }

        $blob = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);
        if ($blob === false || !str_starts_with($blob, "openssh-key-v1\0")) {
            return null;
        }

        $offset = strlen("openssh-key-v1\0");
        $read = static function () use (&$offset, $blob): ?string {
            if ($offset + 4 > strlen($blob)) {
                return null;
            }
            $length = unpack('N', substr($blob, $offset, 4))[1] ?? 0;
            $offset += 4;
            if ($length < 0 || $offset + $length > strlen($blob)) {
                return null;
            }
            $value = substr($blob, $offset, $length);
            $offset += $length;

            return $value;
        };

        $cipher = $read();
        $kdf = $read();
        $kdfOptions = $read();
        if ($cipher !== 'none' || $kdf !== 'none' || $kdfOptions === null) {
            return null;
        }

        $offset += 4; // number of keys
        $publicBlob = $read();
        $privateBlob = $read();
        if ($publicBlob === null || $privateBlob === null) {
            return null;
        }

        $inner = $privateBlob;
        $innerOffset = 0;
        $readInner = static function () use (&$innerOffset, $inner): ?string {
            if ($innerOffset + 4 > strlen($inner)) {
                return null;
            }
            $length = unpack('N', substr($inner, $innerOffset, 4))[1] ?? 0;
            $innerOffset += 4;
            if ($length < 0 || $innerOffset + $length > strlen($inner)) {
                return null;
            }
            $value = substr($inner, $innerOffset, $length);
            $innerOffset += $length;

            return $value;
        };

        $innerOffset += 8; // check integers
        $keyType = $readInner();
        $public = $readInner();
        if ($keyType !== 'ssh-ed25519' || $public === null || strlen($public) !== 32) {
            return null;
        }

        return 'ssh-ed25519 ' . base64_encode(self::sshString('ssh-ed25519') . self::sshString($public));
    }

    private static function sshString(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    private static function mpint(string|int $value): string
    {
        if (is_int($value)) {
            $value = self::intToBytes($value);
        }

        $value = ltrim($value, "\x00");
        if ($value === '') {
            return pack('N', 0);
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return pack('N', strlen($value)) . $value;
    }

    private static function intToBytes(int $value): string
    {
        if (function_exists('gmp_export')) {
            return gmp_export($value);
        }

        $hex = dechex($value);
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }

        return $hex === '' ? '' : (string) hex2bin($hex);
    }

    public static function describe(string $publicKeyLine): string
    {
        try {
            $parts = preg_split('/\s+/', trim($publicKeyLine)) ?: [];

            return ($parts[0] ?? 'ssh') . ' ' . Crypto::fingerprint($publicKeyLine);
        } catch (\Throwable) {
            return 'ssh (unknown)';
        }
    }
}
