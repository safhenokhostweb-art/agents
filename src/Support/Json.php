<?php

declare(strict_types=1);

namespace Workspace\Agents\Support;

use JsonException;
use RuntimeException;

final class Json
{
    public static function decodeFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read JSON file: {$path}");
        }

        return self::decode($raw, $path);
    }

    public static function decode(string $raw, string $label = 'JSON input'): array
    {
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$label}: ".$exception->getMessage());
        }
        if (! is_array($value)) {
            throw new RuntimeException("Expected a JSON object in {$label}.");
        }

        return $value;
    }

    public static function encode(mixed $value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    /**
     * Key-sorted compact encoding: the byte form every integrity digest is
     * computed over, so a digest never depends on key insertion order.
     */
    public static function canonical(mixed $value): string
    {
        return self::encode(self::sortKeys($value), false);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
