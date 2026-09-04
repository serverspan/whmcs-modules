<?php
/**
 * ServerSpan QuickBooks Sync - shared library
 * Developed by ServerSpan - https://www.serverspan.com
 * Location: modules/addons/qbosync/lib/Functions.php
 *
 * Production notes: token refresh is row-locked (no invalid_grant races),
 * creates carry QBO requestid idempotency keys, 401s force-refresh once,
 * 429s requeue without consuming attempts.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/* ---------------------------------------------------------------- settings */

function qbo_settings($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', 'qbosync')->get() as $row) {
            $cache[$row->setting] = $row->value;
        }
    }
    return $cache;
}

function qbo_setting($key, $default = '')
{
    $s = qbo_settings();
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}

function qbo_api_base()
{
    $realm = qbo_realm();
    $host = qbo_setting('environment', 'sandbox') === 'production'
        ? 'https://quickbooks.api.intuit.com'
        : 'https://sandbox-quickbooks.api.intuit.com';
    return $host . '/v3/company/' . $realm;
}

function qbo_realm()
{
    $auth = Capsule::table('mod_qbo_auth')->orderBy('id', 'desc')->first();
    return $auth ? $auth->realm_id : '';
}

/* ----------------------------------------------------------------- OAuth2 */

function qbo_redirect_uri()
{
    $sysurl = rtrim((string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');
    return $sysurl . '/admin/addonmodules.php?module=qbosync&qbo_oauth=callback';
}

function qbo_authorize_url()
{
    $state = bin2hex(random_bytes(12));
    $_SESSION['qbo_oauth_state'] = $state;
    return 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query([
        'client_id'     => qbo_setting('client_id'),
        'scope'         => 'com.intuit.quickbooks.accounting',
        'redirect_uri'  => qbo_redirect_uri(),
        'response_type' => 'code',
        'state'         => $state,
    ]);
}

function qbo_token_request(array $params)
{
    $ch = curl_init('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(qbo_setting('client_id') . ':' . qbo_setting('client_secret')),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $body, true) ?: []];
}

function qbo_connect($code, $realmId)
{
    list($http, $resp) = qbo_token_request([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => qbo_redirect_uri(),
    ]);
    if ($http !== 200 || empty($resp['access_token'])) {
        return [false, isset($resp['error']) ? $resp['error'] : 'HTTP ' . $http];
    }
    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_qbo_auth')->delete(); // single company connection
    Capsule::table('mod_qbo_auth')->insert([
        'realm_id'           => $realmId,
        'access_token'       => encrypt($resp['access_token']),
        'refresh_token'      => encrypt($resp['refresh_token']),
        'access_expires_at'  => date('Y-m-d H:i:s', time() + (int) $resp['expires_in'] - 60),
        'refresh_expires_at' => date('Y-m-d H:i:s', time() + (int) $resp['x_refresh_token_expires_in']),
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);
    return [true, ''];
}

function qbo_disconnect()
{
    Capsule::table('mod_qbo_auth')->delete();
}

/**
 * Valid access token. Row-locked refresh so concurrent processes can't kill
 * the connection with a rotated refresh token. Memoized per request.
 */
function qbo_access_token($force = false)
{
    static $memo = null;
    static $memoExpiry = 0;
    if (!$force && $memo && $memoExpiry > time()) {
        return $memo;
    }
    $auth = Capsule::table('mod_qbo_auth')->orderBy('id', 'desc')->first();
    if (!$auth) {
        return '';
    }
    if (!$force && $auth->access_expires_at > date('Y-m-d H:i:s')) {
        $memo = decrypt($auth->access_token);
        $memoExpiry = strtotime($auth->access_expires_at);
        return $memo;
    }

    $result = null;
    try {
        Capsule::connection()->transaction(function () use (&$result, $auth) {
            $row = Capsule::table('mod_qbo_auth')->where('id', $auth->id)->lockForUpdate()->first();
            if (!$row) {
                return;
            }
            // Another worker may have refreshed while we waited for the lock.
            if ($row->access_expires_at > date('Y-m-d H:i:s') && $row->updated_at !== $auth->updated_at) {
                $result = decrypt($row->access_token);
                return;
            }
            if ($row->refresh_expires_at <= date('Y-m-d H:i:s')) {
                qbo_log('auth', 'auth', '0', '', 'failed', 'Refresh token expired — reconnect required');
                return;
            }
            list($http, $resp) = qbo_token_request([
                'grant_type'    => 'refresh_token',
                'refresh_token' => decrypt($row->refresh_token),
            ]);
            if ($http !== 200 || empty($resp['access_token'])) {
                qbo_log('auth', 'auth', '0', '', 'failed', 'Token refresh failed: HTTP ' . $http);
                return;
            }
            Capsule::table('mod_qbo_auth')->where('id', $row->id)->update([
                'access_token'       => encrypt($resp['access_token']),
                'refresh_token'      => encrypt($resp['refresh_token']),
                'access_expires_at'  => date('Y-m-d H:i:s', time() + (int) $resp['expires_in'] - 60),
                'refresh_expires_at' => date('Y-m-d H:i:s', time() + (int) $resp['x_refresh_token_expires_in']),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
            $result = $resp['access_token'];
        });
    } catch (\Exception $e) {
        qbo_log('auth', 'auth', '0', '', 'failed', 'Refresh lock error: ' . $e->getMessage());
    }
    if ($result) {
        $memo = $result;
        $memoExpiry = time() + 3500;
    }
    return $result ?: '';
}

/* --------------------------------------------------------------------- API */

/**
 * QBO API call. On 401, force a token refresh and retry exactly once.
 */
function qbo_api($method, $path, array $payload = null, $retry = true)
{
    $token = qbo_access_token();
    if (!$token) {
        return [0, ['Fault' => ['Error' => [['Message' => 'Not connected / token refresh failed']]]]];
    }
    $url = qbo_api_base() . $path . (strpos($path, '?') === false ? '?' : '&') . 'minorversion=75';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 401 && $retry) {
        qbo_access_token(true);
        return qbo_api($method, $path, $payload, false);
    }
    return [$code, json_decode((string) $body, true) ?: ['raw' => $body]];
}

/**
 * Create an entity with a requestid so retries return the original object
 * instead of duplicating it.
 */
function qbo_create($entity, array $payload, $requestId)
{
    return qbo_api('POST', '/' . $entity . '?requestid=' . urlencode($requestId), $payload);
}

function qbo_query($sql)
{
    list($code, $resp) = qbo_api('GET', '/query?query=' . urlencode($sql));
    if ($code !== 200) {
        return [];
    }
    $qr = isset($resp['QueryResponse']) ? $resp['QueryResponse'] : [];
    $qr = array_diff_key($qr, ['startPosition' => 1, 'maxResults' => 1, 'totalCount' => 1]);
    $first = reset($qr);
    return is_array($first) ? array_values($first) : [];
}

function qbo_fault($resp, $code)
{
    if (isset($resp['Fault']['Error'][0]['Message'])) {
        $f = $resp['Fault']['Error'][0];
        return $f['Message'] . (isset($f['Detail']) ? ' — ' . $f['Detail'] : '');
    }
    return 'HTTP ' . $code;
}

function qbo_is_rate_limited($result)
{
    return is_string($result) && strpos($result, 'HTTP 429') !== false;
}

/* --------------------------------------------------------------- relations */

function qbo_rel_get($type, $whmcsKey)
{
    $row = Capsule::table('mod_qbo_rel')
        ->where('rel_type', $type)->where('whmcs_key', (string) $whmcsKey)->first();
    return $row ? $row->qbo_id : '';
}

function qbo_rel_set($type, $whmcsKey, $qboId, $qboName = '')
{
    Capsule::table('mod_qbo_rel')->updateOrInsert(
        ['rel_type' => $type, 'whmcs_key' => (string) $whmcsKey],
        ['qbo_id' => (string) $qboId, 'qbo_name' => (string) $qboName]
    );
}

function qbo_map_get($entity, $whmcsId)
{
    $row = Capsule::table('mod_qbo_map')
        ->where('entity', $entity)->where('whmcs_id', (string) $whmcsId)->first();
    return $row ? $row->qbo_id : '';
}

function qbo_map_set($entity, $whmcsId, $qboId)
{
    Capsule::table('mod_qbo_map')->updateOrInsert(
        ['entity' => $entity, 'whmcs_id' => (string) $whmcsId],
        ['qbo_id' => (string) $qboId, 'synced_at' => date('Y-m-d H:i:s')]
    );
}

/* --------------------------------------------------------------- sync: misc */

function qbo_item_for($type)
{
    $type = $type ?: 'Other';
    $existing = qbo_rel_get('item', $type);
    if ($existing) {
        return $existing;
    }
    $name = 'WHMCS ' . ucfirst($type);
    $found = qbo_query("SELECT * FROM Item WHERE Name = '" . addslashes($name) . "'");
    if ($found) {
        qbo_rel_set('item', $type, $found[0]['Id'], $name);
        return $found[0]['Id'];
    }
    $income = qbo_query("SELECT * FROM Account WHERE AccountType = 'Income' MAXRESULTS 1");
    if (!$income) {
        return '';
    }
    list($code, $resp) = qbo_create('item', [
        'Name' => $name,
        'Type' => 'Service',
        'IncomeAccountRef' => ['value' => $income[0]['Id']],
    ], 'whmcs-item-' . md5($name));
    if ($code !== 200 || empty($resp['Item']['Id'])) {
        return '';
    }
    qbo_rel_set('item', $type, $resp['Item']['Id'], $name);
    return $resp['Item']['Id'];
}

function qbo_taxcode_for_client($client, $invoice)
{
    $hasTax1 = (float) $invoice->tax > 0;
    $hasTax2 = (float) $invoice->tax2 > 0;
    if ($hasTax1 && $hasTax2) {
        $combined = qbo_rel_get('tax', 'combined');
        if ($combined) {
            return $combined;
        }
    }
    if ($hasTax1 || $hasTax2) {
        $rule = Capsule::table('tbltax')
            ->where('country', $client->country)
            ->where(function ($q) use ($client) {
                $q->where('state', '')->orWhere('state', $client->state);
            })
            ->orderBy('level')->first();
        if (!$rule) {
            $rule = Capsule::table('tbltax')->where('country', '')->orderBy('level')->first();
        }
        if ($rule) {
            $mapped = qbo_rel_get('tax', 'rule_' . $rule->id);
            if ($mapped) {
                return $mapped;
            }
        }
    }
    return qbo_rel_get('tax', 'non') ?: '';
}

/* ------------------------------------------------------------ sync: client */

function qbo_customer_payload($c, $display)
{
    return [
        'DisplayName'      => $display,
        'GivenName'        => $c->firstname,
        'FamilyName'       => $c->lastname,
        'CompanyName'      => $c->companyname,
        'PrimaryEmailAddr' => ['Address' => $c->email],
        'PrimaryPhone'     => ['FreeFormNumber' => $c->phonenumber],
        'BillAddr'         => [
            'Line1' => $c->address1, 'Line2' => $c->address2, 'City' => $c->city,
            'CountrySubDivisionCode' => $c->state, 'PostalCode' => $c->postcode,
            'Country' => $c->country,
        ],
    ];
}

function qbo_display_name($c)
{
    $display = trim($c->firstname . ' ' . $c->lastname);
    if ($c->companyname) {
        $display .= ' (' . $c->companyname . ')';
    }
    return $display . ' [WHMCS-' . $c->id . ']';
}

function qbo_sync_customer($clientid)
{
    $mapped = qbo_map_get('customer', $clientid);
    if ($mapped) {
        return [true, $mapped];
    }
    $c = Capsule::table('tblclients')->where('id', (int) $clientid)->first();
    if (!$c) {
        return [false, 'Client not found.'];
    }
    $found = qbo_query("SELECT * FROM Customer WHERE PrimaryEmailAddr = '" . addslashes($c->email) . "' MAXRESULTS 1");
    if ($found) {
        qbo_map_set('customer', $clientid, $found[0]['Id']);
        return [true, $found[0]['Id']];
    }
    list($code, $resp) = qbo_create('customer', qbo_customer_payload($c, qbo_display_name($c)), 'whmcs-customer-' . $c->id);
    if ($code !== 200 || empty($resp['Customer']['Id'])) {
        return [false, qbo_fault($resp, $code)];
    }
    qbo_map_set('customer', $clientid, $resp['Customer']['Id']);
    return [true, $resp['Customer']['Id']];
}

/**
 * Sparse update of an already-mapped customer (profile edits never duplicate).
 */
function qbo_update_customer($clientid)
{
    $qboId = qbo_map_get('customer', $clientid);
    if (!$qboId) {
        return qbo_sync_customer($clientid);
    }
    $c = Capsule::table('tblclients')->where('id', (int) $clientid)->first();
    if (!$c) {
        return [false, 'Client not found.'];
    }
    list($code, $current) = qbo_api('GET', '/customer/' . $qboId);
    if ($code !== 200 || empty($current['Customer']['SyncToken'])) {
        return [false, qbo_fault($current, $code)];
    }
    $payload = qbo_customer_payload($c, qbo_display_name($c));
    $payload['Id'] = $qboId;
    $payload['SyncToken'] = $current['Customer']['SyncToken'];
    $payload['sparse'] = true;
    list($code, $resp) = qbo_api('POST', '/customer', $payload);
    if ($code !== 200) {
        return [false, qbo_fault($resp, $code)];
    }
    qbo_map_set('customer', $clientid, $qboId);
    return [true, $qboId];
}

/* ----------------------------------------------------------- sync: invoice */

function qbo_invoice_lines($inv, $client)
{
    $taxCode = qbo_taxcode_for_client($client, $inv);
    $lines = [];
    foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $inv->id)->get() as $item) {
        $itemId = qbo_item_for($item->type);
        if (!$itemId) {
            return [false, 'Could not resolve a QBO item for type "' . $item->type . '".'];
        }
        $detail = [
            'ItemRef'   => ['value' => $itemId],
            'Qty'       => 1,
            'UnitPrice' => (float) $item->amount,
        ];
        if ($taxCode) {
            $detail['TaxCodeRef'] = ['value' => $taxCode];
        }
        $lines[] = [
            'Description'        => $item->description,
            'Amount'             => (float) $item->amount,
            'DetailType'         => 'SalesItemLineDetail',
            'SalesItemLineDetail' => $detail,
        ];
    }
    if (!$lines) {
        return [false, 'Invoice has no line items.'];
    }
    $lines[] = ['Amount' => (float) $inv->subtotal, 'DetailType' => 'SubTotalLineDetail', 'SubTotalLineDetail' => new \stdClass()];
    return [true, $lines];
}

function qbo_invoice_payload($inv, $client, $qboCustomer, $lines)
{
    $currency = Capsule::table('tblcurrencies')->where('id', $inv->currency)->first();
    $payload = [
        'CustomerRef' => ['value' => $qboCustomer],
        'DocNumber'   => (string) ($inv->invoicenum ?: $inv->id),
        'TxnDate'     => date('Y-m-d', strtotime($inv->date)),
        'DueDate'     => date('Y-m-d', strtotime($inv->duedate)),
        'Line'        => $lines,
        'GlobalTaxCalculation' => 'TaxExcluded',
        'BillEmail'   => ['Address' => $client ? $client->email : ''],
        'PrivateNote' => 'WHMCS invoice #' . $inv->id,
    ];
    if ($currency) {
        $payload['CurrencyRef'] = ['value' => $currency->code];
    }
    if ((float) $inv->tax + (float) $inv->tax2 > 0) {
        $payload['TxnTaxDetail'] = ['TotalTax' => (float) $inv->tax + (float) $inv->tax2];
    }
    return $payload;
}

function qbo_sync_invoice($invoiceid)
{
    $existing = qbo_map_get('invoice', $invoiceid);
    if ($existing) {
        // Already synced: update in place while the WHMCS invoice is unpaid.
        $inv = Capsule::table('tblinvoices')->where('id', (int) $invoiceid)->first();
        if ($inv && $inv->status === 'Unpaid') {
            return qbo_update_invoice($inv, $existing);
        }
        return [true, $existing];
    }
    $inv = Capsule::table('tblinvoices')->where('id', (int) $invoiceid)->first();
    if (!$inv) {
        return [false, 'Invoice not found.'];
    }
    list($ok, $qboCustomer) = qbo_sync_customer($inv->userid);
    if (!$ok) {
        return [false, $qboCustomer];
    }
    $client = Capsule::table('tblclients')->where('id', $inv->userid)->first();
    list($ok, $lines) = qbo_invoice_lines($inv, $client);
    if (!$ok) {
        return [false, $lines];
    }
    list($code, $resp) = qbo_create('invoice',
        qbo_invoice_payload($inv, $client, $qboCustomer, $lines), 'whmcs-invoice-' . $inv->id);
    if ($code !== 200 || empty($resp['Invoice']['Id'])) {
        return [false, qbo_fault($resp, $code)];
    }
    $qboId = $resp['Invoice']['Id'];
    qbo_map_set('invoice', $invoiceid, $qboId);
    return [true, $qboId];
}

/**
 * Full update of a previously synced (still unpaid) invoice via SyncToken.
 */
function qbo_update_invoice($inv, $qboId)
{
    list($ok, $qboCustomer) = qbo_sync_customer($inv->userid);
    if (!$ok) {
        return [false, $qboCustomer];
    }
    $client = Capsule::table('tblclients')->where('id', $inv->userid)->first();
    list($ok, $lines) = qbo_invoice_lines($inv, $client);
    if (!$ok) {
        return [false, $lines];
    }
    list($code, $current) = qbo_api('GET', '/invoice/' . $qboId);
    if ($code !== 200 || !isset($current['Invoice']['SyncToken'])) {
        return [false, qbo_fault($current, $code)];
    }
    $payload = qbo_invoice_payload($inv, $client, $qboCustomer, $lines);
    $payload['Id'] = $qboId;
    $payload['SyncToken'] = $current['Invoice']['SyncToken'];
    list($code, $resp) = qbo_api('POST', '/invoice', $payload);
    if ($code !== 200) {
        return [false, qbo_fault($resp, $code)];
    }
    return [true, $qboId];
}

/* ----------------------------------------------------------- sync: payment */

function qbo_sync_payment($transactionid)
{
    if ($id = qbo_map_get('payment', $transactionid)) {
        return [true, $id];
    }
    $txn = Capsule::table('tblaccounts')->where('id', (int) $transactionid)->first();
    if (!$txn || !$txn->invoiceid) {
        return [false, 'Transaction not found or not linked to an invoice.'];
    }
    $inv = Capsule::table('tblinvoices')->where('id', $txn->invoiceid)->first();
    if (!$inv) {
        return [false, 'Invoice not found.'];
    }
    list($ok, $qboInvoice) = qbo_sync_invoice($inv->id);
    if (!$ok) {
        return [false, $qboInvoice];
    }
    list($ok, $qboCustomer) = qbo_sync_customer($inv->userid);
    if (!$ok) {
        return [false, $qboCustomer];
    }

    $note = 'WHMCS transaction #' . $txn->id . ' (' . $txn->gateway . ')';
    if ((float) $txn->fees > 0) {
        // Gateway fees are recorded in the note; book them to an expense
        // account in QBO if you need them split out — QBO Payments have no
        // native fee field.
        $note .= ' — gateway fee: ' . number_format((float) $txn->fees, 2);
    }
    $payload = [
        'CustomerRef' => ['value' => $qboCustomer],
        'TotalAmt'    => (float) $txn->amountin,
        'TxnDate'     => date('Y-m-d', strtotime($txn->date)),
        'PrivateNote' => $note,
        'Line'        => [[
            'Amount'    => (float) $txn->amountin,
            'LinkedTxn' => [['TxnId' => $qboInvoice, 'TxnType' => 'Invoice']],
        ]],
    ];
    $method = qbo_rel_get('gateway_method', $txn->gateway);
    if ($method) {
        $payload['PaymentMethodRef'] = ['value' => $method];
    }
    $account = qbo_rel_get('gateway_account', $txn->gateway);
    if ($account) {
        $payload['DepositToAccountRef'] = ['value' => $account];
    }

    list($code, $resp) = qbo_create('payment', $payload, 'whmcs-payment-' . $txn->id);
    if ($code !== 200 || empty($resp['Payment']['Id'])) {
        return [false, qbo_fault($resp, $code)];
    }
    qbo_map_set('payment', $transactionid, $resp['Payment']['Id']);
    return [true, $resp['Payment']['Id']];
}

/* ------------------------------------------------------------ sync: refund */

function qbo_sync_refund($invoiceid)
{
    if ($id = qbo_map_get('creditmemo', $invoiceid)) {
        return [true, $id];
    }
    $inv = Capsule::table('tblinvoices')->where('id', (int) $invoiceid)->first();
    if (!$inv) {
        return [false, 'Invoice not found.'];
    }
    list($ok, $qboCustomer) = qbo_sync_customer($inv->userid);
    if (!$ok) {
        return [false, $qboCustomer];
    }
    $lines = [];
    foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $inv->id)->get() as $item) {
        $itemId = qbo_item_for($item->type);
        if (!$itemId) {
            return [false, 'Could not resolve a QBO item.'];
        }
        $lines[] = [
            'Description' => $item->description,
            'Amount'      => (float) $item->amount,
            'DetailType'  => 'SalesItemLineDetail',
            'SalesItemLineDetail' => ['ItemRef' => ['value' => $itemId], 'Qty' => 1, 'UnitPrice' => (float) $item->amount],
        ];
    }
    list($code, $resp) = qbo_create('creditmemo', [
        'CustomerRef' => ['value' => $qboCustomer],
        'TxnDate'     => date('Y-m-d'),
        'Line'        => $lines,
        'PrivateNote' => 'Refund for WHMCS invoice #' . $inv->id,
    ], 'whmcs-creditmemo-' . $inv->id);
    if ($code !== 200 || empty($resp['CreditMemo']['Id'])) {
        return [false, qbo_fault($resp, $code)];
    }
    qbo_map_set('creditmemo', $invoiceid, $resp['CreditMemo']['Id']);
    return [true, $resp['CreditMemo']['Id']];
}

/* ------------------------------------------------------------------- queue */

function qbo_enqueue($entity, $whmcsId, $action)
{
    $exists = Capsule::table('mod_qbo_queue')
        ->where('entity', $entity)->where('whmcs_id', (string) $whmcsId)
        ->whereIn('status', ['pending', 'processing'])->exists();
    if ($exists || qbo_map_get($entity === 'refund' ? 'creditmemo' : $entity, $whmcsId)) {
        return;
    }
    Capsule::table('mod_qbo_queue')->insert([
        'entity' => $entity, 'whmcs_id' => (string) $whmcsId, 'action' => $action,
        'status' => 'pending', 'attempts' => 0,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Process queued sync jobs. Recovers stale processing jobs, stops the batch
 * on 429 (jobs return to pending without consuming attempts).
 * Returns [done, failed, rate_limited].
 */
function qbo_process_queue($limit = 25)
{
    // Recover jobs orphaned by a crashed worker.
    Capsule::table('mod_qbo_queue')->where('status', 'processing')
        ->where('updated_at', '<=', date('Y-m-d H:i:s', strtotime('-10 minutes')))
        ->update(['status' => 'pending', 'updated_at' => date('Y-m-d H:i:s')]);

    $jobs = Capsule::table('mod_qbo_queue')->where('status', 'pending')
        ->orderBy('id')->limit((int) $limit)->get();
    $done = 0;
    $failed = 0;
    $rateLimited = false;
    foreach ($jobs as $job) {
        Capsule::table('mod_qbo_queue')->where('id', $job->id)
            ->update(['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')]);
        switch ($job->entity) {
            case 'customer':
                list($ok, $res) = qbo_sync_customer($job->whmcs_id);
                break;
            case 'customer_update':
                list($ok, $res) = qbo_update_customer($job->whmcs_id);
                break;
            case 'invoice':
                list($ok, $res) = qbo_sync_invoice($job->whmcs_id);
                break;
            case 'payment':
                list($ok, $res) = qbo_sync_payment($job->whmcs_id);
                break;
            case 'refund':
                list($ok, $res) = qbo_sync_refund($job->whmcs_id);
                break;
            default:
                $ok = false;
                $res = 'Unknown entity';
        }
        if ($ok) {
            Capsule::table('mod_qbo_queue')->where('id', $job->id)
                ->update(['status' => 'done', 'message' => '', 'updated_at' => date('Y-m-d H:i:s')]);
            qbo_log('sync', $job->entity, $job->whmcs_id, $res, 'success', '');
            $done++;
            continue;
        }
        if (qbo_is_rate_limited($res)) {
            Capsule::table('mod_qbo_queue')->where('id', $job->id)
                ->update(['status' => 'pending', 'message' => 'rate limited', 'updated_at' => date('Y-m-d H:i:s')]);
            qbo_log('sync', $job->entity, $job->whmcs_id, '', 'failed', 'QBO rate limit hit — batch paused');
            $rateLimited = true;
            break;
        }
        $attempts = $job->attempts + 1;
        Capsule::table('mod_qbo_queue')->where('id', $job->id)->update([
            'status' => $attempts >= 5 ? 'failed' : 'pending',
            'message' => is_string($res) ? substr($res, 0, 240) : json_encode($res),
            'attempts' => $attempts,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        qbo_log('sync', $job->entity, $job->whmcs_id, '', 'failed', (string) $res);
        $failed++;
    }
    return [$done, $failed, $rateLimited];
}

/* --------------------------------------------------------------------- log */

function qbo_log($action, $entity, $whmcsId, $qboId, $status, $message)
{
    Capsule::table('mod_qbo_log')->insert([
        'action'    => $action,
        'entity'    => $entity,
        'whmcs_id'  => (string) $whmcsId,
        'qbo_id'    => (string) $qboId,
        'status'    => $status,
        'message'   => is_string($message) ? $message : json_encode($message),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
