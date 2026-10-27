<?php

declare(strict_types=1);

// Included only by the real renderer, inside the exact same Bubblewrap/prlimit process.
// Never read denied file contents, emit diagnostics or try external services.
$manifest = json_decode(file_get_contents($workspace.'/probe.json'), true, flags: JSON_THROW_ON_ERROR);
$unreadable = static function (string $path): bool {
    $handle = @fopen($path, 'rb');
    if (is_resource($handle)) {
        fclose($handle);

        return false;
    }

    return true;
};
$hidden = static function (string $path) use ($unreadable): bool {
    clearstatcache(true, $path);
    $visible = @lstat($path) !== false;

    return $unreadable($path) && ! $visible;
};
$allHidden = static function (array $paths) use ($hidden): bool {
    $ok = true;
    foreach ($paths as $path) {
        $ok = $hidden($path) && $ok;
    }

    return $ok;
};
$networkNamespace = @readlink('/proc/self/ns/net');
$socket = @stream_socket_client($manifest['network_address'], $errno, $error, 0.5);
$networkDenied = $socket === false;
if (is_resource($socket)) {
    fclose($socket);
}
$devices = @file_get_contents('/proc/net/dev');
preg_match_all('/^\s*([a-zA-Z0-9_.-]+):/m', (string) $devices, $interfaces);
$limits = (string) @file_get_contents('/proc/self/limits');
$limited = preg_match('/Max cpu time\s+(\d+)\s+(\d+)\s+seconds/', $limits, $cpu)
    && (int) $cpu[1] === $manifest['cpu_seconds'] && (int) $cpu[2] === $manifest['cpu_seconds']
    && preg_match('/Max file size\s+12582912\s+12582912\s+bytes/', $limits)
    && preg_match('/Max address space\s+335544320\s+335544320\s+bytes/', $limits);
$checks = [
    'workspace_readable' => is_readable($workspace.'/snapshot.enc'),
    'network_namespace' => is_string($networkNamespace) && $networkNamespace !== $manifest['network_namespace'],
    'network_denied' => $networkDenied,
    'network_interfaces' => $devices !== false && $interfaces[1] === ['lo'],
    'env_hidden' => $allHidden($manifest['env_paths']),
    'private_hidden' => $allHidden($manifest['private_paths']),
    'canaries_hidden' => $allHidden($manifest['canary_paths']),
    // lstat intentionally sees this link; only its external target must be inaccessible.
    'symlink_denied' => is_link($workspace.'/escape') && ! is_readable($workspace.'/escape') && $unreadable($workspace.'/escape'),
    'database_unbound' => ! $app->bound('db') && ! $app->bound('queue'),
    'resource_limits' => (bool) $limited,
];
if (file_put_contents($workspace.'/isolation.json', json_encode($checks, JSON_THROW_ON_ERROR)) === false || in_array(false, $checks, true)) {
    throw new RuntimeException('sandbox_check_failed');
}
