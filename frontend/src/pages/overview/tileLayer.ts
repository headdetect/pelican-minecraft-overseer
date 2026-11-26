/*
 * Loads and caches squaremap tiles for the map canvas.
 *
 * The engine calls want() on every frame with the tiles on screen, nearest the
 * center first. This class then:
 *   - fetches at most MAX_LOADS tiles at once, nearest the center first
 *   - cancels fetches for tiles that left the screen before they arrived
 *   - never fetches a loaded tile, or a tile the panel said is missing, again,
 *     until reload()
 *   - decodes each tile at about the size it is drawn, so a 512px tile drawn
 *     64px wide uses 64x64 pixels of memory instead of 512x512
 */

const TILE = 512;
// Browsers open about 6 connections per host, so more than this only queues in the browser.
const MAX_LOADS = 6;
// Tiles kept after they leave the screen. Off-screen tiles keep the PNG they
// downloaded, so showing them again needs a decode but no request.
const KEEP = 512;
// Off-screen tiles that also keep their decoded image, the most recently shown first.
// A full-size tile decodes to 1 MB, so this is at most 128 MB.
const KEEP_DECODED = 128;

interface Tile {
    key: string;
    /** The PNG from the panel. */
    blob?: Blob;
    /** The panel had no tile here. */
    missing: boolean;
    /** The decoded image, and the width it was decoded at. */
    bitmap?: ImageBitmap;
    size: number;
    /** The width of a decode in progress, or 0. */
    decoding: number;
    fetch: 'idle' | 'queued' | 'running';
    abort?: AbortController;
    /** Set by reload(): the next fetch skips the browser cache. */
    version?: number;
    /** Removed from the cache. Fetches and decodes that finish later drop their result. */
    dropped: boolean;
}

export class TileLayer {
    private tiles = new Map<string, Tile>();
    private queue: string[] = [];
    private running = 0;
    private size = TILE;

    constructor(
        private base: string,
        /** A tile finished loading, so the canvas needs drawing again. */
        private onChange: () => void,
    ) {}

    /**
     * The tiles on screen, nearest the center first, and how wide each one is
     * drawn, in device pixels.
     */
    want(keys: string[], drawn: number): void {
        this.size = Math.min(TILE, Math.max(16, Math.pow(2, Math.ceil(Math.log2(Math.max(1, drawn))))));
        const wanted = new Set(keys);

        // Stop loading tiles that left the screen.
        this.tiles.forEach((tile) => {
            if (wanted.has(tile.key) || tile.fetch === 'idle') return;
            tile.abort?.abort();
            tile.fetch = 'idle';
            if (!tile.blob && !tile.missing) this.drop(tile);
        });

        this.queue = [];
        for (const key of keys) {
            let tile = this.tiles.get(key);
            if (tile) {
                // Move it to the end, so it's the last tile the cache drops.
                this.tiles.delete(key);
            } else {
                tile = { key, missing: false, size: 0, decoding: 0, fetch: 'idle', dropped: false };
            }
            this.tiles.set(key, tile);
            if (tile.fetch === 'idle' && ((!tile.blob && !tile.missing) || tile.version)) tile.fetch = 'queued';
            if (tile.fetch === 'queued') this.queue.push(key);
            this.decode(tile);
        }

        this.evict(wanted);
        this.pump();
    }

    /** The decoded image for a tile, if it has one. */
    bitmap(key: string): ImageBitmap | undefined {
        return this.tiles.get(key)?.bitmap;
    }

    /**
     * Loads the tiles on screen again, keeping the old image until the new one
     * arrives. Tiles the panel said were missing get another try. Off-screen
     * tiles are dropped, so they load fresh when they're back on screen.
     */
    reload(visible: string[]): void {
        const keep = new Set(visible);
        const version = Date.now();
        this.tiles.forEach((tile) => {
            if (keep.has(tile.key)) {
                tile.version = version;
            } else {
                this.drop(tile);
            }
        });
        this.onChange();
    }

    clear(): void {
        this.tiles.forEach((tile) => this.drop(tile));
        this.queue = [];
    }

    private pump(): void {
        while (this.running < MAX_LOADS && this.queue.length > 0) {
            const tile = this.tiles.get(this.queue.shift()!);
            if (!tile || tile.fetch !== 'queued') continue;
            this.load(tile);
        }
    }

    private load(tile: Tile): void {
        const abort = new AbortController();
        const url = `${this.base}tiles/${tile.key}.png${tile.version ? `?v=${tile.version}` : ''}`;
        tile.fetch = 'running';
        tile.abort = abort;
        this.running++;

        fetch(url, { signal: abort.signal, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.blob() : null))
            .then((blob) => {
                if (abort.signal.aborted || tile.dropped) return;
                tile.fetch = 'idle';
                tile.version = undefined;
                if (blob) {
                    tile.blob = blob;
                    tile.missing = false;
                    // Decode the new PNG even if the old one is decoded at this size.
                    tile.size = 0;
                    tile.decoding = 0;
                    this.decode(tile);
                } else if (!tile.blob) {
                    tile.missing = true;
                }
            })
            .catch(() => {
                if (abort.signal.aborted || tile.dropped) return;
                tile.fetch = 'idle';
                tile.version = undefined;
                if (!tile.blob) tile.missing = true;
            })
            .finally(() => {
                this.running--;
                this.pump();
            });
    }

    /** Decodes a tile's PNG at the current drawn size, unless it already is. */
    private decode(tile: Tile): void {
        const size = this.size;
        const blob = tile.blob;
        if (!blob || tile.size === size || tile.decoding === size) return;
        tile.decoding = size;
        const options: ImageBitmapOptions = size < TILE ? { resizeWidth: size, resizeHeight: size, resizeQuality: 'medium' } : {};
        createImageBitmap(blob, options)
            .then((bitmap) => {
                if (tile.dropped || tile.blob !== blob || tile.decoding !== size) {
                    bitmap.close();
                    return;
                }
                tile.bitmap?.close();
                tile.bitmap = bitmap;
                tile.size = size;
                tile.decoding = 0;
                this.onChange();
            })
            .catch(() => {
                if (tile.dropped || tile.blob !== blob) return;
                // Not an image the browser can read. Treat it like a missing tile.
                tile.blob = undefined;
                tile.decoding = 0;
                tile.missing = true;
            });
    }

    /** Drops the off-screen tiles shown longest ago, past the cache limits. */
    private evict(wanted: Set<string>): void {
        const offscreen = [...this.tiles.values()].filter((tile) => !wanted.has(tile.key));
        let extra = this.tiles.size - Math.max(KEEP, wanted.size);
        offscreen.forEach((tile, i) => {
            if (extra > 0) {
                this.drop(tile);
                extra--;
            } else if (i < offscreen.length - KEEP_DECODED && tile.bitmap) {
                tile.bitmap.close();
                tile.bitmap = undefined;
                tile.size = 0;
            }
        });
    }

    private drop(tile: Tile): void {
        tile.dropped = true;
        tile.abort?.abort();
        tile.bitmap?.close();
        this.tiles.delete(tile.key);
    }
}
