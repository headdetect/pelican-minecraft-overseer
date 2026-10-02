<?php

namespace Headdetect\Overseer\Services\Rcon;

/**
 * Minimal Minecraft RCON client (https://minecraft.wiki/w/RCON).
 *
 * Packets are little-endian: int32 length, int32 request id, int32 type, ASCII body, two NUL bytes.
 */
class RconClient
{
    public const TYPE_RESPONSE = 0;

    public const TYPE_COMMAND = 2;

    public const TYPE_LOGIN = 3;

    /** Minecraft splits responses longer than this into several packets. */
    private const MAX_RESPONSE_BODY = 4096;

    /** @var resource|null */
    private $socket = null;

    private int $requestId = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $password,
        private readonly float $timeout = 2.0,
    ) {}

    public function connect(): void
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $error, $this->timeout);

        if ($socket === false) {
            throw new RconException("Can't reach RCON at {$this->host}:{$this->port} ($error).");
        }

        $seconds = (int) $this->timeout;
        stream_set_timeout($socket, $seconds, (int) (($this->timeout - $seconds) * 1_000_000));
        $this->socket = $socket;

        $id = $this->write(self::TYPE_LOGIN, $this->password);
        $response = $this->read();

        if ($response['id'] === -1 || $response['id'] !== $id) {
            $this->close();

            throw new RconException('RCON rejected the password in server.properties.');
        }
    }

    public function command(string $command): string
    {
        if (!$this->socket) {
            $this->connect();
        }

        $id = $this->write(self::TYPE_COMMAND, $command);

        // A full-size packet means more fragments follow. Packets for other ids are leftovers and skipped.
        $body = '';
        while (true) {
            $packet = $this->read();
            if ($packet['id'] !== $id) {
                continue;
            }
            $body .= $packet['body'];
            if (strlen($packet['body']) < self::MAX_RESPONSE_BODY) {
                return $body;
            }
        }
    }

    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function encode(int $id, int $type, string $body): string
    {
        $payload = pack('VV', $id, $type) . $body . "\x00\x00";

        return pack('V', strlen($payload)) . $payload;
    }

    /** @return array{id: int, type: int, body: string} */
    public static function decode(string $packet): array
    {
        $header = unpack('Vid/Vtype', substr($packet, 0, 8));

        return [
            'id' => self::signed($header['id']),
            'type' => $header['type'],
            'body' => substr($packet, 8, -2),
        ];
    }

    private function write(int $type, string $body): int
    {
        $id = ++$this->requestId;
        $packet = self::encode($id, $type, $body);

        if (@fwrite($this->socket, $packet) !== strlen($packet)) {
            throw new RconException('Lost the RCON connection while sending.');
        }

        return $id;
    }

    /** @return array{id: int, type: int, body: string} */
    private function read(): array
    {
        $size = $this->readBytes(4);
        $length = unpack('V', $size)[1];

        if ($length < 10 || $length > self::MAX_RESPONSE_BODY + 10) {
            throw new RconException("RCON sent a packet with an invalid length ($length).");
        }

        return self::decode($this->readBytes($length));
    }

    private function readBytes(int $count): string
    {
        $data = '';
        while (strlen($data) < $count) {
            $chunk = fread($this->socket, $count - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new RconException($meta['timed_out'] ? 'RCON did not answer in time.' : 'RCON closed the connection.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function signed(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
