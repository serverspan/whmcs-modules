<?php
/**
 * Super Email Verification Pro (independent recreation)
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/supermailverify/supermailverify.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

define('SEV_VERSION', '1.0.0');
define('SEV_PER_PAGE', 20);

function supermailverify_config()
{
    return [
        'name'        => 'Super Email Verification Pro',
        'description' => 'Email verification with anti-spam registration control, disposable domain blocking, '
            . 'email/IP ban lists, statistics and outbound mail tools.',
        'author'      => 'ServerSpan',
        'language'    => 'english',
        'version'     => SEV_VERSION,
        'fields'      => [
            'verification_type' => [
                'FriendlyName' => 'Verification Type', 'Type' => 'dropdown',
                'Options' => 'static,after,modal', 'Default' => 'static',
                'Description' => 'static = forced verification page, after = verify after registration '
                    . '(banner + checkout block), modal = popup verification',
            ],
            'code_length' => [
                'FriendlyName' => 'Code Length', 'Type' => 'text', 'Size' => '5', 'Default' => '6',
            ],
            'code_charset' => [
                'FriendlyName' => 'Code Charset', 'Type' => 'dropdown',
                'Options' => 'numeric,alpha_upper,alnum_upper', 'Default' => 'numeric',
                'Description' => 'Numbers only, capital letters only, or capitals + numbers',
            ],
            'require_verify_tickets' => [
                'FriendlyName' => 'Require Verification for Tickets', 'Type' => 'yesno',
                'Description' => 'Unverified users cannot open support tickets',
            ],
            'require_verify_contact' => [
                'FriendlyName' => 'Require Verification for Contact Form', 'Type' => 'yesno',
                'Description' => 'Unverified visitors cannot use the contact form',
            ],
            'captcha_provider' => [
                'FriendlyName' => 'Captcha Provider', 'Type' => 'dropdown',
                'Options' => 'none,recaptcha_v3,turnstile', 'Default' => 'none',
            ],
            'recaptcha_site_key'   => ['FriendlyName' => 'reCAPTCHA v3 Site Key', 'Type' => 'text', 'Size' => '60'],
            'recaptcha_secret_key' => ['FriendlyName' => 'reCAPTCHA v3 Secret Key', 'Type' => 'password', 'Size' => '60'],
            'turnstile_site_key'   => ['FriendlyName' => 'Turnstile Site Key', 'Type' => 'text', 'Size' => '60'],
            'turnstile_secret_key' => ['FriendlyName' => 'Turnstile Secret Key', 'Type' => 'password', 'Size' => '60'],
            'ban_email_attempts' => [
                'FriendlyName' => 'Ban Email After X Invalid Codes', 'Type' => 'text', 'Size' => '5',
                'Default' => '5', 'Description' => '0 disables. Email bans from this rule are permanent.',
            ],
            'ban_ip_sends' => [
                'FriendlyName' => 'Ban IP After X Code Emails / 24h', 'Type' => 'text', 'Size' => '5',
                'Default' => '10', 'Description' => '0 disables.',
            ],
            'ban_days' => [
                'FriendlyName' => 'Temporary Ban Days', 'Type' => 'text', 'Size' => '5', 'Default' => '7',
            ],
            'reminder_days' => [
                'FriendlyName' => 'Reminder: Resend After X Days', 'Type' => 'text', 'Size' => '5',
                'Default' => '0', 'Description' => '0 disables. Runs on the daily cron.',
            ],
            'deactivate_days' => [
                'FriendlyName' => 'Auto Deactivate After X Days', 'Type' => 'text', 'Size' => '5', 'Default' => '0',
            ],
            'terminate_days' => [
                'FriendlyName' => 'Auto Close Account After X Days', 'Type' => 'text', 'Size' => '5', 'Default' => '0',
            ],
            'delete_days' => [
                'FriendlyName' => 'Auto Delete Account After X Days', 'Type' => 'text', 'Size' => '5', 'Default' => '0',
                'Description' => 'Only deletes unverified accounts with no services and no unpaid invoices.',
            ],
            'email_subject' => [
                'FriendlyName' => 'Verification Email Subject', 'Type' => 'text', 'Size' => '80',
                'Default' => 'Confirm your email address',
            ],
            'email_template' => [
                'FriendlyName' => 'Verification Email Template (HTML)', 'Type' => 'textarea', 'Rows' => '8',
                'Cols' => '80', 'Description' => 'Mergevars: {name} {code} {companyname} {signature}',
                'Default' => '<p>Hi {name},</p><p>Your verification code is:</p>'
                    . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
                    . '<p>If you did not request this code you can ignore this email.</p><p>{signature}</p>',
            ],
            'mail_provider' => [
                'FriendlyName' => 'Mail Provider', 'Type' => 'dropdown',
                'Options' => 'whmcs,postmark,mailgun,sendgrid,sparkpost', 'Default' => 'whmcs',
                'Description' => 'whmcs uses the SMTP settings from Setup > General Settings > Mail.',
            ],
            'from_name'  => ['FriendlyName' => 'From Name Override', 'Type' => 'text', 'Size' => '40'],
            'from_email' => ['FriendlyName' => 'From Email Override', 'Type' => 'text', 'Size' => '40'],
            'postmark_token'    => ['FriendlyName' => 'Postmark Server Token', 'Type' => 'password', 'Size' => '60'],
            'mailgun_api_key'   => ['FriendlyName' => 'Mailgun API Key', 'Type' => 'password', 'Size' => '60'],
            'mailgun_domain'    => ['FriendlyName' => 'Mailgun Domain', 'Type' => 'text', 'Size' => '60'],
            'sendgrid_api_key'  => ['FriendlyName' => 'SendGrid API Key', 'Type' => 'password', 'Size' => '60'],
            'sparkpost_api_key' => ['FriendlyName' => 'SparkPost API Key', 'Type' => 'password', 'Size' => '60'],
        ],
    ];
}

function supermailverify_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_sev_emails')) {
            Capsule::schema()->create('mod_sev_emails', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('userid')->default(0)->index();
                $table->string('email', 190)->index();
                $table->string('email_normalized', 190)->index();
                $table->string('code', 64);
                $table->boolean('verified')->default(false)->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->unsignedInteger('sends')->default(0);
                $table->boolean('reminder_sent')->default(false);
                $table->string('ip', 45)->default('');
                $table->dateTime('last_sent_at')->nullable();
                $table->dateTime('verified_at')->nullable()->index();
                $table->dateTime('created_at')->index();
            });
        }
        if (!Capsule::schema()->hasTable('mod_sev_email_bans')) {
            Capsule::schema()->create('mod_sev_email_bans', function ($table) {
                $table->increments('id');
                $table->string('email', 190)->unique();
                $table->enum('ban_type', ['temporary', 'forever'])->default('forever');
                $table->string('reason', 190)->default('');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('created_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_sev_ip_bans')) {
            Capsule::schema()->create('mod_sev_ip_bans', function ($table) {
                $table->increments('id');
                $table->string('ip', 45)->unique();
                $table->enum('ban_type', ['temporary', 'forever'])->default('forever');
                $table->string('reason', 190)->default('');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('created_at');
            });
        }
        if (!Capsule::schema()->hasTable('mod_sev_domains')) {
            Capsule::schema()->create('mod_sev_domains', function ($table) {
                $table->increments('id');
                $table->string('domain', 190)->unique();
                $table->dateTime('created_at');
            });
        }
        return ['status' => 'success', 'description' => 'Tables created. Configure the module below, then use '
            . 'Restrictions > Update Blacklist to import the disposable-domain list.'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function supermailverify_deactivate()
{
    // Data is intentionally preserved so re-activation does not lose ban
    // lists or verification history. Drop mod_sev_* tables manually to reset.
    return ['status' => 'success', 'description' => 'Module deactivated. All mod_sev_* tables were preserved.'];
}

/* ============================================================ admin area */

