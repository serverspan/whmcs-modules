<?php
/**
 * Super Email Verification Pro - hooks
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/supermailverify/hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

/**
 * Is the given client (by email) currently unverified?
 * Returns the record when unverified, null when verified/unknown.
 */
function sev_unverified_record($email)
{
    static $cache = [];
    $email = strtolower(trim((string) $email));
    if (!$email) {
        return null;
    }
    if (!array_key_exists($email, $cache)) {
        $row = Capsule::table('mod_sev_emails')->where('email', $email)->first();
        $cache[$email] = ($row && !$row->verified) ? $row : null;
    }
    return $cache[$email];
}

/* --------------------------------------------- registration validation */

add_hook('ClientDetailsValidation', 1, function ($vars) {
    $email = strtolower(trim((string) $vars['email']));
    if (!$email) {
        return;
    }
    if (sev_is_ip_banned(sev_client_ip())) {
        return 'Your IP address is not allowed to register.';
    }
    if (sev_is_email_banned($email)) {
        return 'This email address is not allowed to register.';
    }
    if (sev_is_disposable_domain($email)) {
        return 'Registration with disposable or temporary email addresses is not allowed.';
    }

    // Duplicate detection that defeats Gmail dot/plus tricks.
    $normalized = sev_normalize_email($email);
    $dup = Capsule::table('mod_sev_emails')
        ->where('email_normalized', $normalized)
        ->where('email', '!=', $email)
        ->where('userid', '>', 0)
        ->exists();
    if (!$dup && in_array(sev_email_domain($email), ['gmail.com', 'googlemail.com'], true)) {
        foreach (Capsule::table('tblclients')->where('email', 'like', '%@gmail.com')
            ->orWhere('email', 'like', '%@googlemail.com')->pluck('email') as $existing) {
            if (sev_normalize_email($existing) === $normalized) {
                $dup = true;
                break;
            }
        }
    }
    if ($dup) {
        return 'An account with this email address already exists.';
    }

    if (!sev_check_captcha()) {
        return 'Captcha verification failed. Please try again.';
    }
});

/* ---------------------------------------------------- send code at signup */

add_hook('ClientAdd', 1, function ($vars) {
    $email = isset($vars['email']) ? $vars['email'] : '';
    if (!$email || sev_is_email_banned($email) || sev_is_disposable_domain($email)) {
        return;
    }
    // tblusers id linked to the new client; fall back to 0 if unavailable.
    $userid = 0;
    try {
        $userid = (int) Capsule::table('tblusers_clients')
            ->where('client_id', $vars['client_id'])->where('owner', 1)->value('auth_user_id');
        if (!$userid) {
            $c = Capsule::table('tblclients')->where('id', $vars['client_id'])->first();
            if ($c) {
                $u = Capsule::table('tblusers')->where('email', $c->email)->first();
                $userid = $u ? (int) $u->id : 0;
            }
        }
    } catch (\Exception $e) {
    }
    sev_send_verification($userid, $email);
});

/* -------------------------------------- enforcement inside client area */

