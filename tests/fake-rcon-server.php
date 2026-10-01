<?php

// A tiny RCON server for tests. Usage: php fake-rcon-server.php <port> <password>
// Answers "list" with two players, "long" with an 5000-byte reply split in two packets, anything else with "ok: <command>".

[, $port, $password] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if (!$server) {
    fwrite(STDERR, "listen failed: $error\n");
    exit(1);
}
echo "ready\n";

$readPacket = function ($conn) {
    $size = fread($conn, 4);
    if ($size === '' || $size === false) {
        return null;
    }
    $length = unpack('V', $size)[1];
    $data = '';
    while (strlen($data) < $length) {
        $data .= fread($conn, $length - strlen($data));
    }
    $h = unpack('Vid/Vtype', substr($data, 0, 8));

    return ['id' => $h['id'], 'type' => $h['type'], 'body' => substr($data, 8, -2)];
};
$send = function ($conn, int $id, int $type, string $body) {
    $payload = pack('VV', $id, $type) . $body . "\x00\x00";
    fwrite($conn, pack('V', strlen($payload)) . $payload);
};

while ($conn = @stream_socket_accept($server, 10)) {
    while ($p = $readPacket($conn)) {
        if ($p['type'] === 3) {
            $send($conn, $p['body'] === $password ? $p['id'] : 0xFFFFFFFF, 2, '');
            continue;
        }
        match ($p['body']) {
            'list' => $send($conn, $p['id'], 0, 'There are 2 of a max of 20 players online: Doobie, kelp_lord'),
            'long' => (function () use ($send, $conn, $p) {
                $send($conn, $p['id'], 0, str_repeat('a', 4096));
                $send($conn, $p['id'], 0, str_repeat('b', 904));
            })(),
            default => $send($conn, $p['id'], 0, 'ok: ' . $p['body']),
        };
    }
    fclose($conn);
}