function supermailverify_output($vars)
{
    $modulelink = $vars['modulelink'];
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('check_token') && !check_token('WHMCS.admin.default')) {
            $err = 'Invalid CSRF token.';
        } else {
            list($msg, $err) = sev_admin_handle_post();
        }
    }

    $tab = isset($_GET['sev_tab']) ? preg_replace('/[^a-z]/', '', $_GET['sev_tab']) : 'maillist';
    $tabs = [
        'maillist'     => 'Mailing List',
        'emailbans'    => 'Email Bans',
        'ipbans'       => 'Banned IPs',
        'restrictions' => 'Restrictions',
        'stats'        => 'Statistics',
        'tools'        => 'Tools',
    ];

    echo '<h2>Super Email Verification Pro <small>v' . SEV_VERSION . '</small></h2>';
    if ($msg) {
        echo '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($err) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
    }
    echo '<ul class="nav nav-tabs" style="margin-bottom:20px">';
    foreach ($tabs as $key => $label) {
        $active = $tab === $key ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&sev_tab=' . $key . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    switch ($tab) {
        case 'emailbans':
            sev_admin_email_bans($modulelink);
            break;
        case 'ipbans':
            sev_admin_ip_bans($modulelink);
            break;
        case 'restrictions':
            sev_admin_restrictions($modulelink);
            break;
        case 'stats':
            sev_admin_stats($modulelink);
            break;
        case 'tools':
            sev_admin_tools($modulelink);
            break;
        default:
            sev_admin_maillist($modulelink);
    }

    echo '<p class="text-muted" style="margin-top:20px">Developed by <a href="https://www.serverspan.com/en/tools/index" target="_blank">ServerSpan</a>.</p>';
}

