<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

final class Renderer
{
    public static function dashboard(array $data): string
    {
        $modulelink = Support::e($data['modulelink']);
        $orders = $data['orders'];
        $shipments = $data['shipments'];
        $connection = $data['connection'];

        $html = self::style();
        $html .= '<div class="ssco-wrap"><div class="ssco-head"><div><h2>Colete Online</h2><p>Courier quotes, AWB creation and tracking from WHMCS orders.</p></div>';
        $html .= '<a class="btn btn-default" href="' . $modulelink . '&action=connection">API diagnostics</a></div>';
        if ($connection['ok']) {
            $balanceText = $connection['balance_text'] !== '' ? ' - Balance: ' . Support::e($connection['balance_text']) : '';
            $html .= '<div class="alert alert-success">Colete-Online API connected' . $balanceText . '.</div>';
        } else {
            $html .= '<div class="alert alert-warning">API not verified: ' . Support::e($connection['message']) . '</div>';
        }

        $html .= '<div class="ssco-grid"><section class="ssco-card"><h3>Recent WHMCS orders</h3><div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr><th>Order</th><th>Client</th><th>Date</th><th>Status</th><th>Shipments</th><th></th></tr></thead><tbody>';
        foreach ($orders as $order) {
            $client = trim((string) ($order['companyname'] ?: (($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? ''))));
            $html .= '<tr><td>#' . Support::e($order['ordernum']) . ' <small>(ID ' . (int) $order['id'] . ')</small></td><td>' . Support::e($client) . '</td><td>' . Support::e($order['date']) . '</td><td>' . Support::e($order['status']) . '</td><td>' . (int) $order['shipment_count'] . '</td><td><a class="btn btn-xs btn-primary" href="' . $modulelink . '&action=prepare&order=' . (int) $order['id'] . '">Ship</a></td></tr>';
        }
        if (!$orders) {
            $html .= '<tr><td colspan="6" class="text-muted">No WHMCS orders found.</td></tr>';
        }
        $html .= '</tbody></table></div></section>';

        $html .= '<section class="ssco-card"><h3>Recent shipments</h3><div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr><th>AWB</th><th>Order</th><th>Courier</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($shipments as $shipment) {
            $html .= '<tr><td>' . Support::e($shipment['awb'] ?: $shipment['unique_id']) . '</td><td>#' . Support::e($shipment['ordernum'] ?: $shipment['order_id']) . '</td><td>' . Support::e(trim(($shipment['courier_name'] ?? '') . ' ' . ($shipment['service_name'] ?? ''))) . '</td><td>' . Support::e($shipment['last_status_text'] ?: 'Not refreshed') . '</td><td><a class="btn btn-xs btn-default" href="' . $modulelink . '&action=shipment&id=' . (int) $shipment['id'] . '">Open</a></td></tr>';
        }
        if (!$shipments) {
            $html .= '<tr><td colspan="5" class="text-muted">No Colete-Online shipments created yet.</td></tr>';
        }
        $html .= '</tbody></table></div></section></div></div>';
        return $html;
    }

    public static function prepare(array $data): string
    {
        $o = $data['order'];
        $v = $data['values'];
        $addresses = $data['addresses'];
        $offers = $data['offers'];
        $modulelink = Support::e($data['modulelink']);
        $existing = $data['existing'];

        $html = self::style();
        $html .= '<div class="ssco-wrap"><div class="ssco-head"><div><h2>Create shipment - WHMCS order #' . Support::e($o['ordernum']) . '</h2><p>Order ID ' . (int) $o['id'] . ' - ' . Support::e($o['currency_code']) . ' ' . Support::e($o['amount']) . '</p></div><a class="btn btn-default" href="' . $modulelink . '">Back</a></div>';
        if ($existing) {
            $html .= '<div class="alert alert-info">This WHMCS order already has ' . count($existing) . ' Colete-Online shipment(s). Creating another shipment requires explicit confirmation below.</div>';
        }
        if (!empty($data['notice'])) {
            $html .= '<div class="alert alert-' . Support::e($data['notice_type'] ?? 'info') . '">' . Support::e($data['notice']) . '</div>';
        }

        $html .= '<form method="post" action="' . $modulelink . '&action=prepare&order=' . (int) $o['id'] . '">' . Support::csrfInput() . '<input type="hidden" name="order_id" value="' . (int) $o['id'] . '">';
        $html .= '<div class="ssco-grid">';
        $html .= '<section class="ssco-card"><h3>Sender</h3>';
        if ($addresses) {
            $html .= '<div class="form-group"><label>Saved Colete-Online sender address</label><select class="form-control" name="sender_address_id" required><option value="">Select sender...</option>';
            foreach ($addresses as $address) {
                $selected = ((string) $address['id'] === (string) ($v['sender_address_id'] ?? '')) ? ' selected' : '';
                $html .= '<option value="' . Support::e($address['id']) . '"' . $selected . '>' . Support::e($address['label']) . '</option>';
            }
            $html .= '</select></div>';
        } else {
            $html .= self::input('Sender address ID', 'sender_address_id', $v['sender_address_id'] ?? '', true, 'number');
        }
        $html .= self::input('Sender shipping point ID (optional)', 'sender_shipping_point_id', $v['sender_shipping_point_id'] ?? '');
        $html .= '<p class="help-block">Sender addresses are managed in your Colete-Online account. A shipping-point ID is only needed for supported locker/fixed-point services.</p></section>';

        $html .= '<section class="ssco-card"><h3>Recipient</h3>';
        $html .= self::input('Name', 'recipient_name', $v['recipient_name'] ?? '', true);
        $html .= self::input('Company', 'company', $v['company'] ?? '');
        $html .= '<div class="row"><div class="col-sm-6">' . self::input('Phone', 'phone', $v['phone'] ?? '', true) . '</div><div class="col-sm-6">' . self::input('Email', 'email', $v['email'] ?? '', false, 'email') . '</div></div>';
        $html .= '<div class="row"><div class="col-sm-3">' . self::input('Country', 'country', $v['country'] ?? 'RO', true) . '</div><div class="col-sm-5">' . self::input('City', 'city', $v['city'] ?? '', true) . '</div><div class="col-sm-4">' . self::input('County / State', 'county', $v['county'] ?? '', true) . '</div></div>';
        $html .= '<div class="row"><div class="col-sm-8">' . self::input('Street', 'street', $v['street'] ?? '') . '</div><div class="col-sm-4">' . self::input('Number', 'number', $v['number'] ?? '') . '</div></div>';
        $html .= '<div class="row"><div class="col-sm-5">' . self::input('Postal code', 'postal_code', $v['postal_code'] ?? '') . '</div><div class="col-sm-7">' . self::input('Recipient shipping point ID', 'recipient_shipping_point_id', $v['recipient_shipping_point_id'] ?? '') . '</div></div>';
        $html .= '<div class="form-group"><label>Address details</label><textarea class="form-control" name="additional_info" rows="2">' . Support::e($v['additional_info'] ?? '') . '</textarea></div>';
        $html .= '<div class="form-group"><label>Address validation</label><select class="form-control" name="validation_strategy"><option value="minimal"' . (($v['validation_strategy'] ?? '') === 'minimal' ? ' selected' : '') . '>minimal</option><option value="priceMinimal"' . (($v['validation_strategy'] ?? '') === 'priceMinimal' ? ' selected' : '') . '>priceMinimal</option></select></div></section>';
        $html .= '</div>';

        $html .= '<section class="ssco-card"><h3>Package</h3><div class="row"><div class="col-sm-3"><div class="form-group"><label>Type</label><select class="form-control" name="package_type"><option value="2"' . ((int) ($v['package_type'] ?? 2) === 2 ? ' selected' : '') . '>Parcel / box</option><option value="1"' . ((int) ($v['package_type'] ?? 2) === 1 ? ' selected' : '') . '>Envelope</option></select></div></div><div class="col-sm-9">' . self::input('Contents', 'content', $v['content'] ?? 'Products', true) . '</div></div>';
        $weights = is_array($v['weight'] ?? null) ? $v['weight'] : [$v['weight'] ?? '1'];
        $heights = is_array($v['height'] ?? null) ? $v['height'] : [$v['height'] ?? '10'];
        $widths = is_array($v['width'] ?? null) ? $v['width'] : [$v['width'] ?? '10'];
        $lengths = is_array($v['length'] ?? null) ? $v['length'] : [$v['length'] ?? '10'];
        $rows = max(1, count($weights));
        $html .= '<div id="ssco-packages">';
        for ($i = 0; $i < $rows; $i++) {
            $html .= self::packageRow($weights[$i] ?? '', $heights[$i] ?? '', $widths[$i] ?? '', $lengths[$i] ?? '', $i > 0);
        }
        $html .= '</div><button type="button" class="btn btn-default btn-sm" onclick="sscoAddPackage()">Add package</button></section>';

        $html .= '<section class="ssco-card"><h3>Extra services</h3><div class="ssco-grid ssco-grid-3">';
        $html .= '<div><label><input type="checkbox" name="open_at_delivery" value="1"' . (Support::isOn($v['open_at_delivery'] ?? null) ? ' checked' : '') . '> Open at delivery</label><br><label><input type="checkbox" name="saturday_delivery" value="1"' . (Support::isOn($v['saturday_delivery'] ?? null) ? ' checked' : '') . '> Saturday delivery</label><br><label><input type="checkbox" name="saturday_mandatory" value="1"' . (Support::isOn($v['saturday_mandatory'] ?? null) ? ' checked' : '') . '> Saturday-capable service mandatory</label></div>';
        $html .= '<div>' . self::input('Insurance amount', 'insurance_amount', $v['insurance_amount'] ?? '0', false, 'number', '0.01') . self::input('Declared value', 'declared_value', $v['declared_value'] ?? '0', false, 'number', '0.01') . '</div>';
        $html .= '<div>' . self::input('COD to bank account', 'account_repayment_amount', $v['account_repayment_amount'] ?? '0', false, 'number', '0.01') . self::input('Account holder', 'account_holder_name', $v['account_holder_name'] ?? '') . self::input('IBAN / bank account', 'bank_account', $v['bank_account'] ?? '') . self::input('Cash repayment COD', 'cash_repayment_amount', $v['cash_repayment_amount'] ?? '0', false, 'number', '0.01') . '</div>';
        $html .= '</div><div class="row"><div class="col-sm-4">' . self::input('Pickup date', 'pickup_date', $v['pickup_date'] ?? '', false, 'date') . '</div><div class="col-sm-4">' . self::input('From', 'pickup_from', $v['pickup_from'] ?? '', false, 'time') . '</div><div class="col-sm-4">' . self::input('To', 'pickup_to', $v['pickup_to'] ?? '', false, 'time') . '</div></div>';
        $html .= self::input('Client reference', 'client_reference', $v['client_reference'] ?? ('WHMCS-' . $o['ordernum']));
        $html .= '<p class="help-block">Colete-Online forbids declared value together with insurance or repayment/COD. Non-RON WHMCS orders automatically include the API base-currency display option.</p></section>';

        if ($offers) {
            $html .= '<section class="ssco-card"><h3>Courier offers</h3><div class="table-responsive"><table class="table table-striped"><thead><tr><th></th><th>Courier</th><th>Service</th><th>Total</th><th>Without VAT</th></tr></thead><tbody>';
            foreach ($offers as $index => $offer) {
                $checked = ((string) ($v['service_id'] ?? '') === (string) $offer['id'] || (($v['service_id'] ?? '') === '' && $index === 0)) ? ' checked' : '';
                $html .= '<tr><td><input type="radio" name="service_id" value="' . Support::e($offer['id']) . '"' . $checked . '></td><td>' . Support::e($offer['courier']) . '</td><td>' . Support::e($offer['name']) . '</td><td>' . self::money($offer['total']) . ' RON</td><td>' . self::money($offer['no_vat']) . ' RON</td></tr>';
            }
            $html .= '</tbody></table></div></section>';
        }

        if ($existing) {
            $html .= '<label class="ssco-confirm"><input type="checkbox" name="confirm_additional" value="1"> I intentionally want an additional shipment for this WHMCS order.</label>';
        }
        $html .= '<div class="ssco-actions"><button class="btn btn-default" type="submit" name="do" value="quote">Get live courier offers</button>';
        if ($offers) {
            $html .= '<button class="btn btn-primary" type="submit" name="do" value="create">Create shipment / AWB</button>';
        }
        $html .= '</div></form></div>';
        $html .= '<script>function sscoAddPackage(){var box=document.getElementById("ssco-packages"),d=document.createElement("div");d.className="ssco-package-row row";d.innerHTML=' . json_encode(self::packageRow('', '', '', '', true), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';box.appendChild(d);}function sscoRemovePackage(btn){var row=btn.closest(".ssco-package-row");if(row)row.remove();}</script>';
        return $html;
    }

    public static function shipment(array $data): string
    {
        $s = $data['shipment'];
        $history = $data['history'];
        $modulelink = Support::e($data['modulelink']);
        $trackUrl = 'https://colete-online.ro/track/status/' . rawurlencode((string) $s['unique_id']);
        $awbUrl = (string) ($data['awb_url'] ?? '');

        $html = self::style() . '<div class="ssco-wrap"><div class="ssco-head"><div><h2>Shipment ' . Support::e($s['awb'] ?: $s['unique_id']) . '</h2><p>WHMCS order ID ' . (int) $s['order_id'] . ' - Colete-Online ID ' . Support::e($s['unique_id']) . '</p></div><a class="btn btn-default" href="' . $modulelink . '">Back</a></div>';
        if (!empty($data['notice'])) {
            $html .= '<div class="alert alert-' . Support::e($data['notice_type'] ?? 'info') . '">' . Support::e($data['notice']) . '</div>';
        }
        $html .= '<div class="ssco-grid"><section class="ssco-card"><h3>Shipment</h3><dl class="dl-horizontal"><dt>AWB</dt><dd>' . Support::e($s['awb'] ?: '-') . '</dd><dt>Courier</dt><dd>' . Support::e($s['courier_name'] ?: '-') . '</dd><dt>Service</dt><dd>' . Support::e($s['service_name'] ?: '-') . '</dd><dt>Price</dt><dd>' . ($s['price_total'] !== null ? Support::e($s['price_total']) . ' ' . Support::e($s['price_currency']) : '-') . '</dd><dt>Estimated pickup</dt><dd>' . Support::e($s['estimated_pickup_date'] ?: '-') . '</dd></dl><a class="btn btn-primary" target="_blank" rel="noopener" href="' . Support::e($awbUrl) . '">Download AWB</a> <a class="btn btn-default" target="_blank" rel="noopener" href="' . Support::e($trackUrl) . '">Public tracking</a></section>';
        $html .= '<section class="ssco-card"><h3>Latest status</h3><p class="ssco-status">' . Support::e($s['last_status_text'] ?: 'Tracking has not been refreshed yet.') . '</p><p class="text-muted">' . Support::e($s['last_status_at'] ?: '') . '</p><form method="post" action="' . $modulelink . '&action=shipment&id=' . (int) $s['id'] . '">' . Support::csrfInput() . '<input type="hidden" name="do" value="refresh"><button class="btn btn-default" type="submit">Refresh tracking</button></form></section></div>';

        $html .= '<section class="ssco-card"><h3>Tracking history</h3><div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr><th>Date</th><th>Code</th><th>Status</th><th>Comment</th></tr></thead><tbody>';
        foreach ($history as $item) {
            $html .= '<tr><td>' . Support::e($item['date_time']) . '</td><td>' . Support::e($item['code']) . '</td><td>' . Support::e($item['text']) . '</td><td>' . Support::e($item['comment']) . '</td></tr>';
        }
        if (!$history) {
            $html .= '<tr><td colspan="4" class="text-muted">No stored tracking history. Click Refresh tracking.</td></tr>';
        }
        $html .= '</tbody></table></div></section></div>';
        return $html;
    }

    public static function diagnostics(array $data): string
    {
        $modulelink = Support::e($data['modulelink']);
        $html = self::style() . '<div class="ssco-wrap"><div class="ssco-head"><div><h2>Colete-Online API diagnostics</h2><p>Safe connectivity information. Credentials and bearer tokens are never displayed.</p></div><a class="btn btn-default" href="' . $modulelink . '">Back</a></div>';
        if ($data['ok']) {
            $html .= '<div class="alert alert-success">Authentication and API request successful.</div>';
            $html .= '<section class="ssco-card"><dl class="dl-horizontal"><dt>Environment</dt><dd>' . Support::e($data['environment']) . '</dd><dt>Balance</dt><dd>' . Support::e($data['balance_text'] ?: 'Response received') . '</dd><dt>Saved senders</dt><dd>' . (int) $data['address_count'] . '</dd><dt>Courier services</dt><dd>' . (int) $data['service_count'] . '</dd></dl></section>';
        } else {
            $html .= '<div class="alert alert-danger">' . Support::e($data['message']) . '</div>';
        }
        return $html . '</div>';
    }

    public static function error(string $message, string $modulelink = ''): string
    {
        return self::style() . '<div class="ssco-wrap"><div class="alert alert-danger">' . Support::e($message) . '</div>' . ($modulelink !== '' ? '<a class="btn btn-default" href="' . Support::e($modulelink) . '">Back</a>' : '') . '</div>';
    }

    private static function input(string $label, string $name, mixed $value, bool $required = false, string $type = 'text', string $step = ''): string
    {
        return '<div class="form-group"><label>' . Support::e($label) . '</label><input class="form-control" type="' . Support::e($type) . '" name="' . Support::e($name) . '" value="' . Support::e($value) . '"' . ($required ? ' required' : '') . ($step !== '' ? ' step="' . Support::e($step) . '" min="0"' : '') . '></div>';
    }

    private static function packageRow(mixed $weight, mixed $height, mixed $width, mixed $length, bool $removable): string
    {
        return '<div class="ssco-package-row row"><div class="col-sm-2"><div class="form-group"><label>Weight kg</label><input class="form-control" name="weight[]" type="number" min="0.001" step="0.001" value="' . Support::e($weight) . '" required></div></div><div class="col-sm-2"><div class="form-group"><label>Height cm</label><input class="form-control" name="height[]" type="number" min="0" step="0.1" value="' . Support::e($height) . '"></div></div><div class="col-sm-2"><div class="form-group"><label>Width cm</label><input class="form-control" name="width[]" type="number" min="0" step="0.1" value="' . Support::e($width) . '"></div></div><div class="col-sm-2"><div class="form-group"><label>Length cm</label><input class="form-control" name="length[]" type="number" min="0" step="0.1" value="' . Support::e($length) . '"></div></div><div class="col-sm-2 ssco-remove">' . ($removable ? '<button type="button" class="btn btn-link text-danger" onclick="sscoRemovePackage(this)">Remove</button>' : '') . '</div></div>';
    }

    private static function money(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 2, '.', '');
    }

    private static function style(): string
    {
        return '<style>.ssco-wrap{max-width:1500px}.ssco-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:18px}.ssco-head h2{margin-top:0}.ssco-head p{color:#69727d}.ssco-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.ssco-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.ssco-card{border:1px solid #ddd;background:#fff;border-radius:5px;padding:16px;margin-bottom:16px}.ssco-card h3{margin-top:0;font-size:18px}.ssco-actions{display:flex;gap:10px;justify-content:flex-end;margin:18px 0}.ssco-confirm{display:block;padding:12px;background:#fff3cd;border:1px solid #ffe69c}.ssco-package-row{border-bottom:1px solid #eee;padding-top:6px}.ssco-package-row:last-child{border-bottom:0}.ssco-remove{padding-top:26px}.ssco-status{font-size:17px;font-weight:600}@media(max-width:900px){.ssco-grid,.ssco-grid-3{grid-template-columns:1fr}.ssco-head{display:block}.ssco-head>.btn{margin-top:8px}}</style>';
    }
}
