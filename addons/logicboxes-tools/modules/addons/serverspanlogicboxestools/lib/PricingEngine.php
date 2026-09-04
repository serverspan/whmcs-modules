<?php
namespace ServerSpan\LogicBoxesTools;

final class PricingEngine
{
    private const ACTIONS = [
        'addnewdomain' => 'register',
        'renewdomain' => 'renew',
        'addtransferdomain' => 'transfer',
        'restoredomain' => 'restore',
    ];

    public function extractDomainMatrices(array $pricing): array
    {
        $out = [];
        $walk = function (mixed $value, ?string $productKey = null) use (&$walk, &$out): void {
            if (!is_array($value)) {
                return;
            }
            if ($productKey !== null && $this->looksLikeDomainPricing($value)) {
                $out[$productKey] = $this->normalizeMatrix($value);
                return;
            }
            foreach ($value as $key => $child) {
                if (!is_array($child)) {
                    continue;
                }
                $candidate = is_string($key) && $key !== '' ? $key : $productKey;
                if ($candidate !== null && $this->looksLikeDomainPricing($child)) {
                    $out[$candidate] = $this->normalizeMatrix($child);
                } else {
                    $walk($child, $candidate);
                }
            }
        };
        $walk($pricing);
        return array_filter($out, static fn(array $m): bool => $m['register'] !== [] || $m['renew'] !== [] || $m['transfer'] !== []);
    }

