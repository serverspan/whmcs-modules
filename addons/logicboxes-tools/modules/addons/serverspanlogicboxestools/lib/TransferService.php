<?php
namespace ServerSpan\LogicBoxesTools;

use WHMCS\Database\Capsule;

final class TransferService
{
    private DomainService $domains;
    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
        $customers = new CustomerService($this->accounts, $this->audit);
        $this->domains = new DomainService($this->accounts, $customers, $this->audit);
    }

    public function checkPending(int $accountId, int $limit = 250): array
    {
        $account = $this->accounts->find($accountId, true) ?? throw new \RuntimeException('Account not found.');
        $options = (array)$account['options'];
        $api = $this->accounts->client($account);
        $rows = Capsule::table('tbldomains as d')
            ->join(Schema::TABLE_DOMAINS . ' as m', 'm.whmcs_domain_id', '=', 'd.id')
            ->where('m.account_id', $accountId)
            ->where('d.registrar', (string)$account['registrar_module'])
            ->where('d.status', 'Pending Transfer')
            ->select(['d.id','d.domain','d.userid','m.logicboxes_order_id'])
            ->orderBy('d.id')->limit(max(1, min(1000, $limit)))->get();
        $stats = ['checked'=>0,'completed'=>0,'stalled'=>0,'failed'=>0];
        foreach ($rows as $row) {
            ++$stats['checked'];
            try {
                $details = $api->domainDetails((int)$row->logicboxes_order_id);
                $remote = $this->domains->normalizeDomain($details);
                $status = strtolower((string)$remote['status']);
                if ($status === 'active') {
                    $params = ['domainid'=>(int)$row->id,'status'=>'Active'];
                    if ($remote['expiry_time'] > 0) $params['expirydate'] = date('Y-m-d', $remote['expiry_time']);
                    $r = localAPI('UpdateClientDomain', $params);
                    if (($r['result'] ?? '') !== 'success') throw new \RuntimeException($r['message'] ?? 'UpdateClientDomain failed.');
                    ++$stats['completed'];
                    $this->audit->write('transfer.completed', $accountId, 'domain', (string)$row->id, ['status'=>'Pending Transfer'], ['status'=>'Active'], ['domain'=>$row->domain], null, 'cron');
                    if (Support::bool($options['transfer_send_confirmation'] ?? true)) {
                        $this->sendDomainMail((int)$row->id, 'Domain transfer completed: ' . $row->domain, '<p>Your domain transfer for <strong>'.Support::e($row->domain).'</strong> has completed successfully.</p>');
                    }
                    continue;
                }
                if (in_array($status, ['deleted','archived'], true) && Support::bool($options['cancel_broken_transfers'] ?? false)) {
                    $r = localAPI('UpdateClientDomain', ['domainid'=>(int)$row->id,'status'=>'Cancelled']);
                    if (($r['result'] ?? '') !== 'success') throw new \RuntimeException($r['message'] ?? 'Could not cancel broken transfer.');
                    ++$stats['failed'];
                    $this->audit->write('transfer.cancelled_missing_upstream', $accountId, 'domain', (string)$row->id, null, ['status'=>'Cancelled'], ['domain'=>$row->domain], null, 'cron');
                    continue;
                }
                ++$stats['stalled'];
                if (Support::bool($options['proactive_transfer_mail'] ?? false) && !$this->mailedRecently($accountId, (int)$row->id)) {
                    $reason = $this->reason($details, $remote);
                    $this->sendDomainMail((int)$row->id, 'Action may be required for domain transfer: ' . $row->domain,
                        '<p>Your transfer for <strong>'.Support::e($row->domain).'</strong> is still pending.</p><p>'.Support::e($reason).'</p>');
                    $this->audit->write('transfer.proactive_mail', $accountId, 'domain', (string)$row->id, null, null, ['domain'=>$row->domain,'reason'=>$reason], null, 'cron');
                }
            } catch (\Throwable $e) {
                ++$stats['failed'];
                $this->audit->write('transfer.check_failed', $accountId, 'domain', (string)$row->id, null, null, ['error'=>$e->getMessage()], null, 'cron');
            }
        }
        return $stats;
    }

    public function sendVerificationReport(int $accountId, int $limit = 500): array
    {
        $account = $this->accounts->find($accountId) ?? throw new \RuntimeException('Account not found.');
        if (!Support::bool($account['options']['raa_daily_report'] ?? false)) return ['count'=>0,'sent'=>false];
        $rows = $this->domains->verificationQueue($accountId, 1, min(500, max(10, $limit)));
        if ($rows === []) return ['count'=>0,'sent'=>false];
        $html = '<p>LogicBoxes domains requiring registrant verification:</p><table border="1" cellpadding="5" cellspacing="0"><tr><th>Domain</th><th>Status</th><th>Verification</th><th>Order ID</th></tr>';
        foreach ($rows as $row) $html .= '<tr><td>'.Support::e($row['domain']).'</td><td>'.Support::e($row['status']).'</td><td>'.Support::e($row['verification_status']).'</td><td>'.(int)$row['order_id'].'</td></tr>';
        $html .= '</table>';
        $r = localAPI('SendAdminEmail', ['customsubject'=>'[ServerSpan LogicBoxes] Pending domain verification - '.$account['name'],'custommessage'=>$html,'type'=>'system']);
        $sent = ($r['result'] ?? '') === 'success';
        $this->audit->write('raa.daily_report', $accountId, 'account', (string)$accountId, null, ['count'=>count($rows),'sent'=>$sent], [], null, 'cron');
        return ['count'=>count($rows),'sent'=>$sent];
    }

    private function reason(array $details, array $remote): string
    {
        $value = Support::firstValue($details, ['actionstatusdesc','actionstatus','transferstatus','statusdesc','message','description'], '');
        if ((string)$value !== '') return 'Registrar status: ' . (string)$value;
        if ($remote['status'] !== '') return 'Registrar status: ' . $remote['status'] . '. Check the administrative contact email, transfer lock, authorization code, and privacy settings.';
        return 'The registrar has not completed the transfer yet. Check the administrative contact email, transfer lock, authorization code, and privacy settings.';
    }

    private function mailedRecently(int $accountId, int $domainId): bool
    {
        return Capsule::table(Schema::TABLE_AUDIT)->where('account_id',$accountId)->where('action','transfer.proactive_mail')->where('entity_key',(string)$domainId)->where('created_at','>=',date('Y-m-d H:i:s',time()-86400))->exists();
    }

    private function sendDomainMail(int $domainId, string $subject, string $message): void
    {
        $r = localAPI('SendEmail', ['id'=>$domainId,'customtype'=>'domain','customsubject'=>$subject,'custommessage'=>$message]);
        if (($r['result'] ?? '') !== 'success') throw new \RuntimeException('WHMCS SendEmail failed: ' . ($r['message'] ?? 'unknown error'));
    }
}
