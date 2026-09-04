{* ServerSpan Identity Verification (Didit) - https://www.serverspan.com *}
{* Location: modules/addons/diditkyc/templates/kyc.tpl *}
<div class="row justify-content-center">
    <div class="col-md-6">

        {if $error}
            <div class="alert alert-danger">{$error}</div>
        {/if}

        <div class="card">
            <div class="card-body text-center">
                <h3 class="card-title">{$addonLang.kyc_page_title|default:'Identity Verification'}</h3>

                {if $approved}
                    <div style="font-size:48px;color:#5cb85c;margin:15px 0">&#10004;</div>
                    <p class="lead">{$addonLang.kyc_approved|default:'Your identity has been verified.'}</p>
                    <p class="text-muted">{$addonLang.kyc_approved_text|default:'No further action is needed.'}</p>

                {elseif $isReview}
                    <div class="alert alert-warning">{$addonLang.kyc_review|default:'Your verification is under manual review. We will notify you when it completes.'}</div>

                {elseif $isPending}
                    <p>{$addonLang.kyc_pending|default:'Your verification session is waiting for you.'}</p>
                    <p><span class="label label-info">{$status}</span></p>
                    {if $sessionUrl}
                        <a class="btn btn-primary btn-lg" href="{$sessionUrl}">
                            {$addonLang.kyc_continue|default:'Continue Verification'}
                        </a>
                    {/if}

                {elseif $canRestart}
                    <div class="alert alert-warning">
                        {$addonLang.kyc_notapproved|default:'Your previous verification was not approved or has expired.'}
                        ({$status})
                    </div>
                    <form method="post" action="index.php?m=diditkyc">
                        <input type="hidden" name="token" value="{$token}">
                        <input type="hidden" name="didit_do" value="start">
                        <button type="submit" class="btn btn-primary btn-lg">
                            {$addonLang.kyc_retry|default:'Try Again'}
                        </button>
                    </form>

                {else}
                    <p>{$addonLang.kyc_intro|default:'To keep your account secure we need to verify your identity. The check takes under a minute: a photo of your ID and a quick selfie.'}</p>
                    <form method="post" action="index.php?m=diditkyc">
                        <input type="hidden" name="token" value="{$token}">
                        <input type="hidden" name="didit_do" value="start">
                        <button type="submit" class="btn btn-primary btn-lg">
                            {$addonLang.kyc_start|default:'Start Verification'}
                        </button>
                    </form>
                    <p class="text-muted" style="margin-top:12px">
                        {$addonLang.kyc_provider_note|default:'Verification is powered by Didit. By continuing you agree to the processing of your identity documents for verification purposes.'}
                    </p>
                {/if}
            </div>
        </div>

    </div>
</div>
