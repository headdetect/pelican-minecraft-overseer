<?php

namespace Headdetect\Overseer\Support;

/**
 * Which server files the Config page's file editor may open, and how to
 * highlight them. Pure functions, so they can be tested without a panel.
 */
final class EditableFiles
{
    /** Folders that mods and plugins keep their settings in. squaremap/ is squaremap on Fabric. */
    public const FOLDERS = ['config', 'defaultconfigs', 'plugins', 'squaremap'];

    /** How deep to look inside each folder. */
    public const DEPTH = 3;

    /** Stop listing after this many files, so a huge config folder stays fast. */
    public const MAX_FILES = 500;

    private const EXTENSIONS = ['toml', 'json', 'json5', 'yml', 'yaml', 'properties', 'cfg', 'conf', 'ini', 'txt', 'snbt'];

    /** Folders inside those with generated data, not settings. */
    public const SKIP_FOLDERS = ['squaremap/web', 'squaremap/data'];

    /** Files in the server root that other Overseer pages or the server itself manage. */
    private const ROOT_SKIP = ['ops.json', 'whitelist.json', 'banned-players.json', 'banned-ips.json', 'usercache.json', 'eula.txt', 'modrinth.index.json', '.modpack-manager.json'];

    /** Whether a path may be opened: a config file in the root or a known folder, with no way out of them. */
    public static function isEditable(string $path): bool
    {
        if ($path === '' || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true) || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/[\x00-\x1F]/', $path)) {
            return false;
        }

        $parts = explode('/', $path);
        $name = end($parts);
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return false;
        }

        if (count($parts) === 1) {
            return !in_array($name, self::ROOT_SKIP, true);
        }

        foreach (self::SKIP_FOLDERS as $skip) {
            if (str_starts_with($path, "$skip/")) {
                return false;
            }
        }

        return in_array($parts[0], self::FOLDERS, true) && count($parts) <= self::DEPTH + 1;
    }

    /** The Monaco language for a file, by extension. */
    public static function language(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json', 'json5' => 'json',
            'yml', 'yaml' => 'yaml',
            'properties', 'cfg', 'conf', 'ini', 'toml' => 'ini',
            default => 'plaintext',
        };
    }
}
