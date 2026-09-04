<?php
namespace ServerSpan\LogicBoxesTools;

final class Renderer
{
    public function admin(array $d): string
    {
        $view = (string)($d['view'] ?? 'dashboard');
        $link = Support::e($d['modulelink'] ?? '');
        $html = '<style>'.$this->css().'</style><div class="sslb">';
        $html .= '<header><div><h1>ServerSpan LogicBoxes Tools</h1><p>Auditable LogicBoxes/ResellerClub operations for WHMCS.</p></div><b>1.0.0-beta.1</b></header>';
        $html .= '<nav>';
        foreach (['dashboard'=>'Dashboard','accounts'=>'Accounts','customers'=>'Customers','domains'=>'Domains','pricing'=>'Pricing','promos'=>'Promotions','jobs'=>'Jobs & Rollback','audit'=>'Audit'] as $key=>$label) {
            $html .= '<a'.($view===$key?' class="active"':'').' href="'.$link.'&view='.$key.'">'.Support::e($label).'</a>';
        }
        $html .= '</nav>';
        if (!empty($d['flash'])) {
            $html .= '<div class="flash '.Support::e($d['flash']['type']).'">'.Support::e($d['flash']['message']).'</div>';
        }
        $html .= match ($view) {
            'accounts' => $this->accounts($d),
            'customers' => $this->customers($d),
            'domains' => $this->domains($d),
            'pricing' => $this->pricing($d),
            'promos' => $this->promos($d),
            'jobs' => $this->jobs($d),
            'audit' => $this->audit($d),
            default => $this->dashboard($d),
        };
        return $html.'<footer>Open source by <a href="https://www.serverspan.com/en/" target="_blank" rel="noopener">ServerSpan</a>. No licensing callback, telemetry or encoded PHP.</footer></div>';
    }

    private function dashboard(array $d): string
    {
        $c = $d['counts'] ?? [];
        $h = '<div class="stats">';
        foreach (['accounts'=>'Accounts','customers'=>'Mapped customers','domains'=>'Mapped domains','active_promos'=>'Active promos'] as $k=>$label) {
            $h .= '<div><strong>'.(int)($c[$k] ?? 0).'</strong><span>'.Support::e($label).'</span></div>';
        }
        $h .= '</div><section><h2>Safety model</h2><ul><li>Bulk financial writes start as preview jobs.</li><li>Applied items retain before/proposed/applied snapshots for rollback.</li><li>Unattended financial writes require a second explicit authorization flag.</li><li>WHMCS customer passwords are never synchronized upstream.</li><li>LogicBoxes API keys are encrypted using WHMCS.</li></ul></section>';
        $h .= '<section><h2>Recent jobs</h2>'.$this->jobsTable($d['jobs'] ?? [], $d['modulelink'] ?? '').'</section>';
        $h .= '<section><h2>Recent audit</h2>'.$this->auditTable($d['audit'] ?? []).'</section>';
        return $h;
    }

