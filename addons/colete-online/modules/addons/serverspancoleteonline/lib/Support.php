<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

final class Support
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function isOn(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true'], true);
    }

    public static function toFloat(mixed $value, float $default = 0.0): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function safeJson(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function currentAdminHasModuleAccess(string $module): bool
    {
        if (!isset($_SESSION['adminid']) || (int) $_SESSION['adminid'] < 1) {
            return false;
        }

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return false;
        }

        try {
            $admin = \WHMCS\Database\Capsule::table('tbladmins')
                ->select('roleid')
                ->where('id', (int) $_SESSION['adminid'])
                ->first();
            if (!$admin) {
                return false;
            }

            $access = null;
            if (class_exists('WHMCS\\Module\\Addon\\Setting')) {
                $access = \WHMCS\Module\Addon\Setting::getSettingValueForModule($module, 'access');
            }
            if ($access === null) {
                $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
                    ->select('value')
                    ->where('module', $module)
                    ->where('setting', 'access')
                    ->first();
                $access = $row?->value;
            }

            $roles = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string) $access) ?: [])));
            return $roles === [] || in_array((int) $admin->roleid, $roles, true);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setting(string $module, string $name): ?string
    {
        if (class_exists('WHMCS\\Module\\Addon\\Setting')) {
            $value = \WHMCS\Module\Addon\Setting::getSettingValueForModule($module, $name);
            if ($value !== null) {
                return (string) $value;
            }
        }

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return null;
        }
        $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->select('value')
            ->where('module', $module)
            ->where('setting', $name)
            ->first();
        return $row ? (string) $row->value : null;
    }


    public static function systemUrl(): string
    {
        if (class_exists('WHMCS\\Config\\Setting')) {
            $url = \WHMCS\Config\Setting::getValue('SystemURL');
            if (is_string($url) && trim($url) !== '') {
                return rtrim($url, '/');
            }
        }
        $global = $GLOBALS['CONFIG']['SystemURL'] ?? '';
        return is_string($global) ? rtrim($global, '/') : '';
    }

    public static function csrfInput(): string
    {
        return function_exists('generate_token') ? (string) generate_token() : '';
    }

    public static function checkCsrf(): void
    {
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
        }
    }
}
