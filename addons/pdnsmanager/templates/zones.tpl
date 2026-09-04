{* ServerSpan PowerDNS Manager - https://www.serverspan.com *}
{* Location: modules/addons/pdnsmanager/templates/zones.tpl *}

{if $error}
    <div class="alert alert-danger">{$error}</div>
{/if}
{if $success}
    <div class="alert alert-success">{$success}</div>
{/if}

<div class="card">
    <div class="card-body">
        <h3 class="card-title">{$addonLang.zones_title|default:'Your DNS Zones'}</h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>{$addonLang.domain|default:'Domain'}</th>
                    <th>{$addonLang.actions|default:'Actions'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $domains as $d}
                <tr>
                    <td><code>{$d}</code></td>
                    <td style="white-space:nowrap">
                        <a class="btn btn-default btn-sm" href="index.php?m=pdnsmanager&domain={$d|urlencode}">
                            {$addonLang.manage|default:'Manage'}
                        </a>
                        <form method="post" action="index.php?m=pdnsmanager" style="display:inline">
                            <input type="hidden" name="token" value="{$token}">
                            <input type="hidden" name="pdns_do" value="create_zone">
                            <input type="hidden" name="domain" value="{$d}">
                            <button class="btn btn-default btn-sm">{$addonLang.create_zone|default:'Create Zone'}</button>
                        </form>
                        <button class="btn btn-default btn-sm pdns-nscheck" data-domain="{$d}">
                            {$addonLang.check_ns|default:'Check NS'}
                        </button>
                        <span class="pdns-ns-result" data-domain="{$d}"></span>
                    </td>
                </tr>
                {foreachelse}
                <tr><td colspan="2" class="text-center text-muted">
                    {$addonLang.no_domains|default:'You have no domains eligible for DNS management.'}
                </td></tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>

{literal}
<script>
(function () {
    document.querySelectorAll('.pdns-nscheck').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var domain = btn.getAttribute('data-domain');
            var out = document.querySelector('.pdns-ns-result[data-domain="' + domain + '"]');
            btn.disabled = true;
            out.textContent = '...';
            fetch('index.php?m=pdnsmanager&pdns_ajax=nscheck&domain=' + encodeURIComponent(domain),
                {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.disabled = false;
                    if (!data.ok) { out.textContent = 'error'; return; }
                    if (data.status === 'match') {
                        out.innerHTML = '<span class="label label-success">NS OK</span>';
                    } else if (data.status === 'mismatch') {
                        out.innerHTML = '<span class="label label-warning">NS: '
                            + data.live.join(', ') + '</span>';
                    } else {
                        out.innerHTML = '<span class="label label-default">NS lookup failed</span>';
                    }
                })
                .catch(function () { btn.disabled = false; out.textContent = 'error'; });
        });
    });
})();
</script>
{/literal}