    private function accounts(array $d): string
    {
        $a = $d['account'] ?? null;
        $o = (array)($a['options'] ?? []);
        $policy = (array)($o['scheduled_price_policy'] ?? []);
        $map = '';
        foreach ((array)($o['product_tld_map'] ?? []) as $k=>$v) $map .= $k.'='.$v."\n";
        $h = '<div class="cols"><section><h2>Configured accounts</h2><table><tr><th>Name</th><th>Registrar</th><th>Currency</th><th>Health</th><th></th></tr>';
        foreach ($d['accounts'] ?? [] as $row) {
            $health = $row['last_error'] ? 'Error' : ($row['last_ok_at'] ? 'OK' : 'Not tested');
            $h .= '<tr><td>'.Support::e($row['name']).'</td><td><code>'.Support::e($row['registrar_module']).'</code></td><td>'.Support::e($row['currency']).'</td><td>'.Support::e($health).'</td><td><a class="btn" href="'.Support::e($d['modulelink']).'&view=accounts&account_id='.(int)$row['id'].'">Edit</a></td></tr>';
        }
        $h .= '</table></section><section><h2>'.($a?'Edit account':'Add account').'</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','save_account').$this->hidden('account_id',(int)($a['id'] ?? 0));
        $h .= $this->input('Name','name',$a['name'] ?? '').$this->input('WHMCS registrar module','registrar_module',$a['registrar_module'] ?? 'resellerclub').$this->input('Reseller ID','reseller_id',$a['reseller_id'] ?? '','number').$this->input('API key','api_key','','password','Leave blank to keep the existing key.').$this->input('API base URL','base_url',$a['base_url'] ?? 'https://httpapi.com/api');
        $h .= '<div class="row">'.$this->input('Currency','currency',$a['currency'] ?? 'USD').$this->input('Currency multiplier','multiplier',$a['multiplier'] ?? 1,'number').'</div>';
        $h .= $this->input('Default nameservers','nameservers',implode(', ',(array)($a['nameservers'] ?? []))).$this->input('Funds threshold','fund_threshold',$a['fund_threshold'] ?? '','number').$this->input('OrderBox control panel URL','control_panel_url',$o['control_panel_url'] ?? 'https://manage.resellerclub.com');
        $h .= '<h3>Automation</h3><div class="checks">';
        $flags = ['enabled'=>'Enabled','auto_customer_signup'=>'Auto customer signup','auto_customer_modify'=>'Auto customer modify','auto_customer_delete'=>'Auto customer delete','auto_price_sync'=>'Auto TLD price sync','auto_promo_sync'=>'Refresh promos daily','auto_transfer_sync'=>'Transfer/domain sync daily','auto_recurring_sync'=>'Auto recurring-price sync'];
        foreach ($flags as $k=>$label) $h .= $this->check($k,$label,$a[$k] ?? ($k==='enabled'));
        foreach (['accept_policy_for_auto_signup'=>'Authorize upstream policy acceptance for auto-signup','allow_orderbox_sso'=>'Enable client OrderBox SSO','allow_client_domain_move'=>'Enable client domain ownership moves','transfer_send_confirmation'=>'Email completed transfer confirmation','proactive_transfer_mail'=>'Send stalled-transfer guidance once/24h','cancel_broken_transfers'=>'Cancel WHMCS transfer if upstream order disappeared','raa_daily_report'=>'Daily admin RAA verification report','allow_unattended_financial_writes'=>'ALLOW unattended financial writes (high risk)'] as $k=>$label) {
            $h .= $this->check($k,$label,$o[$k] ?? ($k==='transfer_send_confirmation'));
        }
        $h .= '</div><label>Product key → TLD overrides<textarea name="product_tld_map" rows="5" placeholder="dotfoo=.foo">'.Support::e(trim($map)).'</textarea></label>';
        $h .= '<h3>Scheduled pricing policy</h3><div class="row"><label>Source<select name="scheduled_source">'.$this->options(['customer'=>'LogicBoxes selling price','cost'=>'Cost + margin'],$policy['source'] ?? 'customer').'</select></label><label>Margin type<select name="scheduled_margin_type">'.$this->options(['percent'=>'Percent','fixed'=>'Fixed amount'],$policy['margin_type'] ?? 'percent').'</select></label></div>';
        $h .= '<div class="row">'.$this->input('Margin','scheduled_margin',$policy['margin'] ?? 0,'number').$this->input('Round to','scheduled_round_to',$policy['round_to'] ?? 0.01,'number').'</div><label>Rounding<select name="scheduled_round_mode">'.$this->options(['nearest'=>'Nearest','up'=>'Always up','down'=>'Always down'],$policy['round_mode'] ?? 'nearest').'</select></label><button class="primary">Save account</button></form>';
        if ($a) {
            $h .= '<div class="actions"><form method="post">'.$this->token($d).$this->hidden('ss_action','test_account').$this->hidden('account_id',(int)$a['id']).'<button>Test API</button></form><form method="post">'.$this->token($d).$this->hidden('ss_action','run_account_automation').$this->hidden('account_id',(int)$a['id']).'<button>Run automation now</button></form></div>';
            $h .= '<form method="post" class="danger">'.$this->token($d).$this->hidden('ss_action','delete_account').$this->hidden('account_id',(int)$a['id']).$this->input('Type DELETE','confirm','').'<button>Delete unmapped account</button></form>';
        }
        return $h.'</section></div>';
    }

    private function customers(array $d): string
    {
        $h = '<div class="cols"><section><h2>Export/synchronize WHMCS client</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','export_customer').$this->accountSelect($d).$this->input('WHMCS client ID','client_id','','number').$this->check('accept_policy','Accept upstream policy if a new customer must be created',false).'<button class="primary">Synchronize</button></form></section>';
        $h .= '<section><h2>Import LogicBoxes customer</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','import_customer').$this->accountSelect($d).$this->input('LogicBoxes customer ID','logicboxes_customer_id','','number').$this->input('WHMCS currency ID (optional)','currency_id','','number').'<button class="primary">Import/map</button></form></section></div>';
        $h .= '<section><h2>Mappings</h2><table><tr><th>Account</th><th>WHMCS</th><th>LogicBoxes</th><th>Username</th><th>Origin</th><th>Last sync</th><th>Error</th></tr>';
        foreach ($d['mappings'] ?? [] as $m) $h .= '<tr><td>#'.(int)$m['account_id'].'</td><td>#'.(int)$m['whmcs_client_id'].'</td><td>'.Support::e($m['logicboxes_customer_id']).'</td><td>'.Support::e($m['username']).'</td><td>'.Support::e($m['origin']).'</td><td>'.Support::e($m['last_synced_at']).'</td><td>'.Support::e($m['last_error']).'</td></tr>';
        return $h.'</table></section>';
    }

    private function domains(array $d): string
    {
        $h = '<div class="cols"><section><h2>Import domain order</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','import_domain').$this->accountSelect($d).$this->input('LogicBoxes order ID','order_id','','number').$this->check('recalculate_price','Ask WHMCS to recalculate recurring price',true).'<button class="primary">Import/map</button></form></section>';
        $h .= '<section><h2>Refresh mapped domains</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','refresh_domains').$this->accountSelect($d).$this->input('Limit','limit',250,'number').'<button>Refresh status</button></form><hr><h3>Move domain and associated services</h3><form method="post">'.$this->token($d).$this->hidden('ss_action','move_domain').$this->accountSelect($d).$this->input('Domain','domain','').$this->input('Target WHMCS client ID','target_client_id','','number').$this->input('Type MOVE','confirm','').'<button class="dangerbtn">Move ownership</button></form></section></div>';
        $h .= '<section><h2>Mappings</h2><table><tr><th>Domain</th><th>Account</th><th>WHMCS</th><th>Order</th><th>Customer</th><th>Status</th><th>Verification</th><th>Sync</th></tr>';
        foreach ($d['mappings'] ?? [] as $m) $h .= '<tr><td>'.Support::e($m['domain']).'</td><td>#'.(int)$m['account_id'].'</td><td>#'.(int)$m['whmcs_domain_id'].'</td><td>'.Support::e($m['logicboxes_order_id']).'</td><td>'.Support::e($m['logicboxes_customer_id']).'</td><td>'.Support::e($m['upstream_status']).'</td><td>'.Support::e($m['verification_status']).'</td><td>'.Support::e($m['last_synced_at']).'</td></tr>';
        return $h.'</table></section>';
    }

    private function pricing(array $d): string
    {
        return '<div class="cols"><section><h2>TLD selling-price dry run</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','preview_tld_pricing').$this->accountSelect($d).'<label>Source<select name="source">'.$this->options(['customer'=>'LogicBoxes selling price','cost'=>'Cost + margin'],'customer').'</select></label><div class="row"><label>Margin type<select name="margin_type">'.$this->options(['percent'=>'Percent','fixed'=>'Fixed'],'percent').'</select></label>'.$this->input('Margin','margin',0,'number').'</div><div class="row">'.$this->input('Round to','round_to',0.01,'number').'<label>Rounding<select name="round_mode">'.$this->options(['nearest'=>'Nearest','up'=>'Always up','down'=>'Always down'],'nearest').'</select></label></div><button class="primary">Build dry run</button></form></section><section><h2>Existing-domain recurring prices</h2><p>Uses each client’s effective WHMCS TLD pricing. Zero/free domains are excluded by default.</p><form method="post">'.$this->token($d).$this->hidden('ss_action','preview_recurring').$this->accountSelect($d).$this->input('Max domains','limit',5000,'number').$this->check('include_zero','Include domains currently priced at 0.00',false).'<button class="primary">Build dry run</button></form></section></div><section><p>Inspect and apply every generated job from <a href="'.Support::e($d['modulelink']).'&view=jobs">Jobs & Rollback</a>.</p></section>';
    }

    private function promos(array $d): string
    {
        $h = '<section><h2>Promotions</h2><form method="post">'.$this->token($d).$this->hidden('ss_action','refresh_promos').$this->accountSelect($d).'<button class="primary">Refresh upstream promotions</button></form><p>Ambiguous product keys are never guessed; add explicit product → TLD overrides under Accounts.</p><table><tr><th>TLD</th><th>Product</th><th>Action</th><th>Period</th><th>Customer price</th><th>Window</th><th>Active</th><th></th></tr>';
        foreach ($d['promos'] ?? [] as $p) {
            $h .= '<tr><td>'.Support::e($p['tld'] ?: 'unmapped').'</td><td><code>'.Support::e($p['product_key']).'</code></td><td>'.Support::e($p['action_type']).'</td><td>'.Support::e($p['period']).'</td><td>'.Support::e($p['customer_price']).' '.Support::e($p['currency']).'</td><td>'.Support::e($p['starts_at']).'<br>'.Support::e($p['ends_at']).'</td><td>'.($p['is_active']?'Yes':'No').'</td><td>';
            if ($p['is_active'] && $p['tld']) $h .= '<form method="post">'.$this->token($d).$this->hidden('ss_action','preview_promo').$this->hidden('promo_id',(int)$p['id']).'<button>Preview apply</button></form>';
            $h .= '</td></tr>';
        }
        return $h.'</table></section>';
    }

    private function jobs(array $d): string
    {
        $h = '<section><h2>Jobs</h2>'.$this->jobsTable($d['jobs'] ?? [], $d['modulelink'] ?? '').'</section>';
        $j = $d['selected_job'] ?? null;
        if (!$j) return $h;
        $h .= '<section><h2>Job '.Support::e($j['id']).'</h2><p><b>'.Support::e($j['type']).'</b> · '.Support::e($j['status']).' · '.(int)$j['processed_items'].'/'.(int)$j['total_items'].' processed · '.(int)$j['failed_items'].' failed</p><div class="actions">';
        if (in_array($j['status'], ['queued','running','partial'], true)) {
            if ($j['type']==='tld_price_sync') $h .= $this->jobForm($d,'apply_tld_job',$j['id'],'Apply TLD job','primary');
            if ($j['type']==='tld_price_rollback') $h .= $this->jobForm($d,'apply_tld_rollback',$j['id'],'Apply TLD rollback','dangerbtn');
            if (in_array($j['type'], ['domain_recurring_sync','domain_recurring_rollback'], true)) $h .= $this->jobForm($d,'apply_recurring',$j['id'],'Apply recurring job','primary');
            if ($j['type']==='promo_apply') $h .= $this->jobForm($d,'apply_promo',$j['id'],'Apply promotion','primary');
        }
        if ($j['status']==='completed') {
            if ($j['type']==='tld_price_sync') $h .= $this->jobForm($d,'preview_tld_rollback',$j['id'],'Create rollback preview','');
            if ($j['type']==='domain_recurring_sync') $h .= $this->jobForm($d,'preview_recurring_rollback',$j['id'],'Create recurring rollback','');
        }
        $h .= '</div><table><tr><th>Entity</th><th>Status</th><th>Before</th><th>Proposed</th><th>Error</th></tr>';
        foreach ($d['job_items'] ?? [] as $i) $h .= '<tr><td>'.Support::e($i['entity_type']).' <code>'.Support::e($i['entity_key']).'</code></td><td>'.Support::e($i['status']).'</td><td><pre>'.Support::e($i['before_json']).'</pre></td><td><pre>'.Support::e($i['proposed_json']).'</pre></td><td>'.Support::e($i['error']).'</td></tr>';
        return $h.'</table></section>';
    }

    private function audit(array $d): string { return '<section><h2>Audit trail</h2>'.$this->auditTable($d['audit'] ?? []).'</section>'; }

    private function jobsTable(array $jobs, string $link): string
    {
        $h = '<table><tr><th>Created</th><th>Type</th><th>Status</th><th>Progress</th><th></th></tr>';
        foreach ($jobs as $j) $h .= '<tr><td>'.Support::e($j['created_at']).'</td><td>'.Support::e($j['type']).'</td><td>'.Support::e($j['status']).'</td><td>'.(int)$j['processed_items'].'/'.(int)$j['total_items'].' ('.(int)$j['failed_items'].' failed)</td><td><a class="btn" href="'.Support::e($link).'&view=jobs&job_id='.Support::e($j['id']).'">Inspect</a></td></tr>';
        return $h.'</table>';
    }

    private function auditTable(array $rows): string
    {
        $h = '<table><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Metadata</th></tr>';
        foreach ($rows as $r) $h .= '<tr><td>'.Support::e($r['created_at']).'</td><td>'.Support::e($r['actor']).($r['admin_id']?' #'.(int)$r['admin_id']:'').'</td><td><code>'.Support::e($r['action']).'</code></td><td>'.Support::e($r['entity_type']).' '.Support::e($r['entity_key']).'</td><td><pre>'.Support::e($r['metadata']).'</pre></td></tr>';
        return $h.'</table>';
    }

    private function accountSelect(array $d): string
    {
        $selected = (int)($_GET['account_id'] ?? 0);
        $h = '<label>LogicBoxes account<select name="account_id" required><option value="">Select account</option>';
        foreach ($d['accounts'] ?? [] as $a) $h .= '<option value="'.(int)$a['id'].'" '.((int)$a['id']===$selected?'selected':'').'>'.Support::e($a['name']).' (#'.(int)$a['id'].')</option>';
        return $h.'</select></label>';
    }

    private function input(string $label,string $name,mixed $value,string $type='text',string $help=''): string
    {
        return '<label>'.Support::e($label).'<input type="'.Support::e($type).'" name="'.Support::e($name).'" value="'.Support::e($value).'" '.($type==='number'?'step="any"':'').'>'.($help?'<small>'.Support::e($help).'</small>':'').'</label>';
    }
    private function check(string $name,string $label,mixed $checked): string { return '<label class="check"><input type="checkbox" name="'.Support::e($name).'" value="1" '.(Support::bool($checked)?'checked':'').'> '.Support::e($label).'</label>'; }
    private function options(array $items,mixed $selected): string { $h=''; foreach($items as $v=>$l) $h.='<option value="'.Support::e($v).'" '.((string)$selected===(string)$v?'selected':'').'>'.Support::e($l).'</option>'; return $h; }
    private function token(array $d): string { return $this->hidden('token',$d['token'] ?? ''); }
    private function hidden(string $name,mixed $value): string { return '<input type="hidden" name="'.Support::e($name).'" value="'.Support::e($value).'">'; }
    private function jobForm(array $d,string $action,string $id,string $label,string $class): string { return '<form method="post">'.$this->token($d).$this->hidden('ss_action',$action).$this->hidden('job_id',$id).'<button class="'.Support::e($class).'">'.Support::e($label).'</button></form>'; }
    private function css(): string
    {
        return '.sslb{max-width:1500px}.sslb header{display:flex;justify-content:space-between;align-items:center}.sslb header h1{margin-bottom:2px}.sslb header p,footer{color:#667}.sslb nav{display:flex;gap:5px;flex-wrap:wrap;margin:15px 0}.sslb nav a,.sslb .btn{padding:7px 10px;border:1px solid #ccd4dc;border-radius:5px;background:#fff}.sslb nav a.active{background:#293a4a;color:#fff}.sslb section,.sslb .stats>div{background:#fff;border:1px solid #d9e0e6;border-radius:7px;padding:15px;margin-bottom:15px}.sslb .cols,.sslb .stats,.sslb .row{display:grid;gap:15px}.sslb .cols{grid-template-columns:1fr 1fr}.sslb .stats{grid-template-columns:repeat(4,1fr)}.sslb .row{grid-template-columns:1fr 1fr}.sslb .stats strong{display:block;font-size:28px}.sslb label{display:block;margin:0 0 10px;font-weight:600}.sslb input[type=text],.sslb input[type=password],.sslb input[type=number],.sslb input[type=email],.sslb select,.sslb textarea{display:block;width:100%;padding:7px;border:1px solid #bbc5cf;border-radius:4px;font-weight:400}.sslb .check{font-weight:400}.sslb .check input{width:auto}.sslb .checks{columns:2}.sslb button{padding:7px 11px}.sslb .primary{background:#286090;color:#fff;border:1px solid #204d74}.sslb .dangerbtn,.sslb .danger button{background:#c9302c;color:#fff;border:1px solid #ac2925}.sslb .actions{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.sslb .danger{border-top:1px solid #e5b5b5;padding-top:15px;margin-top:15px}.sslb .flash{padding:10px;margin:10px 0;border-radius:5px}.sslb .flash.success{background:#dff0d8}.sslb .flash.danger{background:#f2dede}.sslb table{width:100%;border-collapse:collapse}.sslb th,.sslb td{text-align:left;vertical-align:top;padding:7px;border-bottom:1px solid #e3e8ed}.sslb pre{white-space:pre-wrap;word-break:break-word;max-width:520px;max-height:220px;overflow:auto;background:#f7f8fa;padding:5px}.sslb footer{font-size:12px;margin:18px 0}@media(max-width:950px){.sslb .cols,.sslb .stats,.sslb .row{grid-template-columns:1fr}.sslb .checks{columns:1}}';
    }
}