    public function resolveProductTld(string $productKey, array $productDetails = [], array $manualMap = [], array $signedTlds = []): ?string
    {
        $productKey = trim($productKey);
        if (isset($manualMap[$productKey])) {
            return $this->normalizeTld((string)$manualMap[$productKey]);
        }

        $details = $productDetails[$productKey] ?? [];
        if (is_array($details)) {
            $candidates = [];
            $collect = function (mixed $value) use (&$collect, &$candidates): void {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $collect($v);
                    }
                    return;
                }
                if (!is_string($value)) {
                    return;
                }
                if (preg_match_all('/(?<![a-z0-9-])(\.[a-z0-9-]+(?:\.[a-z0-9-]+)?)(?![a-z0-9-])/i', strtolower($value), $m)) {
                    foreach ($m[1] as $candidate) {
                        $candidates[$candidate] = true;
                    }
                }
            };
            $collect($details);
            if (count($candidates) === 1) {
                return array_key_first($candidates);
            }
        }

        $signed = [];
        foreach ($signedTlds as $key => $value) {
            $candidate = is_string($key) && !is_numeric($key) ? $key : (is_string($value) ? $value : '');
            $candidate = $this->normalizeTld($candidate);
            if ($candidate !== null) {
                $signed[$candidate] = true;
            }
        }

        $lower = strtolower($productKey);
        if (preg_match('/^dot([a-z0-9-]+)$/', $lower, $m)) {
            $candidate = '.' . $m[1];
            if ($signed === [] || isset($signed[$candidate])) {
                return $candidate;
            }
        }
        if (preg_match('/^(?:thirdlevel)?dot([a-z0-9-]+)$/', $lower, $m)) {
            $candidate = '.' . $m[1];
            if ($signed === [] || isset($signed[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    public function buildSellingMatrix(array $matrix, array $policy, int $multiplier = 1): array
    {
        $source = strtolower((string)($policy['source'] ?? 'customer'));
        $marginType = strtolower((string)($policy['margin_type'] ?? 'percent'));
        $margin = (float)($policy['margin'] ?? 0);
        $roundTo = max(0.0001, (float)($policy['round_to'] ?? 0.01));
        $roundMode = strtolower((string)($policy['round_mode'] ?? 'nearest'));
        $multiplier = max(1, $multiplier);
        $out = [];
        foreach (['register', 'renew', 'transfer', 'restore'] as $action) {
            $out[$action] = [];
            foreach ((array)($matrix[$action] ?? []) as $period => $raw) {
                if (!is_numeric($raw)) {
                    continue;
                }
                $price = ((float)$raw) / $multiplier;
                if ($source === 'cost') {
                    $price = $marginType === 'fixed'
                        ? $price + $margin
                        : $price * (1 + ($margin / 100));
                }
                if ($price < 0) {
                    continue;
                }
                $out[$action][(int)$period] = $this->roundCommercial($price, $roundTo, $roundMode);
            }
            ksort($out[$action], SORT_NUMERIC);
        }
        return $out;
    }

    public function buildWhmcsPayload(string $tld, array $selling, array $account, array $tldInfo = []): array
    {
        $register = array_slice($selling['register'] ?? [], 0, 10, true);
        $renew = array_slice($selling['renew'] ?? [], 0, 9, true);
        $transfer = [];
        if (($selling['transfer'] ?? []) !== []) {
            $firstPeriod = array_key_first($selling['transfer']);
            $transfer[$firstPeriod] = $selling['transfer'][$firstPeriod];
        }
        $info = $tldInfo[ltrim($tld, '.')] ?? $tldInfo[$tld] ?? [];
        $payload = [
            'extension' => $tld,
            'auto_registrar' => (string)$account['registrar_module'],
            'currency_code' => strtoupper((string)$account['currency']),
            'register' => $register,
            'renew' => $renew,
            'transfer' => $transfer,
        ];
        if (is_array($info)) {
            if (array_key_exists('privacy_available', $info)) {
                $payload['id_protection'] = Support::bool($info['privacy_available']);
            }
            if (array_key_exists('epp_required', $info)) {
                $payload['epp_required'] = Support::bool($info['epp_required']);
            }
        }
        return $payload;
    }

    public function findTldForDomain(string $domain, array $configuredTlds): ?string
    {
        $domain = Support::canonicalDomain($domain);
        $matches = [];
        foreach ($configuredTlds as $tld) {
            $tld = $this->normalizeTld((string)$tld);
            if ($tld !== null && ($domain === ltrim($tld, '.') || str_ends_with($domain, $tld))) {
                $matches[] = $tld;
            }
        }
        usort($matches, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $matches[0] ?? null;
    }

    public function priceForPeriod(array $pricing, string $action, int $period): ?float
    {
        $bucket = $pricing[$action] ?? [];
        foreach ([$period, (string)$period] as $key) {
            if (isset($bucket[$key]) && is_numeric($bucket[$key])) {
                return (float)$bucket[$key];
            }
        }
        return null;
    }

    private function normalizeMatrix(array $raw): array
    {
        $matrix = ['register' => [], 'renew' => [], 'transfer' => [], 'restore' => []];
        foreach (self::ACTIONS as $remote => $local) {
            $bucket = $raw[$remote] ?? [];
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $period => $price) {
                if ((is_int($period) || ctype_digit((string)$period)) && is_numeric($price)) {
                    $matrix[$local][(int)$period] = (float)$price;
                }
            }
            ksort($matrix[$local], SORT_NUMERIC);
        }
        return $matrix;
    }

    private function looksLikeDomainPricing(array $value): bool
    {
        foreach (array_keys(self::ACTIONS) as $action) {
            if (isset($value[$action]) && is_array($value[$action])) {
                return true;
            }
        }
        return false;
    }

    private function normalizeTld(string $tld): ?string
    {
        $tld = strtolower(trim($tld));
        if ($tld === '') {
            return null;
        }
        if ($tld[0] !== '.') {
            $tld = '.' . $tld;
        }
        return preg_match('/^\.[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $tld) ? $tld : null;
    }

    private function roundCommercial(float $price, float $step, string $mode): float
    {
        $scaled = $price / $step;
        $scaled = match ($mode) {
            'up', 'ceil' => ceil($scaled - 1e-10),
            'down', 'floor' => floor($scaled + 1e-10),
            default => round($scaled, 0, PHP_ROUND_HALF_UP),
        };
        return round($scaled * $step, 4);
    }
}
