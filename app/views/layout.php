<?php
/** @var string $template */
/** @var string $csrf */
/** @var string $version */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title>Bootstrap · Deploy a project</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<div class="glow" aria-hidden="true"></div>
<header class="shell-header">
    <a class="brand" href="./">
        <span class="brand-mark" aria-hidden="true">◈</span>
        <span class="brand-text">Bootstrap</span>
    </a>
    <span class="brand-sub">installer</span>
</header>
<main class="shell-main">
    <?php require $templateFile; ?>
</main>
<footer class="shell-footer">
    <span>Bootstrap v<?= e($version) ?></span>
    <span class="dot" aria-hidden="true">·</span>
    <span>Keys are issued by your BootStrapServer</span>
</footer>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