function sev_admin_handle_post()
{
    $do = isset($_POST['sev_do']) ? $_POST['sev_do'] : '';
    $now = date('Y-m-d H:i:s');
    try {
        switch ($do) {
            case 'resend':
                $row = Capsule::table('mod_sev_emails')->where('id', (int) $_POST['id'])->first();
                if (!$row) {
                    return ['', 'Record not found.'];
                }
                list($ok, $info) = sev_send_verification($row->userid, $row->email);
                return $ok ? ['Verification code resent to ' . $row->email, ''] : ['', $info];

            case 'verify':
                Capsule::table('mod_sev_emails')->where('id', (int) $_POST['id'])
                    ->update(['verified' => 1, 'verified_at' => $now]);
                $row = Capsule::table('mod_sev_emails')->where('id', (int) $_POST['id'])->first();
                if ($row) {
                    sev_mark_verified($row->userid, $row->email);
                }
                return ['Email marked as verified.', ''];

            case 'unverify':
                Capsule::table('mod_sev_emails')->where('id', (int) $_POST['id'])
                    ->update(['verified' => 0, 'verified_at' => null]);
                return ['Email marked as unverified.', ''];

            case 'delete_record':
                Capsule::table('mod_sev_emails')->where('id', (int) $_POST['id'])->delete();
                return ['Record deleted.', ''];

            case 'ban_email':
                sev_ban_email($_POST['email'], $_POST['ban_type'] === 'temporary' ? 'temporary' : 'forever',
                    trim((string) $_POST['reason']));
                return ['Email banned.', ''];

            case 'unban_email':
                Capsule::table('mod_sev_email_bans')->where('id', (int) $_POST['id'])->delete();
                return ['Email ban removed.', ''];

            case 'ban_ip':
                sev_ban_ip(trim((string) $_POST['ip']), $_POST['ban_type'] === 'temporary' ? 'temporary' : 'forever',
                    trim((string) $_POST['reason']));
                return ['IP banned.', ''];

            case 'unban_ip':
                Capsule::table('mod_sev_ip_bans')->where('id', (int) $_POST['id'])->delete();
                return ['IP ban removed.', ''];

            case 'add_domain':
                $domain = strtolower(trim((string) $_POST['domain']));
                if ($domain && !Capsule::table('mod_sev_domains')->where('domain', $domain)->exists()) {
                    Capsule::table('mod_sev_domains')->insert(['domain' => $domain, 'created_at' => $now]);
                    return ['Domain "' . $domain . '" added to the blacklist.', ''];
                }
                return ['', 'Domain empty or already listed.'];

            case 'del_domain':
                Capsule::table('mod_sev_domains')->where('id', (int) $_POST['id'])->delete();
                return ['Domain removed.', ''];

            case 'update_blacklist':
                return sev_admin_update_blacklist();

            case 'send_custom_email':
                return sev_admin_send_custom_email();
        }
    } catch (\Exception $e) {
        return ['', $e->getMessage()];
    }
    return ['', ''];
}

