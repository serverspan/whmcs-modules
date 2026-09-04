<?php
namespace ServerSpan\LogicBoxesTools;

final class Support
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(?string $value, array $default = []): array
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function bool(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    public static function randomPassword(int $length = 24): string
    {
        $length = max(16, $length);
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#_-';
        $all = $lower . $upper . $digits . $symbols;
        $password = $lower[random_int(0, strlen($lower) - 1)]
            . $upper[random_int(0, strlen($upper) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)]
            . $symbols[random_int(0, strlen($symbols) - 1)];
        while (strlen($password) < $length) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    public static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $secretKeys = [
            'api-key', 'api_key', 'apikey', 'password', 'passwd', 'password2',
            'auth-code', 'eppcode', 'token', 'auth-token', 'secret', 'key',
        ];
        $out = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            $out[$key] = in_array($normalized, $secretKeys, true)
                ? '[REDACTED]'
                : (is_array($item) ? self::redact($item) : $item);
        }
        return $out;
    }

    public static function canonicalDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        return rtrim($domain, '.');
    }

    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return ['LogicBoxes', 'Customer'];
        }
        $parts = explode(' ', $name, 2);
        return [$parts[0], $parts[1] ?? '-'];
    }

    public static function normalizePhone(string $phone, string $country = ''): array
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            throw new \InvalidArgumentException('Telephone number is empty.');
        }

        if (str_starts_with($phone, '+')) {
            $callingCodes = self::callingCodes();
            for ($len = 3; $len >= 1; --$len) {
                $candidate = substr($digits, 0, $len);
                if (isset($callingCodes[$candidate])) {
                    $subscriber = substr($digits, $len);
                    if ($subscriber === '') {
                        break;
                    }
                    return [$candidate, $subscriber];
                }
            }
        }

        $isoMap = [
            'RO' => '40', 'US' => '1', 'CA' => '1', 'GB' => '44', 'DE' => '49', 'FR' => '33',
            'IT' => '39', 'ES' => '34', 'NL' => '31', 'BE' => '32', 'AT' => '43', 'CH' => '41',
            'PL' => '48', 'HU' => '36', 'BG' => '359', 'GR' => '30', 'PT' => '351', 'IE' => '353',
            'CZ' => '420', 'SK' => '421', 'SE' => '46', 'NO' => '47', 'DK' => '45', 'FI' => '358',
            'IN' => '91', 'AU' => '61', 'NZ' => '64', 'SG' => '65', 'AE' => '971', 'ZA' => '27',
        ];
        $cc = $isoMap[strtoupper($country)] ?? null;
        if ($cc === null) {
            throw new \InvalidArgumentException('Phone number must include an international +country code for this country.');
        }
        $subscriber = ltrim($digits, '0');
        return [$cc, $subscriber === '' ? $digits : $subscriber];
    }

    private static function callingCodes(): array
    {
        $codes = [
            '1','7','20','27','30','31','32','33','34','36','39','40','41','43','44','45','46','47','48','49',
            '51','52','53','54','55','56','57','58','60','61','62','63','64','65','66','81','82','84','86','90','91','92','93','94','95','98',
            '211','212','213','216','218','220','221','222','223','224','225','226','227','228','229','230','231','232','233','234','235','236','237','238','239','240','241','242','243','244','245','246','248','249','250','251','252','253','254','255','256','257','258','260','261','262','263','264','265','266','267','268','269',
            '290','291','297','298','299','350','351','352','353','354','355','356','357','358','359','370','371','372','373','374','375','376','377','378','379','380','381','382','383','385','386','387','389',
            '420','421','423','500','501','502','503','504','505','506','507','508','509','590','591','592','593','594','595','596','597','598','599',
            '670','672','673','674','675','676','677','678','679','680','681','682','683','685','686','687','688','689','690','691','692','850','852','853','855','856','880','886',
            '960','961','962','963','964','965','966','967','968','970','971','972','973','974','975','976','977','992','993','994','995','996','998'
        ];
        return array_fill_keys($codes, true);
    }

    public static function actionMap(): array
    {
        return [
            'addnewdomain' => 'register',
            'renewdomain' => 'renew',
            'addtransferdomain' => 'transfer',
            'restoredomain' => 'restore',
        ];
    }

    public static function firstValue(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    }

    public static function flattenRows(array $payload, string $idKey): array
    {
        $rows = [];
        $needle = strtolower($idKey);
        $walk = function (mixed $value) use (&$walk, &$rows, $needle): void {
            if (!is_array($value)) {
                return;
            }
            $matched = null;
            foreach ($value as $key => $item) {
                $normalized = strtolower((string) $key);
                if ($normalized === $needle || str_ends_with($normalized, '.' . $needle)) {
                    $matched = (string) $item;
                    break;
                }
            }
            if ($matched !== null && $matched !== '') {
                $rows[$matched] = $value;
                return;
            }
            foreach ($value as $item) {
                $walk($item);
            }
        };
        $walk($payload);
        return array_values($rows);
    }
}
