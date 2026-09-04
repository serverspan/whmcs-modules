<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class ClientController
{
    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {}

    public function page(array $vars): array
    {
        $clientId = $this->currentClientId();
        if ($clientId <= 0) {
            return $this->response($vars, '<div class="alert alert-warning">Please sign in to use LogicBoxes domain tools.</div>', true);
        }
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                if (function_exists('check_token')) check_token('WHMCS.default');
                $action = (string)($_POST['ss_action'] ?? '');
                if ($action === 'orderbox_sso') {
                    $this->orderBoxSso($clientId, (int)($_POST['account_id'] ?? 0));
                } elseif ($action === 'client_move_domain') {
                    $message = '<div class="alert alert-success">' . Support::e($this->moveDomain($clientId, $_POST)) . '</div>';
                } else {
                    throw new \InvalidArgumentException('Unknown action.');
                }
            } catch (\Throwable $e) {
                $message = '<div class="alert alert-danger">' . Support::e($e->getMessage()) . '</div>';
            }
        }

        $token = function_exists('generate_token') ? generate_token('plain') : '';
        $rows = Capsule::table(Schema::TABLE_DOMAINS . ' as m')
            ->join('tbldomains as d', 'd.id', '=', 'm.whmcs_domain_id')
            ->join(Schema::TABLE_ACCOUNTS . ' as a', 'a.id', '=', 'm.account_id')
            ->where('d.userid', $clientId)->where('a.enabled', 1)
            ->select(['m.account_id','m.domain','m.upstream_status','m.verification_status','a.name as account_name','a.options'])
            ->orderBy('m.domain')->get();
        $html = '<style>.sslb-client{max-width:1100px}.sslb-client .card{border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:14px}.sslb-client table{width:100%;border-collapse:collapse}.sslb-client th,.sslb-client td{padding:8px;border-bottom:1px solid #eee;text-align:left}.sslb-client form{margin:4px 0}.sslb-client input[type=email]{max-width:360px}</style><div class="sslb-client">' . $message;
        $ssoAccounts = [];
        foreach ($rows as $row) {
            $opts = Support::fromJson($row->options ?? null);
            if (Support::bool($opts['allow_orderbox_sso'] ?? false)) $ssoAccounts[(int)$row->account_id] = (string)$row->account_name;
        }
        if ($ssoAccounts !== []) {
            $html .= '<div class="card"><h3>OrderBox control panel</h3><p>Generate a short-lived sign-in token. Your WHMCS password is never sent to LogicBoxes.</p>';
            foreach ($ssoAccounts as $accountId => $name) {
                $html .= '<form method="post"><input type="hidden" name="token" value="'.Support::e($token).'"><input type="hidden" name="ss_action" value="orderbox_sso"><input type="hidden" name="account_id" value="'.$accountId.'"><button class="btn btn-default">Open '.Support::e($name).' control panel</button></form>';
            }
            $html .= '</div>';
        }
        $html .= '<div class="card"><h3>Mapped domains</h3><table><thead><tr><th>Domain</th><th>Account</th><th>Status</th><th>Verification</th><th>Move ownership</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $opts = Support::fromJson($row->options ?? null);
            $html .= '<tr><td>'.Support::e($row->domain).'</td><td>'.Support::e($row->account_name).'</td><td>'.Support::e($row->upstream_status).'</td><td>'.Support::e($row->verification_status).'</td><td>';
            if (Support::bool($opts['allow_client_domain_move'] ?? false)) {
                $html .= '<form method="post"><input type="hidden" name="token" value="'.Support::e($token).'"><input type="hidden" name="ss_action" value="client_move_domain"><input type="hidden" name="account_id" value="'.(int)$row->account_id.'"><input type="hidden" name="domain" value="'.Support::e($row->domain).'"><input type="email" name="destination_email" placeholder="Destination customer email" required> <label><input type="checkbox" name="confirm" value="yes" required> I understand ownership will change</label> <button class="btn btn-warning btn-sm">Move</button></form>';
            } else $html .= 'Disabled';
            $html .= '</td></tr>';
        }
        if ($rows->count() === 0) $html .= '<tr><td colspan="5">No mapped LogicBoxes domains are associated with this account.</td></tr>';
        $html .= '</tbody></table></div></div>';
        return $this->response($vars, $html, true);
    }

    private function orderBoxSso(int $clientId, int $accountId): never
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('Account not found.');
        if (!Support::bool($account['options']['allow_orderbox_sso'] ?? false)) throw new \RuntimeException('OrderBox SSO is disabled for this account.');
        $map = Capsule::table(Schema::TABLE_CUSTOMERS)->where('account_id', $accountId)->where('whmcs_client_id', $clientId)->first();
        if (!$map) throw new \RuntimeException('Your WHMCS account is not mapped to this LogicBoxes account.');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $token = $this->accounts->client($account)->customerLoginToken((int)$map->logicboxes_customer_id, $ip);
        $base = rtrim((string)($account['options']['control_panel_url'] ?? 'https://manage.resellerclub.com'), '/');
        if (!preg_match('#^https://#i', $base)) throw new \RuntimeException('Unsafe OrderBox control panel URL.');
        $url = $base . '/servlet/AutoLoginServlet?userLoginId=' . rawurlencode($token) . '&role=customer';
        $this->audit->write('client.orderbox_sso', $accountId, 'client', (string)$clientId, null, null, ['ip' => $ip], null, 'client');
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        echo '<meta http-equiv="refresh" content="0;url=' . Support::e($url) . '"><a rel="noreferrer" href="' . Support::e($url) . '">Continue to OrderBox</a>';
        exit;
    }

    private function moveDomain(int $sourceClientId, array $post): string
    {
        if (($post['confirm'] ?? '') !== 'yes') throw new \RuntimeException('Ownership change was not confirmed.');
        $accountId = (int)($post['account_id'] ?? 0);
        $domain = Support::canonicalDomain((string)($post['domain'] ?? ''));
        $destinationEmail = strtolower(trim((string)($post['destination_email'] ?? '')));
        if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Destination email is invalid.');
        $account = $this->accounts->find($accountId) ?? throw new \RuntimeException('Account not found.');
        if (!Support::bool($account['options']['allow_client_domain_move'] ?? false)) throw new \RuntimeException('Client domain moves are disabled.');
        $owned = Capsule::table(Schema::TABLE_DOMAINS . ' as m')->join('tbldomains as d', 'd.id', '=', 'm.whmcs_domain_id')->where('m.account_id', $accountId)->where('m.domain', $domain)->where('d.userid', $sourceClientId)->exists();
        if (!$owned) throw new \RuntimeException('Domain does not belong to the current WHMCS client.');
        $target = Capsule::table('tblclients')->whereRaw('LOWER(email) = ?', [$destinationEmail])->first();
        if (!$target) throw new \RuntimeException('No eligible destination customer exists for that email address.');
        $targetId = (int)$target->id;
        $targetMap = Capsule::table(Schema::TABLE_CUSTOMERS)->where('account_id', $accountId)->where('whmcs_client_id', $targetId)->exists();
        if (!$targetMap) throw new \RuntimeException('The destination customer is not mapped to this registrar account.');
        $customers = new CustomerService($this->accounts, $this->audit);
        $result = (new DomainService($this->accounts, $customers, $this->audit))->moveDomainAndServices($accountId, $domain, $targetId);
        $this->audit->write('client.domain_move', $accountId, 'domain', $domain, ['source_client_id' => $sourceClientId], ['target_client_id' => $targetId], [], null, 'client');
        return 'Domain ' . $result['domain'] . ' and associated services were moved.';
    }

    private function currentClientId(): int
    {
        if (class_exists('WHMCS\\Authentication\\CurrentUser')) {
            try {
                $client = \WHMCS\Authentication\CurrentUser::client();
                if ($client && isset($client->id)) return (int)$client->id;
            } catch (\Throwable) {}
        }
        return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    }

    private function response(array $vars, string $content, bool $login): array
    {
        return [
            'pagetitle' => 'LogicBoxes Domain Tools',
            'breadcrumb' => ['index.php?m=serverspanlogicboxestools' => 'LogicBoxes Domain Tools'],
            'templatefile' => 'client',
            'requirelogin' => $login,
            'forcessl' => true,
            'vars' => ['content' => $content, 'modulelink' => $vars['modulelink'] ?? 'index.php?m=serverspanlogicboxestools'],
        ];
    }
}