function sev_admin_update_blacklist()
{
    $url = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'supermailverify/' . SEV_VERSION,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!$body || $code !== 200) {
        return ['', 'Could not download the disposable-domain list (HTTP ' . $code . ').'];
    }
    $before = Capsule::table('mod_sev_domains')->count();
    $now = date('Y-m-d H:i:s');
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $domain = strtolower(trim($line));
        if ($domain && strpos($domain, '#') !== 0) {
            Capsule::table('mod_sev_domains')->updateOrInsert(
                ['domain' => $domain],
                ['created_at' => $now]
            );
        }
    }
    $added = Capsule::table('mod_sev_domains')->count() - $before;
    return ['Blacklist updated. ' . $added . ' new domains imported.', ''];
}

function sev_admin_send_custom_email()
{
    $toRaw   = trim((string) $_POST['to']);
    $ccRaw   = trim((string) $_POST['cc']);
    $subject = trim((string) $_POST['subject']);
    $body    = trim((string) $_POST['message']);
    if (!$toRaw || !$subject || !$body) {
        return ['', 'To, subject and message are required.'];
    }
    $to = array_filter(array_map('trim', explode(',', $toRaw)), 'filter_var', FILTER_VALIDATE_EMAIL);
    $cc = array_filter(array_map('trim', explode(',', $ccRaw)), 'filter_var', FILTER_VALIDATE_EMAIL);
    if (!$to) {
        return ['', 'No valid recipient addresses.'];
    }
    if (!empty($_POST['embed_signature'])) {
        $sig = (string) Capsule::table('tblconfiguration')->where('setting', 'Signature')->value('value');
        if ($sig) {
            $body .= '<br><br>' . nl2br($sig);
        }
    }
    $body = nl2br($body);

    $attachments = [];
    if (!empty($_FILES['attachments']['name'][0])) {
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp = sys_get_temp_dir() . '/sev_' . uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $tmp)) {
                    $attachments[] = ['path' => $tmp, 'name' => $name];
                }
            }
        }
    }

    $failed = [];
    foreach ($to as $addr) {
        list($ok, $error) = sev_mailer_send($addr, $subject, $body, $cc, $attachments);
        if (!$ok) {
            $failed[] = $addr . ' (' . $error . ')';
        }
    }
    foreach ($attachments as $att) {
        @unlink($att['path']);
    }
    if ($failed) {
        return ['', 'Failed for: ' . implode('; ', $failed)];
    }
    return ['Email sent to ' . count($to) . ' recipient(s)' . ($cc ? ' with ' . count($cc) . ' CC' : '') . '.', ''];
}

/* ------------------------------------------------------ admin rendering */

function sev_token_field()
{
    return function_exists('generate_token') ? generate_token() : '';
}

function sev_pager($modulelink, $tab, $total, $page, $extra = '')
{
    $pages = max(1, (int) ceil($total / SEV_PER_PAGE));
    if ($pages <= 1) {
        return;
    }
    echo '<ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        $active = $p === $page ? ' class="active"' : '';
        echo '<li' . $active . '><a href="' . $modulelink . '&sev_tab=' . $tab . '&page=' . $p . $extra . '">'
            . $p . '</a></li>';
    }
    echo '</ul>';
}

function sev_row_actions($modulelink, $buttons)
{
    // $buttons: array of [do, id, label, btn-class]
    echo '<div style="white-space:nowrap">';
    foreach ($buttons as $b) {
        echo '<form method="post" action="' . $modulelink . '" style="display:inline">';
        echo sev_token_field();
        echo '<input type="hidden" name="sev_do" value="' . $b[0] . '">';
        echo '<input type="hidden" name="id" value="' . (int) $b[1] . '">';
        echo '<button class="btn btn-xs ' . $b[3] . '">' . $b[2] . '</button>';
        echo '</form> ';
    }
    echo '</div>';
}