add_hook('ClientAreaPage', 1, function ($vars) {
    if (empty($vars['clientsdetails']['email'])) {
        return;
    }
    $type = sev_setting('verification_type', 'static');
    if ($type === 'after') {
        return; // "after" mode only nags; checkout block handles enforcement
    }
    if (!sev_unverified_record($vars['clientsdetails']['email'])) {
        return;
    }
    // Let the module page, logout and file downloads through.
    $script = basename((string) $_SERVER['SCRIPT_NAME']);
    if ((isset($_GET['m']) && $_GET['m'] === 'supermailverify') || $script === 'logout.php') {
        return;
    }
    if ($type === 'static') {
        header('Location: index.php?m=supermailverify');
        exit;
    }
    // modal mode: popup injected by the footer hook below
});

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    if (empty($vars['clientsdetails']['email'])) {
        return;
    }
    $type = sev_setting('verification_type', 'static');
    if (!sev_unverified_record($vars['clientsdetails']['email'])) {
        return;
    }
    if ($type === 'after' && !isset($_GET['m'])) {
        return '<div class="alert alert-warning" style="margin:10px 0">Please verify your email address. '
            . '<a href="index.php?m=supermailverify">Click here to enter your verification code</a>.</div>';
    }
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $out = '';
    $captcha = sev_setting('captcha_provider', 'none');

    // Modal-mode popup for unverified logged-in clients.
    if (!empty($vars['clientsdetails']['email'])
        && sev_setting('verification_type', 'static') === 'modal'
        && !(isset($_GET['m']) && $_GET['m'] === 'supermailverify')
        && sev_unverified_record($vars['clientsdetails']['email'])) {
        $out .= '<div id="sevModal" style="position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.6);'
            . 'display:flex;align-items:center;justify-content:center">'
            . '<div style="background:#fff;padding:30px;border-radius:6px;max-width:420px;width:90%;text-align:center">'
            . '<h4 style="margin-top:0">Verify your email address</h4>'
            . '<p>We sent a verification code to <strong>' . htmlspecialchars($vars['clientsdetails']['email']) . '</strong>.</p>'
            . '<p><a class="btn btn-primary" href="index.php?m=supermailverify">Enter verification code</a> '
            . '<a class="btn btn-default" href="logout.php">Log out</a></p>'
            . '</div></div>';
    }

    // Captcha bootstrap on registration and the module verify page.
    $isVerifyPage = isset($_GET['m']) && $_GET['m'] === 'supermailverify';
    $isRegister   = basename((string) $_SERVER['SCRIPT_NAME']) === 'register.php';
    if ($captcha === 'recaptcha_v3' && ($isRegister || $isVerifyPage)) {
        $siteKey = sev_setting('recaptcha_site_key');
        if ($siteKey) {
            $out .= '<script src="https://www.google.com/recaptcha/api.js?render=' . htmlspecialchars($siteKey) . '"></script>'
                . '<script>document.addEventListener("submit",function(e){'
                . 'if(e.target.dataset.sevCaptcha)return;e.preventDefault();e.target.dataset.sevCaptcha=1;'
                . 'grecaptcha.execute("' . htmlspecialchars($siteKey) . '",{action:"submit"}).then(function(t){'
                . 'var i=document.createElement("input");i.type="hidden";i.name="g-recaptcha-response";i.value=t;'
                . 'e.target.appendChild(i);e.target.submit();});},true);</script>';
        }
    } elseif ($captcha === 'turnstile' && ($isRegister || $isVerifyPage)) {
        $siteKey = sev_setting('turnstile_site_key');
        if ($siteKey) {
            $out .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
                . '<script>document.addEventListener("DOMContentLoaded",function(){'
                . 'document.querySelectorAll("form").forEach(function(f){'
                . 'if(f.querySelector("input[name=\'sev_do\']")||f.action.indexOf("register")>-1){'
                . 'var d=document.createElement("div");d.className="cf-turnstile";'
                . 'd.setAttribute("data-sitekey","' . htmlspecialchars($siteKey) . '");'
                . 'var b=f.querySelector("[type=submit]");f.insertBefore(d,b);}});});</script>';
        }
    }
    return $out;
});

/* ------------------------------------------------- gated functionality */

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (empty($_SESSION['uid'])) {
        return;
    }
    $user = Capsule::table('tblusers')->where('id', (int) $_SESSION['uid'])->first();
    if ($user && sev_unverified_record($user->email)) {
        return ['Please verify your email address before placing an order. '
            . 'Visit index.php?m=supermailverify to enter your code.'];
    }
});

add_hook('TicketOpenValidation', 1, function ($vars) {
    if (sev_setting('require_verify_tickets', '') !== 'on') {
        return;
    }
    $email = '';
    if (!empty($vars['userid'])) {
        $user = Capsule::table('tblusers')->where('id', (int) $vars['userid'])->first();
        $email = $user ? $user->email : '';
    } elseif (!empty($vars['email'])) {
        $email = $vars['email'];
    }
    if (!$email) {
        return;
    }
    if (sev_is_email_banned($email) || sev_is_disposable_domain($email)) {
        return 'This email address is not allowed to open tickets.';
    }
    if (sev_unverified_record($email)) {
        return 'Please verify your email address before opening a ticket. '
            . 'Use the verification page at index.php?m=supermailverify.';
    }
    // Unknown sender: create a verification request and ask them to verify.
    $known = Capsule::table('mod_sev_emails')->where('email', strtolower($email))->exists();
    if (!$known) {
        sev_send_verification(0, $email);
        return 'We sent a verification code to ' . $email . '. Verify it before opening a ticket.';
    }
});

