<?php
/**
 * Super Email Verification Pro - shared library
 * Location: modules/addons/supermailverify/lib/Functions.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/* ---------------------------------------------------------------- settings */

function sev_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'supermailverify')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function sev_setting($key, $default = '')
{
    $s = sev_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

/* ------------------------------------------------------------------ codes */

function sev_generate_code()
{
    $length = (int) sev_setting('code_length', 6);
    if ($length < 4) {
        $length = 4;
    }
    if ($length > 32) {
        $length = 32;
    }
    $sets = [
        'numeric'     => '0123456789',
        'alpha_upper' => 'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'alnum_upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
    ];
    $mode  = sev_setting('code_charset', 'numeric');
    $chars = isset($sets[$mode]) ? $sets[$mode] : $sets['numeric'];
    $max   = strlen($chars) - 1;
    $code  = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

/**
 * Normalise an address for duplicate detection. Strips +tags on every
 * domain and additionally strips dots on Gmail, so
 * account.1+spam@gmail.com == account1@gmail.com.
 */
function sev_normalize_email($email)
{
    $email = strtolower(trim($email));
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }
    list($local, $domain) = $parts;
    $plus = strpos($local, '+');
    if ($plus !== false) {
        $local = substr($local, 0, $plus);
    }
    if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
        $local  = str_replace('.', '', $local);
        $domain = 'gmail.com';
    }
    return $local . '@' . $domain;
}

function sev_email_domain($email)
{
    $pos = strrpos($email, '@');
    return $pos === false ? '' : strtolower(substr($email, $pos + 1));
}

function sev_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
}

/* ------------------------------------------------------------------- bans */

function sev_is_email_banned($email)
{
    $now = date('Y-m-d H:i:s');
    return Capsule::table('mod_sev_email_bans')
        ->where('email', strtolower(trim($email)))
        ->where(function ($q) use ($now) {
            $q->where('ban_type', 'forever')->orWhere('expires_at', '>', $now);
        })
        ->exists();
}

function sev_is_ip_banned($ip)
{
    if (!$ip) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    return Capsule::table('mod_sev_ip_bans')
        ->where('ip', $ip)
        ->where(function ($q) use ($now) {
            $q->where('ban_type', 'forever')->orWhere('expires_at', '>', $now);
        })
        ->exists();
}

