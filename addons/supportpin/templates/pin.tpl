{* ServerSpan Support PIN - https://www.serverspan.com *}
{* Location: modules/addons/supportpin/templates/pin.tpl *}
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body text-center">
                <h3 class="card-title">{$LANG.pin_page_title|default:'Your Support PIN'}</h3>
                <p>{$LANG.pin_intro|default:'Give this PIN to our support team when they ask you to verify your identity.'}</p>

                <div id="pinDisplay" style="margin:20px 0">
                    {if $hasPin && !$isUsed && !$isExpired}
                        <span id="pinValue" style="font-size:36px;font-weight:bold;letter-spacing:10px">{$pin}</span>
                        {if $expiresAt}
                            <p class="text-muted" style="margin-top:8px">
                                {$LANG.pin_expires|default:'Expires at'}: <span id="pinExpiry">{$expiresAt}</span>
                            </p>
                        {/if}
                        {if $oneTime}
                            <p class="text-muted">{$LANG.pin_onetime|default:'This PIN works once. After it is used you will need a new one.'}</p>
                        {/if}
                    {elseif $isUsed}
                        <div class="alert alert-warning">{$LANG.pin_used|default:'Your PIN has been used. Generate a new one below.'}</div>
                    {elseif $isExpired}
                        <div class="alert alert-warning">{$LANG.pin_expired|default:'Your PIN has expired. Generate a new one below.'}</div>
                    {else}
                        <div class="alert alert-info">{$LANG.pin_none|default:'You have no PIN yet. Generate one below.'}</div>
                    {/if}
                </div>

                <form method="post" action="index.php?m=supportpin" id="pinGenerateForm">
                    <input type="hidden" name="pin_action" value="generate">
                    <button type="submit" class="btn btn-primary" id="pinGenerateBtn">
                        {if $hasPin}{$LANG.pin_regenerate|default:'Generate New PIN'}{else}{$LANG.pin_generate|default:'Generate PIN'}{/if}
                    </button>
                </form>
                <p class="text-muted" style="margin-top:12px">
                    {$LANG.pin_note|default:'Generating a new PIN invalidates the previous one immediately.'}
                </p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('pinGenerateForm');
    if (!form || !window.fetch) { return; }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('pinGenerateBtn');
        btn.disabled = true;
        fetch('index.php?m=supportpin', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams({pin_action: 'generate', ajax: '1'})
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            if (!data.ok) { form.submit(); return; }
            var display = document.getElementById('pinDisplay');
            var html = '<span id="pinValue" style="font-size:36px;font-weight:bold;letter-spacing:10px">'
                + data.pin + '</span>';
            if (data.expiresAt) {
                html += '<p class="text-muted" style="margin-top:8px">Expires at: ' + data.expiresAt + '</p>';
            }
            display.innerHTML = html;
        })
        .catch(function () { form.submit(); });
    });
})();
</script>
