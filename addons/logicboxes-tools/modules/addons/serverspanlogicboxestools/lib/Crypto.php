<?php
namespace ServerSpan\LogicBoxesTools;

final class Crypto
{
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('WHMCS Local API is unavailable.');
        }
        $result = localAPI('EncryptPassword', ['password2' => $plaintext]);
        if (($result['result'] ?? '') !== 'success' || empty($result['password'])) {
            throw new \RuntimeException('WHMCS failed to encrypt the LogicBoxes API key.');
        }
        return (string) $result['password'];
    }

    public static function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('WHMCS Local API is unavailable.');
        }
        $result = localAPI('DecryptPassword', ['password2' => $ciphertext]);
        if (($result['result'] ?? '') !== 'success' || !array_key_exists('password', $result)) {
            throw new \RuntimeException('WHMCS failed to decrypt the LogicBoxes API key.');
        }
        return (string) $result['password'];
    }
}