function sev_ban_email($email, $type = 'forever', $reason = '')
{
    $email = strtolower(trim($email));
    if (!$email || Capsule::table('mod_sev_email_bans')->where('email', $email)->exists()) {
        return;
    }
    $expires = null;
    if ($type !== 'forever') {
        $days = max(1, (int) sev_setting('ban_days', 7));
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }
    Capsule::table('mod_sev_email_bans')->insert([
        'email'      => $email,
        'ban_type'   => $type === 'forever' ? 'forever' : 'temporary',
        'reason'     => $reason,
        'expires_at' => $expires,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function sev_ban_ip($ip, $type = 'forever', $reason = '')
{
    if (!$ip || Capsule::table('mod_sev_ip_bans')->where('ip', $ip)->exists()) {
        return;
    }
    $expires = null;
    if ($type !== 'forever') {
        $days = max(1, (int) sev_setting('ban_days', 7));
        $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }
    Capsule::table('mod_sev_ip_bans')->insert([
        'ip'         => $ip,
        'ban_type'   => $type === 'forever' ? 'forever' : 'temporary',
        'reason'     => $reason,
        'expires_at' => $expires,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function sev_is_disposable_domain($email)
{
    $domain = sev_email_domain($email);
    if (!$domain) {
        return false;
    }
    return Capsule::table('mod_sev_domains')->where('domain', $domain)->exists();
}

/* --------------------------------------------------------- verification */

/**
 * Create or refresh the verification record for an address and send the
 * code. Returns [bool ok, string message].
 */
function sev_send_verification($userid, $email, $ip = null)
{
    $email = strtolower(trim($email));
    $ip    = $ip === null ? sev_client_ip() : $ip;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid email address.'];
    }
    if (sev_is_email_banned($email)) {
        return [false, 'This email address is banned.'];
    }
    if (sev_is_ip_banned($ip)) {
        return [false, 'Your IP address is banned.'];
    }

    // Per-IP send throttle: ban the IP after X code emails in 24h.
    $limit = (int) sev_setting('ban_ip_sends', 0);
    if ($limit > 0 && $ip) {
        $sentToday = (int) Capsule::table('mod_sev_emails')
            ->where('ip', $ip)
            ->where('last_sent_at', '>=', date('Y-m-d H:i:s', strtotime('-1 day')))
            ->sum('sends');
        if ($sentToday >= $limit) {
            sev_ban_ip($ip, 'temporary', 'Excessive verification emails');
            return [false, 'Too many verification emails from your network. Try again later.'];
        }
    }

    $now  = date('Y-m-d H:i:s');
    $code = sev_generate_code();
    $row  = Capsule::table('mod_sev_emails')->where('email', $email)->first();

    if ($row) {
        if ($row->verified) {
            return [true, 'already'];
        }
        Capsule::table('mod_sev_emails')->where('id', $row->id)->update([
            'code'         => $code,
            'userid'       => $userid ?: $row->userid,
            'ip'           => $ip,
            'sends'        => $row->sends + 1,
            'attempts'     => 0,
            'last_sent_at' => $now,
        ]);
    } else {
        Capsule::table('mod_sev_emails')->insert([
            'userid'           => (int) $userid,
            'email'            => $email,
            'email_normalized' => sev_normalize_email($email),
            'code'             => $code,
            'verified'         => 0,
            'attempts'         => 0,
            'sends'            => 1,
            'reminder_sent'    => 0,
            'ip'               => $ip,
            'last_sent_at'     => $now,
            'created_at'       => $now,
        ]);
    }

    $name = 'Customer';
    if ($userid) {
        $u = Capsule::table('tblusers')->where('id', $userid)->first();
        if ($u) {
            $name = trim($u->first_name . ' ' . $u->last_name) ?: 'Customer';
        }
    }
    list($subject, $html) = sev_build_email($name, $code);
    list($ok, $error) = sev_mailer_send($email, $subject, $html);
    if (!$ok) {
        sev_log('Verification email to ' . $email . ' failed: ' . $error);
        return [false, 'Could not send the verification email.'];
    }
    return [true, 'sent'];
}

/**
 * Attempt a code. Returns one of: ok, already, notfound, bad, banned.
 */
function sev_verify_code($email, $code)
{
    $email = strtolower(trim($email));
    $code  = strtoupper(trim($code));
    $row   = Capsule::table('mod_sev_emails')->where('email', $email)->first();

    if (!$row) {
        return 'notfound';
    }
    if ($row->verified) {
        return 'already';
    }
    if ($code !== '' && hash_equals(strtoupper($row->code), $code)) {
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_sev_emails')->where('id', $row->id)->update([
            'verified'    => 1,
            'verified_at' => $now,
        ]);
        sev_mark_verified($row->userid, $email);
        return 'ok';
    }

    $attempts = $row->attempts + 1;
    Capsule::table('mod_sev_emails')->where('id', $row->id)->update(['attempts' => $attempts]);
    $maxAttempts = (int) sev_setting('ban_email_attempts', 0);
    if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
        sev_ban_email($email, 'forever', 'Exceeded invalid code attempts');
        return 'banned';
    }
    return 'bad';
}

function sev_mark_verified($userid, $email)
{
    try {
        if ($userid) {
            Capsule::table('tblusers')->where('id', $userid)->update(['email_verified' => 1]);
        }
    } catch (\Exception $e) {
        // email_verified column missing on older installs; non-fatal
    }
}

function sev_build_email($name, $code)
{
    $company   = (string) Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value');
    $signature = (string) Capsule::table('tblconfiguration')->where('setting', 'Signature')->value('value');
    $subject   = sev_setting('email_subject', 'Confirm your email address');
    $template  = sev_setting('email_template',
        '<p>Hi {name},</p>'
        . '<p>Your verification code is:</p>'
        . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
        . '<p>If you did not request this code you can ignore this email.</p>'
        . '<p>{signature}</p>'
    );
    $replace = [
        '{name}'        => $name,
        '{code}'        => $code,
        '{companyname}' => $company,
        '{signature}'   => nl2br($signature),
    ];
    return [strtr($subject, $replace), strtr($template, $replace)];
}

/* ---------------------------------------------------------------- mailer */

/**
 * Send an email through the configured provider.
 * $attachments: array of ['path' => ..., 'name' => ...]
 * Returns [bool ok, string error].
 */
function sev_mailer_send($to, $subject, $html, array $cc = [], array $attachments = [])
{
    $provider  = sev_setting('mail_provider', 'whmcs');
    $fromEmail = sev_setting('from_email');
    $fromName  = sev_setting('from_name');
    if (!$fromEmail) {
        $fromEmail = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemEmailsFromEmail')->value('value');
    }
    if (!$fromName) {
        $fromName = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemEmailsFromName')->value('value');
    }

    switch ($provider) {
        case 'postmark':
            return sev_send_postmark($fromEmail, $fromName, $to, $subject, $html, $cc, $attachments);
        case 'mailgun':
            return sev_send_mailgun($fromEmail, $fromName, $to, $subject, $html, $cc, $attachments);
        case 'sendgrid':
            return sev_send_sendgrid($fromEmail, $fromName, $to, $subject, $html, $cc, $attachments);
        case 'sparkpost':
            return sev_send_sparkpost($fromEmail, $fromName, $to, $subject, $html, $cc, $attachments);
        default:
            return sev_send_whmcs_smtp($fromEmail, $fromName, $to, $subject, $html, $cc, $attachments);
    }
}

function sev_send_whmcs_smtp($fromEmail, $fromName, $to, $subject, $html, array $cc, array $attachments)
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [false, 'PHPMailer not available'];
    }
    $cfg = [];
    foreach (Capsule::table('tblconfiguration')
        ->whereIn('setting', ['MailType', 'SMTPHost', 'SMTPPort', 'SMTPUsername', 'SMTPPassword', 'SMTPSSL'])
        ->get() as $r) {
        $cfg[$r->setting] = $r->value;
    }
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        if (isset($cfg['MailType']) && strtolower($cfg['MailType']) === 'smtp') {
            $mail->isSMTP();
            $mail->Host = $cfg['SMTPHost'];
            $mail->Port = (int) ($cfg['SMTPPort'] ?: 25);
            if (!empty($cfg['SMTPUsername'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $cfg['SMTPUsername'];
                $mail->Password = html_entity_decode((string) $cfg['SMTPPassword']);
            }
            $ssl = strtolower((string) $cfg['SMTPSSL']);
            if ($ssl === 'ssl' || $ssl === 'tls') {
                $mail->SMTPSecure = $ssl;
            }
        } else {
            $mail->isMail();
        }
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        foreach ($cc as $addr) {
            $mail->addCC($addr);
        }
        foreach ($attachments as $att) {
            if (is_file($att['path'])) {
                $mail->addAttachment($att['path'], $att['name']);
            }
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);
        $mail->send();
        return [true, ''];
    } catch (\Exception $e) {
        return [false, $e->getMessage()];
    }
}

function sev_send_postmark($fromEmail, $fromName, $to, $subject, $html, array $cc, array $attachments)
{
    $token = sev_setting('postmark_token');
    if (!$token) {
        return [false, 'Postmark token missing'];
    }
    $payload = [
        'From'     => $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail,
        'To'       => $to,
        'Subject'  => $subject,
        'HtmlBody' => $html,
        'TextBody' => strip_tags($html),
    ];
    if ($cc) {
        $payload['Cc'] = implode(',', $cc);
    }
    foreach ($attachments as $att) {
        if (is_file($att['path'])) {
            $payload['Attachments'][] = [
                'Name'        => $att['name'],
                'Content'     => base64_encode(file_get_contents($att['path'])),
                'ContentType' => 'application/octet-stream',
            ];
        }
    }
    return sev_http_json('https://api.postmarkapp.com/email', [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Postmark-Server-Token: ' . $token,
    ], $payload);
}

function sev_send_mailgun($fromEmail, $fromName, $to, $subject, $html, array $cc, array $attachments)
{
    $key    = sev_setting('mailgun_api_key');
    $domain = sev_setting('mailgun_domain');
    if (!$key || !$domain) {
        return [false, 'Mailgun API key or domain missing'];
    }
    $post = [
        'from'    => $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail,
        'to'      => $to,
        'subject' => $subject,
        'html'    => $html,
        'text'    => strip_tags($html),
    ];
    if ($cc) {
        $post['cc'] = implode(',', $cc);
    }
    $boundary = '----sev' . md5(uniqid('', true));
    $body = '';
    foreach ($post as $name => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    foreach ($attachments as $att) {
        if (is_file($att['path'])) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"{$att['name']}\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n" . file_get_contents($att['path']) . "\r\n";
        }
    }
    $body .= "--{$boundary}--\r\n";
    $ch = curl_init('https://api.mailgun.net/v3/' . $domain . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => 'api:' . $key,
        CURLOPT_HTTPHEADER     => ["Content-Type: multipart/form-data; boundary={$boundary}"],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [false, $err];
    }
    return $code >= 200 && $code < 300 ? [true, ''] : [false, 'Mailgun HTTP ' . $code . ': ' . $resp];
}

function sev_send_sendgrid($fromEmail, $fromName, $to, $subject, $html, array $cc, array $attachments)
{
    $key = sev_setting('sendgrid_api_key');
    if (!$key) {
        return [false, 'SendGrid API key missing'];
    }
    $personalization = ['to' => [['email' => $to]]];
    if ($cc) {
        foreach ($cc as $addr) {
            $personalization['cc'][] = ['email' => $addr];
        }
    }
    $payload = [
        'personalizations' => [$personalization],
        'from'             => ['email' => $fromEmail, 'name' => $fromName],
        'subject'          => $subject,
        'content'          => [
            ['type' => 'text/plain', 'value' => strip_tags($html)],
            ['type' => 'text/html', 'value' => $html],
        ],
    ];
    foreach ($attachments as $att) {
        if (is_file($att['path'])) {
            $payload['attachments'][] = [
                'content'  => base64_encode(file_get_contents($att['path'])),
                'filename' => $att['name'],
                'type'     => 'application/octet-stream',
            ];
        }
    }
    return sev_http_json('https://api.sendgrid.com/v3/mail/send', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], $payload);
}

