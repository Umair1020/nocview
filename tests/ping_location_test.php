<?php
// Run with: php tests/ping_location_test.php
require_once __DIR__ . '/../router_poll.php';

function checkPing($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$reply = parsePingLocationResult(0, '64 bytes from 127.0.0.1: icmp_seq=1 ttl=64 time=12.3 ms');
checkPing($reply['status'] === 'up' && $reply['ping'] === 12.3, 'Linux reply');
$reply = parsePingLocationResult(0, 'Reply from 127.0.0.1: bytes=32 time<1ms TTL=128');
checkPing($reply['status'] === 'up' && $reply['ping'] === 1.0, 'Windows reply');
foreach ([[1, '100% packet loss'], [0, 'Request timed out'], [127, 'ping: not found']] as $failure) {
    $result = parsePingLocationResult($failure[0], $failure[1]);
    checkPing($result['status'] === 'unknown' && $result['ping'] === null, 'No reply is unknown');
}
checkPing(pollPingLocation('127.0.0.1; echo unsafe')['reason'] === 'invalid_ip', 'Reject shell input');
echo "Ping location tests passed\n";
