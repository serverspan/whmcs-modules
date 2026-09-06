{if $configured}
<div class="alert alert-success"><strong>RadiusSphere service active.</strong><br>RADIUS username: {$username}</div>
{else}
<div class="alert alert-info">{$state}</div>
{/if}
