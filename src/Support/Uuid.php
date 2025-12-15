<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Support;

final class Uuid
{
    private const NAMESPACE_DNS = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private function __construct() {}

    public static function isUuid(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        return (bool)preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $v
        );
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return self::bytesToUuid($bytes);
    }

    public static function v5(string $namespaceUuid, string $name): string
    {
        $ns = strtolower(trim($namespaceUuid));
        if (!self::isUuid($ns)) {
            throw new \InvalidArgumentException('Invalid UUID namespace.');
        }

        $hash = sha1(self::uuidToBytes($ns) . $name, true);
        $bytes = substr($hash, 0, 16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::bytesToUuid($bytes);
    }

    public static function normalize(string $input, string $salt = ''): string
    {
        $in = strtolower(trim($input));
        if ($in !== '' && self::isUuid($in)) {
            return $in;
        }

        $name = $salt !== '' ? ($salt . '|' . $input) : $input;
        return self::v5(self::NAMESPACE_DNS, $name);
    }

    private static function uuidToBytes(string $uuid): string
    {
        $hex = str_replace('-', '', strtolower($uuid));
        if (strlen($hex) !== 32) {
            throw new \InvalidArgumentException('Invalid UUID.');
        }

        $bin = hex2bin($hex);
        if (!is_string($bin) || strlen($bin) !== 16) {
            throw new \InvalidArgumentException('Invalid UUID.');
        }

        return $bin;
    }

    private static function bytesToUuid(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('Invalid UUID bytes.');
        }

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

