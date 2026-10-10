#!/bin/bash
set -euo pipefail

# Validate before Artisan, migrations, cron, Horizon, or PHP-FPM can start.
# The gate is issued by verify-frozen-upload-release.py after testing the exact
# candidate and rebuilding its frontend. It lives in the existing storage volume.
gate_path=/var/www/html/storage/app/.release-gates
gate_root=/
if [[ "${1:-}" == "--check-release-only" ]]; then
  gate_path="${2:-$gate_path}"
  gate_root="${MIXPOST_RELEASE_CHECK_ROOT:-/}"
fi
php /dev/stdin "$gate_path" "$gate_root" <<'UPLOAD_RELEASE_GATE_PHP'
<?php
function blocked(string $reason): never { throw new RuntimeException($reason); }
function verifyGate(string $gatePath, string $root): void {
try {
    $gate = json_decode(file_get_contents($gatePath), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    blocked('missing or invalid upload gate');
}
if (($gate['schema'] ?? null) !== 1 || ($gate['status'] ?? null) !== 'passed'
    || ($gate['contract'] ?? null) !== 'video-upload-recovery-v1'
    || !preg_match('/^sha256:[0-9a-f]{64}$/', $gate['imageId'] ?? '')) {
    blocked('unsupported or incomplete upload contract');
}
foreach (['frontend-network' => 13, 'frontend-queue' => 3, 'backend-recovery' => 12, 'assets-build' => 1] as $name => $count) {
    $suite = $gate['suites'][$name] ?? [];
    if (($suite['exitCode'] ?? null) !== 0 || ($suite['checks'] ?? 0) < $count
        || !preg_match('/^[0-9a-f]{64}$/', $suite['testSha256'] ?? '')
        || !preg_match('/^[0-9a-f]{64}$/', $suite['logSha256'] ?? '')) {
        blocked("required suite $name did not pass");
    }
}
$pro = '/var/www/html/vendor/inovector/mixpost-pro-team/';
$assets = '/var/www/html/public/vendor/mixpost/';
$required = array_map(fn($file) => $pro.$file, [
    'resources/js/bootstrap.js', 'resources/js/Composables/useNotifications.js',
    'resources/js/Composables/useChunkedUpload.js', 'resources/js/Components/Media/UploadMedia.vue',
    'resources/js/Components/Media/UploadProgressPanel.vue', 'resources/js/Components/Media/UploadProgressItem.vue',
    'src/Support/ChunkedUpload.php', 'package.json', 'package-lock.json', 'vite.config.js',
]);
$required[] = '/var/www/html/composer.lock';
$required[] = '/usr/local/bin/peachy-start.sh';
$required[] = $assets.'manifest.json';
$files = $gate['files'] ?? [];
if (!is_array($files) || !$files || empty($gate['mountInventory'])) blocked('missing file or mount inventory');
foreach ($gate['mountInventory'] as $mount) {
    if (($mount['Type'] ?? '') === 'bind') {
        if (($mount['RW'] ?? true) !== false) blocked('writable override mount');
        $required[] = $mount['Destination'];
    }
}
foreach ($required as $file) if (!isset($files[$file])) blocked("missing fingerprint for $file");
foreach ($files as $file => $expected) {
    if (!str_starts_with($file, '/') || str_contains($file, '..')
        || !is_string($expected) || !preg_match('/^[0-9a-f]{64}$/', $expected)
        || !is_file($root.$file) || !hash_equals($expected, hash_file('sha256', $root.$file))) {
        blocked("untested or changed file $file");
    }
}
try {
    $manifest = json_decode(file_get_contents($root.$assets.'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $error) { blocked('invalid published manifest'); }
if (empty($manifest['resources/js/app.js'])) blocked('missing app entrypoint');
foreach ($manifest as $entry) {
    foreach (['file', 'css', 'assets'] as $field) {
        $values = $entry[$field] ?? [];
        foreach (is_string($values) ? [$values] : $values as $name) {
            if (!isset($files[$assets.$name])) blocked("unverified compiled asset $name");
        }
    }
    foreach (['imports', 'dynamicImports'] as $field) {
        foreach ($entry[$field] ?? [] as $key) if (!isset($manifest[$key])) blocked("missing asset import $key");
    }
}
}
$root = rtrim($argv[2], '/');
$paths = is_dir($argv[1]) ? glob($argv[1].'/*.json') : [$argv[1]];
$reason = 'missing upload gate';
foreach ($paths as $path) {
    try {
        verifyGate($path, $root);
        fwrite(STDOUT, "Mixpost upload release gate passed\n");
        exit(0);
    } catch (Throwable $error) { $reason = $error->getMessage(); }
}
fwrite(STDERR, "Mixpost release blocked: $reason. Run the frozen upload release validator.\n");
exit(1);
UPLOAD_RELEASE_GATE_PHP
if [[ "${1:-}" == "--check-release-only" ]]; then
  exit 0
fi

ensure_laravel_log_permissions() {
  install -d -o www-data -g www-data -m 775 /var/www/html/storage/logs
  touch /var/www/html/storage/logs/laravel.log
  chown www-data:www-data /var/www/html/storage/logs/laravel.log
  chmod 664 /var/www/html/storage/logs/laravel.log
}

# The image starts as root and runs Artisan during boot. Pre-create Laravel's
# log for PHP-FPM so those commands cannot leave a root-only file behind.
ensure_laravel_log_permissions

exec /usr/local/bin/start.sh
