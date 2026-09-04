<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

final class StatusParser
{
    public static function history(array $response): array
    {
        if (!isset($response['history']) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        $history = $response['history'] ?? [];
        if (!is_array($history)) {
            return [];
        }
        $out = [];
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'date_time' => self::dateTime($item),
                'code' => (string) ($item['code'] ?? ''),
                'text' => self::localized($item['statusTextParts'] ?? $item['statusText'] ?? null),
                'comment' => self::localized($item['comment'] ?? null),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $b['date_time'], (string) $a['date_time']));
        return $out;
    }

    public static function latest(array $response): array
    {
        $history = self::history($response);
        return $history[0] ?? ['date_time' => '', 'code' => '', 'text' => '', 'comment' => ''];
    }

    private static function localized(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['ro'])) {
            $ro = $value['ro'];
            if (is_string($ro)) {
                return trim($ro);
            }
            if (is_array($ro)) {
                foreach (['name', 'text', 'value'] as $key) {
                    if (isset($ro[$key]) && is_scalar($ro[$key])) {
                        return trim((string) $ro[$key]);
                    }
                }
            }
        }
        foreach (['name', 'text', 'value', 'en'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                return trim((string) $value[$key]);
            }
        }
        return '';
    }

    private static function dateTime(array $item): string
    {
        if (!empty($item['dateTime'])) {
            $value = (string) $item['dateTime'];
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return $value;
            }
        }
        if (isset($item['unixDateTime']) && is_numeric($item['unixDateTime'])) {
            return date('Y-m-d H:i:s', (int) $item['unixDateTime']);
        }
        return '';
    }
}