function sev_admin_maillist($modulelink)
{
    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $page   = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

    $query = Capsule::table('mod_sev_emails')->orderBy('id', 'desc');
    if ($search !== '') {
        $query->where('email', 'like', '%' . $search . '%');
    }
    $total = $query->count();
    $rows  = $query->offset(($page - 1) * SEV_PER_PAGE)->limit(SEV_PER_PAGE)->get();

    echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
    echo '<input type="hidden" name="m" value="supermailverify">';
    echo '<input type="hidden" name="sev_tab" value="maillist">';
    echo '<input type="text" name="search" class="form-control" placeholder="Search by email" value="'
        . htmlspecialchars($search) . '"> ';
    echo '<button class="btn btn-default">Search</button></form>';

    echo '<table class="table table-striped table-hover"><thead><tr>'
        . '<th>Email</th><th>User</th><th>Status</th><th>Sends</th><th>Attempts</th>'
        . '<th>Last Sent</th><th>Verified At</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $status = $row->verified
            ? '<span class="label label-success">Verified</span>'
            : '<span class="label label-warning">Unverified</span>';
        $user = $row->userid
            ? '<a href="clientssummary.php?userid=' . (int) $row->userid . '">#' . (int) $row->userid . '</a>'
            : '-';
        $buttons = [];
        if (!$row->verified) {
            $buttons[] = ['resend', $row->id, 'Resend Code', 'btn-default'];
            $buttons[] = ['verify', $row->id, 'Verify', 'btn-success'];
        } else {
            $buttons[] = ['unverify', $row->id, 'Unverify', 'btn-warning'];
        }
        $buttons[] = ['delete_record', $row->id, 'Delete', 'btn-danger'];
        echo '<tr><td>' . htmlspecialchars($row->email) . '</td><td>' . $user . '</td><td>' . $status . '</td>'
            . '<td>' . (int) $row->sends . '</td><td>' . (int) $row->attempts . '</td>'
            . '<td>' . htmlspecialchars((string) $row->last_sent_at) . '</td>'
            . '<td>' . htmlspecialchars((string) $row->verified_at) . '</td><td>';
        sev_row_actions($modulelink, $buttons);
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="8" class="text-center text-muted">No records.</td></tr>';
    }
    echo '</tbody></table>';
    sev_pager($modulelink, 'maillist', $total, $page, $search !== '' ? '&search=' . urlencode($search) : '');
}

function sev_admin_ban_table($modulelink, $type)
{
    $isEmail  = $type === 'email';
    $table    = $isEmail ? 'mod_sev_email_bans' : 'mod_sev_ip_bans';
    $tab      = $isEmail ? 'emailbans' : 'ipbans';
    $field    = $isEmail ? 'email' : 'ip';
    $label    = $isEmail ? 'Email' : 'IP address';
    $search   = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $page     = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));

    $query = Capsule::table($table)->orderBy('id', 'desc');
    if ($search !== '') {
        $query->where($field, 'like', '%' . $search . '%');
    }
    $total = $query->count();
    $rows  = $query->offset(($page - 1) * SEV_PER_PAGE)->limit(SEV_PER_PAGE)->get();

    echo '<div class="row"><div class="col-md-6">';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline well">';
    echo sev_token_field();
    echo '<input type="hidden" name="sev_do" value="ban_' . $type . '">';
    echo '<input type="text" name="' . $field . '" class="form-control" placeholder="' . $label . '" required> ';
    echo '<select name="ban_type" class="form-control"><option value="temporary">Temporary</option>'
        . '<option value="forever">Forever</option></select> ';
    echo '<input type="text" name="reason" class="form-control" placeholder="Reason (optional)"> ';
    echo '<button class="btn btn-danger">Ban</button></form></div>';

    echo '<div class="col-md-6"><form method="get" class="form-inline pull-right">';
    echo '<input type="hidden" name="m" value="supermailverify">';
    echo '<input type="hidden" name="sev_tab" value="' . $tab . '">';
    echo '<input type="text" name="search" class="form-control" placeholder="Search" value="'
        . htmlspecialchars($search) . '"> ';
    echo '<button class="btn btn-default">Search</button></form></div></div>';

    $now = date('Y-m-d H:i:s');
    echo '<table class="table table-striped"><thead><tr><th>' . $label . '</th><th>Type</th>'
        . '<th>Reason</th><th>Expires</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $expired = $row->ban_type === 'temporary' && $row->expires_at && $row->expires_at <= $now;
        $typeLabel = $row->ban_type === 'forever'
            ? '<span class="label label-danger">Forever</span>'
            : '<span class="label label-warning">Temporary</span>';
        if ($expired) {
            $typeLabel .= ' <span class="label label-default">Expired</span>';
        }
        echo '<tr><td>' . htmlspecialchars($row->{$field}) . '</td><td>' . $typeLabel . '</td>'
            . '<td>' . htmlspecialchars($row->reason) . '</td>'
            . '<td>' . ($row->expires_at ? htmlspecialchars($row->expires_at) : '-') . '</td>'
            . '<td>' . htmlspecialchars($row->created_at) . '</td><td>';
        sev_row_actions($modulelink, [['unban_' . $type, $row->id, 'Remove', 'btn-success']]);
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="6" class="text-center text-muted">No bans.</td></tr>';
    }
    echo '</tbody></table>';
    sev_pager($modulelink, $tab, $total, $page, $search !== '' ? '&search=' . urlencode($search) : '');
}

