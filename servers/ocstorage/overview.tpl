{* ServerSpan ownCloud Storage - https://www.serverspan.com *}
{* Location: modules/servers/ocstorage/overview.tpl *}
<div class="card">
    <div class="card-body">
        <h4>ownCloud Storage — {$username}</h4>
        <p>
            Status:
            {if $enabled}<span class="label label-success">Active</span>
            {else}<span class="label label-warning">Suspended</span>{/if}
        </p>
        {if $total !== '' && $total > 0}
            <p>Storage used: {$used} of {$total}</p>
            <div class="progress">
                <div class="progress-bar" role="progressbar"
                     style="width: {math equation="min(100, round(u / t * 100))" u=$used t=$total}%"></div>
            </div>
        {elseif $total !== ''}
            <p class="text-muted">Unlimited storage.</p>
        {/if}
        <a class="btn btn-primary" href="{$loginUrl}" target="_blank">Open ownCloud</a>
    </div>
</div>
