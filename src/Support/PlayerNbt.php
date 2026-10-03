<?php

namespace Headdetect\Overseer\Support;

use RuntimeException;

/**
 * Reads a few fields from a player's .dat file, which is a gzip-compressed
 * NBT compound. It only keeps top-level fields it was asked for and skips
 * the rest, so it never builds the whole tree.
 */
final class PlayerNbt
{
    private const FIELDS = ['playerGameType', 'XpLevel', 'Dimension'];

    private string $data;

    private int $pos = 0;

    /** @return ?array{gamemode: ?string, xp_level: ?int, dimension: ?string} null when the file isn't valid NBT */
    public static function summary(string $file): ?array
    {
        $data = @gzdecode($file);
        if ($data === false || $data === '') {
            return null;
        }

        try {
            $fields = (new self($data))->readRoot();
        } catch (RuntimeException) {
            return null;
        }

        $mode = isset($fields['playerGameType']) ? (int) $fields['playerGameType'] : null;

        return [
            'gamemode' => $mode !== null ? (CommandInput::GAME_MODES[$mode] ?? null) : null,
            'xp_level' => isset($fields['XpLevel']) ? (int) $fields['XpLevel'] : null,
            'dimension' => isset($fields['Dimension']) && is_string($fields['Dimension']) ? $fields['Dimension'] : null,
        ];
    }

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    /** @return array<string, int|float|string> */
    private function readRoot(): array
    {
        if ($this->byte() !== 10) {
            throw new RuntimeException('The root is not a compound.');
        }
        $this->string();

        $found = [];
        while (($type = $this->byte()) !== 0) {
            $name = $this->string();
            if (in_array($name, self::FIELDS, true) && in_array($type, [1, 2, 3, 8], true)) {
                $found[$name] = $this->scalar($type);
            } else {
                $this->skip($type);
            }
        }

        return $found;
    }

    private function scalar(int $type): int|string
    {
        return match ($type) {
            1 => $this->signed($this->take(1), 'c'),
            2 => $this->signed($this->take(2), 'n', 16),
            3 => $this->signed($this->take(4), 'N', 32),
            8 => $this->string(),
        };
    }

    private function skip(int $type): void
    {
        match ($type) {
            1 => $this->take(1),
            2 => $this->take(2),
            3, 5 => $this->take(4),
            4, 6 => $this->take(8),
            7 => $this->take($this->int()),
            8 => $this->string(),
            9 => $this->skipList(),
            10 => $this->skipCompound(),
            11 => $this->take(4 * $this->int()),
            12 => $this->take(8 * $this->int()),
            default => throw new RuntimeException("Unknown NBT tag type $type."),
        };
    }

    private function skipList(): void
    {
        $type = $this->byte();
        $count = $this->int();
        for ($i = 0; $i < $count; $i++) {
            $this->skip($type);
        }
    }

    private function skipCompound(): void
    {
        while (($type = $this->byte()) !== 0) {
            $this->string();
            $this->skip($type);
        }
    }

    private function byte(): int
    {
        return ord($this->take(1));
    }

    private function int(): int
    {
        $value = (int) $this->signed($this->take(4), 'N', 32);
        if ($value < 0) {
            throw new RuntimeException('Negative NBT length.');
        }

        return $value;
    }

    private function string(): string
    {
        return $this->take(unpack('n', $this->take(2))[1]);
    }

    private function signed(string $bytes, string $format, int $bits = 8): int
    {
        if ($format === 'c') {
            return unpack('c', $bytes)[1];
        }
        $value = unpack($format, $bytes)[1];

        return $value >= 2 ** ($bits - 1) ? $value - 2 ** $bits : $value;
    }

    private function take(int $length): string
    {
        if ($length < 0 || $this->pos + $length > strlen($this->data)) {
            throw new RuntimeException('The NBT data ends early.');
        }
        $bytes = substr($this->data, $this->pos, $length);
        $this->pos += $length;

        return $bytes;
    }
}