function sev_admin_email_bans($modulelink)
{
    sev_admin_ban_table($modulelink, 'email');
}

function sev_admin_ip_bans($modulelink)
{
    sev_admin_ban_table($modulelink, 'ip');
}

function sev_admin_restrictions($modulelink)
{
    $page  = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
    $total = Capsule::table('mod_sev_domains')->count();
    $rows  = Capsule::table('mod_sev_domains')->orderBy('domain')
        ->offset(($page - 1) * SEV_PER_PAGE)->limit(SEV_PER_PAGE)->get();

    echo '<div class="row"><div class="col-md-6">';
    echo '<form method="post" action="' . $modulelink . '" class="form-inline well">';
    echo sev_token_field();
    echo '<input type="hidden" name="sev_do" value="add_domain">';
    echo '<input type="text" name="domain" class="form-control" placeholder="spam1.com" required> ';
    echo '<button class="btn btn-primary">Add Domain</button></form></div>';

    echo '<div class="col-md-6"><form method="post" action="' . $modulelink . '" class="form-inline pull-right well">';
    echo sev_token_field();
    echo '<input type="hidden" name="sev_do" value="update_blacklist">';
    echo '<button class="btn btn-default">Update Blacklist from Public List</button></form></div></div>';

    echo '<p class="text-muted">Every address at a listed domain (user1@spam1.com, user2@spam1.com, ...) '
        . 'is rejected at registration, ticket opening and the contact form.</p>';

    echo '<table class="table table-striped"><thead><tr><th>Domain</th><th>Added</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . htmlspecialchars($row->domain) . '</td><td>' . htmlspecialchars($row->created_at) . '</td><td>';
        sev_row_actions($modulelink, [['del_domain', $row->id, 'Delete', 'btn-danger']]);
        echo '</td></tr>';
    }
    if (!$total) {
        echo '<tr><td colspan="3" class="text-center text-muted">No domains listed. Use "Update Blacklist" to import the public disposable-domain list.</td></tr>';
    }
    echo '</tbody></table>';
    sev_pager($modulelink, 'restrictions', $total, $page);
}

