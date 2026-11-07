<?php
/** @var string $backend_host */
/** @var string $owner */
?>
<section class="card card-narrow" aria-labelledby="login-title">
    <h1 id="login-title">Sign in to deploy</h1>
    <p class="lede">
        Enter the credentials issued by <strong><?= e($backend_host) ?></strong>.
        Your SSH key is decrypted in this browser session only.
    </p>

    <form id="login-form" autocomplete="on" novalidate>
        <label class="field">
            <span>Username</span>
            <input type="text" name="username" id="username" autocomplete="username"
                   autocapitalize="off" spellcheck="false" required>
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" id="password" autocomplete="current-password" required>
        </label>

        <button type="submit" class="btn btn-primary" id="login-submit">
            <span class="btn-label">Sign in</span>
            <span class="spinner" aria-hidden="true"></span>
        </button>

        <p class="form-error" id="login-error" role="alert" hidden></p>
    </form>

    <div class="meta-grid">
        <div><span class="meta-label">Backend</span><span class="meta-value"><?= e($backend_host) ?></span></div>
        <div><span class="meta-label">Repositories</span><span class="meta-value">github.com/<?= e($owner) ?></span></div>
    </div>
</section>
