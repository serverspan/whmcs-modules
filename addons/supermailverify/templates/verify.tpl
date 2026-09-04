{* ServerSpan Super Email Verification - https://www.serverspan.com *}
{* Location: modules/addons/supermailverify/templates/verify.tpl *}
<div class="row justify-content-center">
    <div class="col-md-6">

        {if $error}
            <div class="alert alert-danger">{$error}</div>
        {/if}
        {if $success}
            <div class="alert alert-success">{$success}</div>
        {/if}

        {if !$isVerified}
        <div class="card">
            <div class="card-body">
                <h3 class="card-title">{$addonLang.sev_page_title|default:'Email Verification'}</h3>
                <p>{$addonLang.sev_intro|default:'Enter the verification code we sent to your email address.'}</p>

                <form method="post" action="index.php?m=supermailverify">
                    <input type="hidden" name="sev_do" value="verify">

                    <div class="form-group">
                        <label for="sevEmail">{$addonLang.sev_email|default:'Email address'}</label>
                        <input type="email" name="email" id="sevEmail" class="form-control"
                               value="{$email}" {if $emailLocked}readonly{/if} required>
                    </div>

                    <div class="form-group">
                        <label for="sevCode">{$addonLang.sev_code|default:'Verification code'}</label>
                        <input type="text" name="code" id="sevCode" class="form-control"
                               autocomplete="one-time-code" required autofocus>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        {$addonLang.sev_verify_button|default:'Verify Email'}
                    </button>
                </form>

                <hr>
                <form method="post" action="index.php?m=supermailverify">
                    <input type="hidden" name="sev_do" value="resend">
                    <input type="hidden" name="email" value="{$email}">
                    <button type="submit" class="btn btn-default btn-block">
                        {$addonLang.sev_resend|default:'Resend Verification Code'}
                    </button>
                </form>
            </div>
        </div>
        {else}
        <div class="card">
            <div class="card-body text-center">
                <h3 class="card-title">{$addonLang.sev_verified_title|default:'Email verified'}</h3>
                <p>{$addonLang.sev_verified_text|default:'Thank you. Your email address has been confirmed.'}</p>
                <a href="index.php" class="btn btn-primary">{$addonLang.sev_continue|default:'Continue'}</a>
            </div>
        </div>
        {/if}

    </div>
</div>