function sev_admin_stats($modulelink)
{
    $range = isset($_GET['range']) ? $_GET['range'] : 'month';
    if (!in_array($range, ['today', 'month', 'year'], true)) {
        $range = 'month';
    }
    echo '<div class="btn-group" style="margin-bottom:15px">';
    foreach (['today' => 'Today', 'month' => 'Last 30 Days', 'year' => 'Last 12 Months'] as $r => $label) {
        $cls = $range === $r ? 'btn-primary' : 'btn-default';
        echo '<a class="btn ' . $cls . '" href="' . $modulelink . '&sev_tab=stats&range=' . $r . '">' . $label . '</a>';
    }
    echo '</div>';

    $labels = [];
    $verified = [];
    $unverified = [];

    if ($range === 'year') {
        $buckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("first day of -{$i} months"));
            $labels[] = date('M Y', strtotime($key . '-01'));
            $buckets[$key] = ['v' => 0, 'u' => 0];
        }
        $vrows = Capsule::table('mod_sev_emails')
            ->selectRaw('DATE_FORMAT(verified_at, "%Y-%m") as bucket, COUNT(*) as c')
            ->where('verified', 1)->where('verified_at', '>=', date('Y-m-01', strtotime('-11 months')))
            ->groupBy('bucket')->pluck('c', 'bucket');
        $urows = Capsule::table('mod_sev_emails')
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as bucket, COUNT(*) as c')
            ->where('verified', 0)->where('created_at', '>=', date('Y-m-01', strtotime('-11 months')))
            ->groupBy('bucket')->pluck('c', 'bucket');
        foreach ($buckets as $key => $v) {
            $verified[]   = isset($vrows[$key]) ? (int) $vrows[$key] : 0;
            $unverified[] = isset($urows[$key]) ? (int) $urows[$key] : 0;
        }
    } else {
        $days = $range === 'today' ? 1 : 30;
        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = $range === 'today' ? 'Today' : date('M j', strtotime($key));
            $buckets[$key] = true;
        }
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $vrows = Capsule::table('mod_sev_emails')
            ->selectRaw('DATE(verified_at) as bucket, COUNT(*) as c')
            ->where('verified', 1)->where('verified_at', '>=', $start)
            ->groupBy('bucket')->pluck('c', 'bucket');
        $urows = Capsule::table('mod_sev_emails')
            ->selectRaw('DATE(created_at) as bucket, COUNT(*) as c')
            ->where('verified', 0)->where('created_at', '>=', $start)
            ->groupBy('bucket')->pluck('c', 'bucket');
        foreach (array_keys($buckets) as $key) {
            $verified[]   = isset($vrows[$key]) ? (int) $vrows[$key] : 0;
            $unverified[] = isset($urows[$key]) ? (int) $urows[$key] : 0;
        }
    }

    $totalVerified   = Capsule::table('mod_sev_emails')->where('verified', 1)->count();
    $totalUnverified = Capsule::table('mod_sev_emails')->where('verified', 0)->count();

    echo '<div class="row"><div class="col-md-8"><div class="panel panel-default"><div class="panel-heading">'
        . '<strong>Verified vs Unverified Over Time</strong></div><div class="panel-body">'
        . '<canvas id="sevTimeChart" height="120"></canvas></div></div></div>';
    echo '<div class="col-md-4"><div class="panel panel-default"><div class="panel-heading">'
        . '<strong>Total (all time)</strong></div><div class="panel-body">'
        . '<canvas id="sevTotalChart" height="120"></canvas></div></div></div></div>';

    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
    echo '<script>