function sev_send_sparkpost($fromEmail, $fromName, $to, $subject, $html, array $cc, array $attachments)
{
    $key = sev_setting('sparkpost_api_key');
    if (!$key) {
        return [false, 'SparkPost API key missing'];
    }
    $content = [
        'from'    => ['email' => $fromEmail, 'name' => $fromName],
        'subject' => $subject,
        'html'    => $html,
        'text'    => strip_tags($html),
    ];
    $recipients = [['address' => ['email' => $to]]];
    if ($cc) {
        $content['headers'] = ['CC' => implode(',', $cc)];
        foreach ($cc as $addr) {
            $recipients[] = ['address' => ['email' => $addr, 'header_to' => $to]];
        }
    }
    foreach ($attachments as $att) {
        if (is_file($att['path'])) {
            $content['attachments'][] = [
                'name' => $att['name'],
                'type' => 'application/octet-stream',
                'data' => base64_encode(file_get_contents($att['path'])),
            ];
        }
    }
    return sev_http_json('https://api.sparkpost.com/api/v1/transmissions', [
        'Content-Type: application/json',
        'Authorization: ' . $key,
    ], ['content' => $content, 'recipients' => $recipients]);
}

function sev_http_json($url, array $headers, array $payload)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return [false, $err];
    }
    return $code >= 200 && $code < 300 ? [true, ''] : [false, 'HTTP ' . $code . ': ' . $resp];
}

/* ---------------------------------------------------------------- captcha */

function sev_check_captcha()
{
    $provider = sev_setting('captcha_provider', 'none');
    if ($provider === 'recaptcha_v3') {
        $secret = sev_setting('recaptcha_secret_key');
        $token  = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
        if (!$secret || !$token) {
            return false;
        }
        $resp = sev_http_post('https://www.google.com/recaptcha/api/siteverify', [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => sev_client_ip(),
        ]);
        $data = json_decode($resp, true);
        return !empty($data['success']) && (!isset($data['score']) || $data['score'] >= 0.5);
    }
    if ($provider === 'turnstile') {
        $secret = sev_setting('turnstile_secret_key');
        $token  = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
        if (!$secret || !$token) {
            return false;
        }
        $resp = sev_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => sev_client_ip(),
        ]);
        $data = json_decode($resp, true);
        return !empty($data['success']);
    }
    return true;
}

function sev_http_post($url, array $post)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ?: '';
}

/* ------------------------------------------------------------------- misc */

function sev_log($message)
{
    if (function_exists('logActivity')) {
        logActivity('Super Email Verification: ' . $message);
    }
}
