<?php

// Run as www-data. Exercise the installed Pro temporary-directory implementation
// without booting Laravel or accessing the database, S3, or existing user sessions.
require '/var/www/html/vendor/autoload.php';
if (posix_geteuid() === 0) {
    fwrite(STDERR, "FAIL: media permission checks must run as the application user\n");
    exit(1);
}
$directory = null;
try {
    // Supply a name explicitly: randomSafe is registered during Laravel boot,
    // which this isolated filesystem check deliberately does not invoke.
    $directory = (new Inovector\Mixpost\Support\TemporaryDirectory())
        ->name('ops-permissions-'.bin2hex(random_bytes(16)))
        ->location('/var/www/html/storage/mixpost-media/temp')
        ->create();
    $path = $directory->path('probe.txt');
    if (file_put_contents($path, 'verified') !== 8 || file_get_contents($path) !== 'verified') {
        throw new RuntimeException('Media temporary file write/read failed');
    }
    if (!is_writable('/var/www/html/storage/mixpost-media/temp/chunked')) {
        throw new RuntimeException('Chunked upload parent is not writable');
    }
} finally {
    $directory?->delete();
}
fwrite(STDOUT, "PASS media temporary create/write/read/cleanup as application user\n");