(function(){
  var labels = ' . json_encode($labels) . ';
  new Chart(document.getElementById("sevTimeChart"), {
    type: "line",
    data: { labels: labels, datasets: [
      { label: "Verified", data: ' . json_encode($verified) . ', borderColor: "#5cb85c", backgroundColor: "rgba(92,184,92,0.15)", fill: true, tension: 0.3 },
      { label: "Unverified", data: ' . json_encode($unverified) . ', borderColor: "#d9534f", backgroundColor: "rgba(217,83,79,0.15)", fill: true, tension: 0.3 }
    ]},
    options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
  new Chart(document.getElementById("sevTotalChart"), {
    type: "doughnut",
    data: { labels: ["Verified (' . (int) $totalVerified . ')", "Unverified (' . (int) $totalUnverified . ')"],
      datasets: [{ data: [' . (int) $totalVerified . ', ' . (int) $totalUnverified . '],
        backgroundColor: ["#5cb85c", "#d9534f"] }] }
  });
})();
</script>';
}

function sev_admin_tools($modulelink)
{
    echo '<div class="panel panel-default"><div class="panel-heading"><strong>Send Email to Non-Clients</strong>'
        . '</div><div class="panel-body">';
    echo '<form method="post" action="' . $modulelink . '" enctype="multipart/form-data">';
    echo sev_token_field();
    echo '<input type="hidden" name="sev_do" value="send_custom_email">';
    echo '<div class="form-group"><label>To (comma separated)</label>'
        . '<input type="text" name="to" class="form-control" required></div>';
    echo '<div class="form-group"><label>CC (comma separated, optional)</label>'
        . '<input type="text" name="cc" class="form-control"></div>';
    echo '<div class="form-group"><label>Subject</label>'
        . '<input type="text" name="subject" class="form-control" required></div>';
    echo '<div class="form-group"><label>Message</label>'
        . '<textarea name="message" class="form-control" rows="8" required></textarea></div>';
    echo '<div class="form-group"><label>Attachments</label>'
        . '<input type="file" name="attachments[]" multiple></div>';
    echo '<div class="checkbox"><label><input type="checkbox" name="embed_signature" value="1" checked> '
        . 'Embed the WHMCS general signature automatically</label></div>';
    echo '<button class="btn btn-primary">Send Email</button></form>';
    echo '<p class="text-muted" style="margin-top:10px">Sent through the provider selected in the module '
        . 'configuration (currently: ' . htmlspecialchars(sev_setting('mail_provider', 'whmcs')) . ').</p>';
    echo '</div></div>';
}

/* ========================================================== client area */

function supermailverify_clientarea($vars)
{
    $LANG = isset($vars['_lang']) ? $vars['_lang'] : [];
    $error   = '';
    $success = '';

    $email = '';
    if (!empty($vars['clientsdetails']['email'])) {
        $email = strtolower(trim($vars['clientsdetails']['email']));
    } elseif (!empty($_POST['email'])) {
        $email = strtolower(trim((string) $_POST['email']));
    } elseif (!empty($_GET['email'])) {
        $email = strtolower(trim((string) $_GET['email']));
    }

    // Already verified? Nothing to do.
    $record = $email ? Capsule::table('mod_sev_emails')->where('email', $email)->first() : null;
    if ($record && $record->verified) {
        $success = $LANG['already_verified'] ?? 'Your email address is already verified.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!sev_check_captcha()) {
            $error = $LANG['captcha_failed'] ?? 'Captcha verification failed. Please try again.';
        } elseif (sev_is_email_banned($email)) {
            $error = $LANG['email_banned'] ?? 'This email address is banned.';
        } elseif (sev_is_ip_banned(sev_client_ip())) {
            $error = $LANG['ip_banned'] ?? 'Your IP address is banned.';
        } else {
            $do = isset($_POST['sev_do']) ? $_POST['sev_do'] : 'verify';
            if ($do === 'resend') {
                $userid = $record ? $record->userid : (int) ($vars['clientsdetails']['userid'] ?? 0);
                list($ok, $info) = sev_send_verification($userid, $email);
                if ($ok) {
                    $success = $LANG['code_resent'] ?? 'A new verification code has been sent to your email.';
                } else {
                    $error = $info;
                }
            } else {
                $code = isset($_POST['code']) ? (string) $_POST['code'] : '';
                $result = sev_verify_code($email, $code);
                switch ($result) {
                    case 'ok':
                    case 'already':
                        $success = $LANG['verify_success'] ?? 'Email verified successfully. You can continue.';
                        break;
                    case 'banned':
                        $error = $LANG['too_many_attempts'] ?? 'Too many invalid codes. This email has been banned.';
                        break;
                    case 'bad':
                        $error = $LANG['invalid_code'] ?? 'Invalid verification code.';
                        break;
                    default:
                        $error = $LANG['not_found'] ?? 'No verification request found for this email. Use resend first.';
                }
            }
        }
        $record = $email ? Capsule::table('mod_sev_emails')->where('email', $email)->first() : null;
    }

    $captchaProvider = sev_setting('captcha_provider', 'none');
    return [
        'pagetitle'    => $LANG['page_title'] ?? 'Email Verification',
        'breadcrumb'   => ['index.php?m=supermailverify' => $LANG['page_title'] ?? 'Email Verification'],
        'templatefile' => 'verify',
        'requirelogin' => false,
        'vars'         => [
            'email'           => $email,
            'emailLocked'     => !empty($vars['clientsdetails']['email']),
            'isVerified'      => $record && $record->verified,
            'error'           => $error,
            'success'         => $success,
            'captchaProvider' => $captchaProvider,
            'turnstileSiteKey'  => $captchaProvider === 'turnstile' ? sev_setting('turnstile_site_key') : '',
            'recaptchaSiteKey'  => $captchaProvider === 'recaptcha_v3' ? sev_setting('recaptcha_site_key') : '',
        ],
    ];
}
