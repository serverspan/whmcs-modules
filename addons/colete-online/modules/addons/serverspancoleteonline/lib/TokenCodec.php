<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

use RuntimeException;

final class TokenCodec
{
    public static function encode(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        if (function_exists('localAPI')) {
            $result = localAPI('EncryptPassword', ['password2' => $plain]);
            if (($result['result'] ?? '') === 'success' && !empty($result['password'])) {
                return 'whmcs:' . $result['password'];
            }
        }

        if (function_exists('encrypt')) {
            return 'whmcs:' . encrypt($plain);
        }

        throw new RuntimeException('WHMCS encryption service is unavailable.');
    }

    public static function decode(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, 'whmcs:')) {
            throw new RuntimeException('Unsupported token cache encoding.');
        }
        $cipher = substr($stored, 6);

        if (function_exists('localAPI')) {
            $result = localAPI('DecryptPassword', ['password2' => $cipher]);
            if (($result['result'] ?? '') === 'success' && array_key_exists('password', $result)) {
                return (string) $result['password'];
            }
        }

        if (function_exists('decrypt')) {
            return (string) decrypt($cipher);
        }

        throw new RuntimeException('WHMCS decryption service is unavailable.');
    }
}
