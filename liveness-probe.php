<?php

/*
 * Checks how recently a ping() has been called.
 * Useful as a Kubernetes liveness probe.
 *
 * Usage: php path/to/liveness-probe.php [timeout]
 *
 * timeout - how old, in seconds, the ping file can be.
 *           Default is 60 seconds
 */

$fileName = '/tmp/allegro.ping';
$timeout = $argv[1] ?? 60;

if (!file_exists($fileName)) {
    echo "MISSING\n";
    exit(1);
}

$timestamp = filemtime($fileName);
if ($timestamp === false) {
    echo "FAILURE TIMESTAMP\n";
    exit(2);
}

$fileTime = new DateTime("@$timestamp");

$cutoff = new DateTime();
$cutoff->modify("-$timeout seconds");

if ($fileTime < $cutoff) {
    echo "EXPIRED\n";
    exit(3);
}

echo "OK\n";
exit(0);
