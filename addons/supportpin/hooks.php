<?php
/**
 * ServerSpan Support PIN - hooks
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/supportpin/hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/Functions.php';

/* ------------------------------------------------- admin dashboard widget */

add_hook('AdminHomeWidgets', 1, function () {
    return new ServerSpanSupportPinWidget();
});

class ServerSpanSupportPinWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Support PIN Verification';
    protected $description = 'Verify a client by their support PIN.';
    protected $weight = 140;
    protected $columns = 1;
    protected $cache = false;

    public function getData()
    {
        return [];
    }

    public function generateOutput($data)
    {
        return <<<'HTML'
<div class="widget-content-padded">
    <form method="get" action="addonmodules.php" class="form-inline">
        <input type="hidden" name="module" value="supportpin">
        <div class="input-group" style="width:100%">
            <input type="text" name="widget_pin" class="form-control" placeholder="Client's support PIN"
                   autocomplete="off" inputmode="numeric">
            <span class="input-group-btn">
                <button class="btn btn-primary" type="submit">Verify</button>
            </span>
        </div>
    </form>
    <p class="text-muted" style="margin:8px 0 0">Enter the PIN the client reads from their client area.</p>
</div>
HTML;
    }
}

/* ---------------------------------- staff access restriction to profiles */

add_hook('AdminAreaPage', 1, function ($vars) {
    if (pin_setting('restrict_staff', '') !== 'on') {
        return;
    }
    $adminid = (int) (isset($_SESSION['adminid']) ? $_SESSION['adminid'] : 0);
    if (!$adminid) {
        return;
    }
    $roleid = (string) Capsule::table('tbladmins')->where('id', $adminid)->value('roleid');
    $exempt = array_map('trim', explode(',', (string) pin_setting('exempt_roles', '1')));
    if (in_array($roleid, $exempt, true)) {
        return;
    }

    $script = basename((string) $_SERVER['SCRIPT_NAME']);
    $protected = [
        'clientssummary.php', 'clientsprofile.php', 'clientscontacts.php', 'clientsservices.php',
        'clientsdomains.php', 'clientsinvoices.php', 'clientsbillableitems.php',
        'clientstransactions.php', 'clientsemails.php', 'clientsnotes.php',
        'clientstickets.php', 'clientsmanage.php', 'clientscredit.php', 'clientslog.php',
    ];
    if (!in_array($script, $protected, true)) {
        return;
    }

    $clientid = 0;
    if (isset($_GET['userid'])) {
        $clientid = (int) $_GET['userid'];
    } elseif (isset($_GET['id'])) {
        $clientid = (int) $_GET['id'];
    }
    if (!$clientid) {
        return;
    }
    if (pin_has_grant($adminid, $clientid)) {
        return;
    }

    header('Location: addonmodules.php?module=supportpin&verify_for=' . $clientid);
    exit;
});

/* --------------------------------------------------- client area sidebar */

add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
    if (!$sidebar) {
        return;
    }
    $panel = $sidebar->getChild('Support PIN');
    if (!$panel) {
        $panel = $sidebar->addChild('Support PIN', [
            'label' => 'Support PIN',
            'icon'  => 'fa-shield',
            'order' => 90,
        ]);
    }
    if ($panel && !$panel->getChild('View Support PIN')) {
        $panel->addChild('View Support PIN', [
            'label' => 'View Support PIN',
            'uri'   => 'index.php?m=supportpin',
            'icon'  => 'fa-key',
        ]);
    }
});

/* ----------------------------------------------------------- cron cleanup */

add_hook('DailyCronJob', 1, function () {
    $now = date('Y-m-d H:i:s');
    // Drop expired PINs and one-time PINs used more than 7 days ago.
    Capsule::table('mod_pin_pins')
        ->where(function ($q) use ($now) {
            $q->whereNotNull('expires_at')->where('expires_at', '<=', $now);
        })
        ->orWhere(function ($q) {
            $q->whereNotNull('used_at')
              ->where('used_at', '<=', date('Y-m-d H:i:s', strtotime('-7 days')));
        })
        ->delete();
    Capsule::table('mod_pin_grants')->where('expires_at', '<=', $now)->delete();
    Capsule::table('mod_pin_log')
        ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime('-90 days')))
        ->delete();
});
