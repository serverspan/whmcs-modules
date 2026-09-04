{* ServerSpan PowerDNS Manager - https://www.serverspan.com *}
{* Location: modules/addons/pdnsmanager/templates/records.tpl *}

{if $error}
    <div class="alert alert-danger">{$error}</div>
{/if}
{if $success}
    <div class="alert alert-success">{$success}</div>
{/if}

<p><a href="index.php?m=pdnsmanager">&laquo; {$addonLang.back|default:'Back to zones'}</a></p>

<div class="card">
    <div class="card-body">
        <h3 class="card-title">{$domain} <small>{$addonLang.records|default:'DNS Records'}</small></h3>

        <table class="table table-striped table-condensed" id="pdnsRecords">
            <thead>
                <tr>
                    <th>{$addonLang.name|default:'Name'}</th>
                    <th>{$addonLang.type|default:'Type'}</th>
                    <th>TTL</th>
                    <th>{$addonLang.content|default:'Content'}</th>
                    <th>{$addonLang.actions|default:'Actions'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $records as $r}
                <tr class="pdns-rec" data-name="{$r.name}" data-type="{$r.type}" data-ttl="{$r.ttl}"
                    data-content="{$r.content|escape:'html'}">
                    <td><code>{$r.name}</code></td>
                    <td><span class="label label-{if $r.is_soa}danger{elseif $r.is_apex_ns}warning{else}primary{/if}">{$r.type}</span></td>
                    <td>{$r.ttl}</td>
                    <td style="word-break:break-all">{$r.content}</td>
                    <td style="white-space:nowrap">
                        {if $r.is_soa || $r.is_apex_ns}
                            <span class="text-muted">{$addonLang.protected|default:'Protected'}</span>
                        {else}
                            <button type="button" class="btn btn-xs btn-default pdns-edit">{$addonLang.edit|default:'Edit'}</button>
                            <form method="post" action="index.php?m=pdnsmanager" style="display:inline"
                                  onsubmit="return confirm('{$addonLang.confirm_delete|default:'Delete this record set?'}')">
                                <input type="hidden" name="token" value="{$token}">
                                <input type="hidden" name="pdns_do" value="delete_record">
                                <input type="hidden" name="domain" value="{$domain}">
                                <input type="hidden" name="name" value="{$r.name}">
                                <input type="hidden" name="type" value="{$r.type}">
                                <button class="btn btn-xs btn-danger">{$addonLang.delete|default:'Delete'}</button>
                            </form>
                        {/if}
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="card"><div class="card-body">
            <h4>{$addonLang.add_record|default:'Add / Edit Record Set'}</h4>
            <p class="text-muted">{$addonLang.record_hint|default:'One value per line. Saving replaces the full set for this name and type.'}</p>
            <form method="post" action="index.php?m=pdnsmanager" id="pdnsRecordForm">
                <input type="hidden" name="token" value="{$token}">
                <input type="hidden" name="pdns_do" value="save_record">
                <input type="hidden" name="domain" value="{$domain}">
                <div class="row">
                    <div class="col-md-5 form-group">
                        <label>{$addonLang.name|default:'Name'}</label>
                        <input type="text" name="name" id="pdnsName" class="form-control" placeholder="www or @">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>{$addonLang.type|default:'Type'}</label>
                        <select name="type" id="pdnsType" class="form-control">
                            {foreach $types as $t}<option>{$t}</option>{/foreach}
                        </select>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>TTL</label>
                        <input type="number" name="ttl" id="pdnsTtl" class="form-control" value="3600" min="60">
                    </div>
                </div>
                <div class="form-group">
                    <label>{$addonLang.content|default:'Content'}</label>
                    <textarea name="content" id="pdnsContent" class="form-control" rows="3" required></textarea>
                    <p class="text-muted" style="margin-top:4px">MX: <code>10 mail.example.com</code> ·
                        SRV: <code>0 5 5060 sip.example.com</code> ·
                        CAA: <code>0 issue "letsencrypt.org"</code> ·
                        TLSA: <code>3 1 1 &lt;hash&gt;</code></p>
                </div>
                <button class="btn btn-primary">{$addonLang.save|default:'Save Record'}</button>
            </form>
        </div></div>
    </div>

    <div class="col-md-5">
        <div class="card"><div class="card-body">
            <h4>{$addonLang.import_export|default:'Import / Export'}</h4>
            <p>
                <a class="btn btn-default" href="index.php?m=pdnsmanager&pdns_action=export&domain={$domain|urlencode}">
                    {$addonLang.export|default:'Export Zone File'}
                </a>
            </p>
            <form method="post" action="index.php?m=pdnsmanager">
                <input type="hidden" name="token" value="{$token}">
                <input type="hidden" name="pdns_do" value="import_zone">
                <input type="hidden" name="domain" value="{$domain}">
                <div class="form-group">
                    <textarea name="zonefile" class="form-control" rows="6"
                        placeholder="{$addonLang.import_hint|default:'Paste a BIND-format zone file here'}"></textarea>
                </div>
                <button class="btn btn-default">{$addonLang.import|default:'Import Zone'}</button>
            </form>
        </div></div>

        {if $dnssec}
        <div class="card"><div class="card-body">
            <h4>DNSSEC</h4>
            <p id="pdnsDsStatus">
                <button type="button" class="btn btn-default" id="pdnsDsCheck">
                    {$addonLang.dnssec_check|default:'Check DNSSEC Status'}
                </button>
            </p>
            <div id="pdnsDsRecords" style="display:none">
                <p class="text-muted">{$addonLang.ds_hint|default:'Add these DS records at your registrar:'}</p>
                <pre id="pdnsDsList" style="font-size:11px"></pre>
            </div>
            {if $signed}
                <form method="post" action="index.php?m=pdnsmanager" style="display:inline">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="pdns_do" value="dnssec_off">
                    <input type="hidden" name="domain" value="{$domain}">
                    <button class="btn btn-danger btn-sm">{$addonLang.dnssec_disable|default:'Disable DNSSEC'}</button>
                </form>
            {else}
                <form method="post" action="index.php?m=pdnsmanager" style="display:inline">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="pdns_do" value="dnssec_on">
                    <input type="hidden" name="domain" value="{$domain}">
                    <button class="btn btn-success btn-sm">{$addonLang.dnssec_enable|default:'Enable DNSSEC'}</button>
                </form>
            {/if}
        </div></div>
        {/if}
    </div>
</div>

{literal}
<script>
(function () {
    // Multi-value-safe edit: loads every value of the same name+type into the form.
    document.querySelectorAll('.pdns-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.pdns-rec');
            var name = row.getAttribute('data-name');
            var type = row.getAttribute('data-type');
            document.getElementById('pdnsName').value = name;
            document.getElementById('pdnsType').value = type;
            document.getElementById('pdnsTtl').value = row.getAttribute('data-ttl');
            var values = [];
            document.querySelectorAll('.pdns-rec[data-name="' + name + '"][data-type="' + type + '"]')
                .forEach(function (r) { values.push(r.getAttribute('data-content')); });
            document.getElementById('pdnsContent').value = values.join('\n');
            document.getElementById('pdnsRecordForm').scrollIntoView({behavior: 'smooth'});
        });
    });

    var check = document.getElementById('pdnsDsCheck');
    if (check) {
        check.addEventListener('click', function () {
            check.disabled = true;
            fetch('index.php?m=pdnsmanager&pdns_ajax=dnssec&domain={/literal}{$domain|urlencode}{literal}',
                {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    check.disabled = false;
                    if (data.ok && data.signed) {
                        document.getElementById('pdnsDsStatus').innerHTML =
                            '<span class="label label-success">Signed</span>';
                        if (data.ds && data.ds.length) {
                            document.getElementById('pdnsDsList').textContent = data.ds.join('\n');
                            document.getElementById('pdnsDsRecords').style.display = 'block';
                        }
                    } else {
                        document.getElementById('pdnsDsStatus').innerHTML =
                            '<span class="label label-default">Not signed</span>';
                    }
                })
                .catch(function () { check.disabled = false; });
        });
    }
})();
</script>
{/literal}
