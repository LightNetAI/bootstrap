<?php
/** @var string $username */
/** @var string $fingerprint */
/** @var array $github */
/** @var string $owner */
/** @var string $backend_host */
/** @var bool $token_configured */
$sshOk = (bool) ($github['ok'] ?? false);
$account = (string) ($github['account'] ?? '');
$sshMessage = (string) ($github['message'] ?? '');
?>
<section class="dashboard" data-username="<?= e($username) ?>">
    <div class="card">
        <div class="row row-between">
            <div>
                <h1>Projects</h1>
                <p class="lede">
                    Signed in as <strong><?= e($username) ?></strong>
                    <?php if ($fingerprint !== ''): ?>
                        <span class="dot">·</span> <code class="key-fp"><?= e($fingerprint) ?></code>
                    <?php endif; ?>
                </p>
            </div>
            <button type="button" class="btn btn-ghost" id="logout">Sign out</button>
        </div>

        <?php if ($sshOk): ?>
            <div class="notice notice-ok" id="ssh-status">
                <span class="notice-icon" aria-hidden="true">✓</span>
                Connected to GitHub<?= $account !== '' ? ' as <strong>' . e($account) . '</strong>' : '' ?>.
            </div>
        <?php else: ?>
            <div class="notice notice-warn" id="ssh-status">
                <span class="notice-icon" aria-hidden="true">!</span>
                SSH check failed: <?= e($sshMessage) ?>
            </div>
        <?php endif; ?>

        <div class="notice notice-warn" id="deploy-warning" hidden></div>

        <div class="toolbar">
            <input type="search" id="repo-filter" class="input" placeholder="Filter projects…" autocomplete="off">
            <button type="button" class="btn btn-ghost" id="refresh-repos">Refresh</button>
        </div>

        <div id="repo-list" class="repo-grid" aria-live="polite">
            <div class="skeleton"></div>
            <div class="skeleton"></div>
            <div class="skeleton"></div>
        </div>

        <p class="muted small" id="repo-source"></p>
        <?php if (!$token_configured): ?>
            <p class="muted small">
                Only public repositories are listed. Add a <code>github_token</code> to config.php to include private ones.
            </p>
        <?php endif; ?>
    </div>
</section>

<div class="overlay" id="deploy-overlay" hidden>
    <div class="overlay-card" role="alertdialog" aria-labelledby="deploy-title" aria-describedby="deploy-desc">
        <div class="overlay-spinner" aria-hidden="true"></div>
        <h2 id="deploy-title">Deploying <span id="deploy-repo"></span></h2>
        <p id="deploy-desc" class="muted">Cloning the repository, swapping the web root and removing the installer. Keep this tab open.</p>
        <ul class="progress" id="deploy-progress">
            <li data-step="clone">Clone repository over SSH</li>
            <li data-step="stage">Prepare deployment</li>
            <li data-step="swap">Swap web root</li>
            <li data-step="cleanup">Remove bootstrap files</li>
        </ul>
    </div>
</div>

<div class="overlay" id="success-overlay" hidden>
    <div class="overlay-card success-card">
        <div class="success-mark" aria-hidden="true">✓</div>
        <h2>Deployment complete</h2>
        <p class="muted"><strong id="success-repo"></strong> is now live and the bootstrap files have been removed.</p>
        <a class="btn btn-primary" href="./" id="success-link">Open your site</a>
    </div>
</div>
