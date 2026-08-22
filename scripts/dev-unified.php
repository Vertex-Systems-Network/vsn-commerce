<?php
/** Run Laravel and Vite from one merged project without extra npm packages. */
$windows = PHP_OS_FAMILY === 'Windows';
$npm = $windows ? 'npm.cmd' : 'npm';
$commands = [
    ['php', 'artisan', 'serve', '--host=127.0.0.1', '--port=8000'],
    [$npm, 'run', 'dev:vite'],
];
$processes = [];
foreach ($commands as $command) {
    $proc = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, getcwd(), null, ['bypass_shell' => true]);
    if (! is_resource($proc)) {
        fwrite(STDERR, "Unable to start: ".implode(' ', $command).PHP_EOL);
        foreach ($processes as $p) @proc_terminate($p);
        exit(1);
    }
    $processes[] = $proc;
}
register_shutdown_function(/** Inline callback for this operation. */ function () use (&$processes): void {
    foreach ($processes as $proc) if (is_resource($proc)) @proc_terminate($proc);
});
echo "VSN unified development server:\n  Laravel + React: http://localhost:8000\n  Vite HMR:        http://localhost:5173\n\nPress Ctrl+C to stop.\n";
while (true) {
    foreach ($processes as $proc) {
        $status = proc_get_status($proc);
        if (! $status['running']) exit((int) $status['exitcode']);
    }
    usleep(300000);
}