add_hook('ContactDetailsValidation', 1, function ($vars) {
    $email = strtolower(trim((string) $vars['email']));
    if (!$email) {
        return;
    }
    if (sev_is_ip_banned(sev_client_ip()) || sev_is_email_banned($email)) {
        return 'Your address is not allowed to contact us.';
    }
    if (sev_is_disposable_domain($email)) {
        return 'Disposable email addresses cannot be used.';
    }
    if (sev_setting('require_verify_contact', '') === 'on') {
        if (sev_unverified_record($email)) {
            return 'Please verify your email address before sending a message.';
        }
        $known = Capsule::table('mod_sev_emails')->where('email', $email)->exists();
        if (!$known) {
            sev_send_verification(0, $email);
            return 'We sent a verification code to ' . $email . '. Verify it before sending your message.';
        }
    }
});

/* ------------------------------------------- daily automation (cron) */

add_hook('DailyCronJob', 1, function () {
    $now = time();

    $reminderDays = (int) sev_setting('reminder_days', 0);
    if ($reminderDays > 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$reminderDays} days", $now));
        $rows = Capsule::table('mod_sev_emails')
            ->where('verified', 0)->where('reminder_sent', 0)->where('created_at', '<=', $cutoff)->get();
        foreach ($rows as $row) {
            list($ok) = sev_send_verification($row->userid, $row->email);
            Capsule::table('mod_sev_emails')->where('id', $row->id)->update(['reminder_sent' => 1]);
        }
    }

    $deactivateDays = (int) sev_setting('deactivate_days', 0);
    if ($deactivateDays > 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$deactivateDays} days", $now));
        $ids = Capsule::table('mod_sev_emails')
            ->where('verified', 0)->where('userid', '>', 0)->where('created_at', '<=', $cutoff)
            ->pluck('userid');
        foreach ($ids as $uid) {
            $client = Capsule::table('tblusers_clients')->where('auth_user_id', $uid)->where('owner', 1)->first();
            if ($client) {
                Capsule::table('tblclients')->where('id', $client->client_id)
                    ->where('status', 'Active')->update(['status' => 'Inactive']);
            }
        }
    }

    $terminateDays = (int) sev_setting('terminate_days', 0);
    if ($terminateDays > 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$terminateDays} days", $now));
        $ids = Capsule::table('mod_sev_emails')
            ->where('verified', 0)->where('userid', '>', 0)->where('created_at', '<=', $cutoff)
            ->pluck('userid');
        foreach ($ids as $uid) {
            $client = Capsule::table('tblusers_clients')->where('auth_user_id', $uid)->where('owner', 1)->first();
            if ($client) {
                Capsule::table('tblclients')->where('id', $client->client_id)
                    ->whereIn('status', ['Active', 'Inactive'])->update(['status' => 'Closed']);
            }
        }
    }

    $deleteDays = (int) sev_setting('delete_days', 0);
    if ($deleteDays > 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$deleteDays} days", $now));
        $rows = Capsule::table('mod_sev_emails')
            ->where('verified', 0)->where('userid', '>', 0)->where('created_at', '<=', $cutoff)->get();
        foreach ($rows as $row) {
            $client = Capsule::table('tblusers_clients')->where('auth_user_id', $row->userid)->where('owner', 1)->first();
            if (!$client) {
                continue;
            }
            $clientId = (int) $client->client_id;
            $hasServices = Capsule::table('tblhosting')->where('userid', $clientId)->exists();
            $hasUnpaid   = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->exists();
            if ($hasServices || $hasUnpaid) {
                continue;
            }
            $result = localAPI('DeleteClient', ['clientid' => $clientId, 'deleteusers' => true]);
            if (!empty($result['result']) && $result['result'] === 'success') {
                Capsule::table('mod_sev_emails')->where('id', $row->id)->delete();
                sev_log('Deleted unverified client #' . $clientId . ' (' . $row->email . ')');
            }
        }
    }
});
