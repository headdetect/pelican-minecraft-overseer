import type { MapPlayer, World } from './types';

/*
 * A small tile viewer for squaremap tiles, with player markers. No libraries.
 * It draws tiles, the grid and the markers straight into the DOM, because
 * those change on every drag frame. React draws the controls and popovers on
 * top, at the screen positions this class works out.
 *
 * Coordinates follow squaremap: at zoom level `max` one pixel is one block, each
 * level below halves that, and tile (tx, ty) at a level has the blocks starting at
 * tx * 512 * 2^(max - level) on x and ty * the same on z.
 *
 * `zoom` can be fractional. The viewer scales the nearest tile level to fit.
 */

const TILE = 512;
// Scroll distance, in pixels, for one zoom level (a 2x change in scale).
// A mouse wheel notch is about 100px, so one notch zooms about 19%.
const WHEEL_PX_PER_LEVEL = 400;

export interface EngineElements {
    viewport: HTMLDivElement;
    tiles: HTMLDivElement;
    pins: HTMLDivElement;
    grid: HTMLCanvasElement;
}

export interface EngineOptions {
    mode: 'squaremap' | 'grid';
    tileBase: string;
    head: (name: string) => string;
    /** A marker was clicked. */
    onPin: (name: string) => void;
    /** A click on the map that wasn't a drag. */
    onClick: (e: PointerEvent) => void;
    /** The pointer moved, with the block under it, or null when it left. */
    onCoords: (coords: { x: number; z: number } | null) => void;
    /** The view moved or zoomed, so overlays need placing again. */
    onView: () => void;
    onEscape: () => void;
}

export class MapEngine {
    worlds: World[];
    world: string;
    zoom = 0;
    cx = 0;
    cz = 0;
    players: MapPlayer[] = [];
    selected: string | null = null;

    private tiles = new Map<string, HTMLImageElement>();
    private pins = new Map<string, HTMLButtonElement>();
    private drag: { x: number; y: number; cx: number; cz: number; moved: boolean } | null = null;
    private resize: ResizeObserver;
    private els: EngineElements;
    private opts: EngineOptions;
    private cleanup: Array<() => void> = [];
    private glide = 0;

    constructor(els: EngineElements, opts: EngineOptions, worlds: World[]) {
        this.els = els;
        this.opts = opts;
        this.worlds = worlds;
        this.world = worlds[0].name;
        this.setWorld(this.world, false);

        this.resize = new ResizeObserver(() => this.render());
        this.resize.observe(els.viewport);

        const on = <K extends keyof HTMLElementEventMap>(type: K, fn: (e: HTMLElementEventMap[K]) => void, options?: AddEventListenerOptions) => {
            els.viewport.addEventListener(type, fn, options);
            this.cleanup.push(() => els.viewport.removeEventListener(type, fn, options));
        };
        on('wheel', (e) => this.onWheel(e), { passive: false });
        on('pointerdown', (e) => this.onDown(e));
        on('pointermove', (e) => this.onMove(e));
        on('pointerup', (e) => this.onUp(e));
        on('pointercancel', () => this.onUp(null));
        on('pointerleave', () => this.opts.onCoords(null));
        on('keydown', (e) => this.onKey(e));
    }

    destroy(): void {
        this.stopGlide();
        this.resize.disconnect();
        this.cleanup.forEach((fn) => fn());
        this.clearTiles();
        this.pins.forEach((pin) => pin.remove());
        this.pins.clear();
    }

    get current(): World {
        return this.worlds.find((w) => w.name === this.world) ?? this.worlds[0];
    }

    /** Pixels per block at the current zoom. */
    get ppb(): number {
        return Math.pow(2, this.zoom - this.current.max);
    }

    size(): { w: number; h: number } {
        const r = this.els.viewport.getBoundingClientRect();
        return { w: r.width, h: r.height };
    }

    toScreen(x: number, z: number): { x: number; y: number } {
        const { w, h } = this.size();
        return { x: (x - this.cx) * this.ppb + w / 2, y: (z - this.cz) * this.ppb + h / 2 };
    }

    toBlock(sx: number, sy: number): { x: number; z: number } {
        const { w, h } = this.size();
        return { x: this.cx + (sx - w / 2) / this.ppb, z: this.cz + (sy - h / 2) / this.ppb };
    }

    /** The block under a pointer event. */
    blockAt(e: { clientX: number; clientY: number }): { x: number; z: number } {
        const r = this.els.viewport.getBoundingClientRect();
        const b = this.toBlock(e.clientX - r.left, e.clientY - r.top);
        return { x: Math.floor(b.x), z: Math.floor(b.z) };
    }

    setWorlds(worlds: World[]): void {
        this.worlds = worlds;
        if (!worlds.some((w) => w.name === this.world)) this.setWorld(worlds[0].name);
    }

    setWorld(name: string, render = true): void {
        const world = this.worlds.find((w) => w.name === name);
        if (!world) return;
        this.world = name;
        this.zoom = world.def;
        this.cx = world.spawn.x;
        this.cz = world.spawn.z;
        if (render) {
            this.clearTiles();
            this.render();
        }
    }

    setPlayers(players: MapPlayer[]): void {
        this.players = players;
        this.renderPins();
    }

    setSelected(name: string | null): void {
        this.selected = name;
        this.renderPins();
    }

    /**
     * Centers on a player and zooms in to one block per pixel. Within one world
     * the view glides there. A player in another world needs a world switch,
     * so the view jumps.
     */
    focus(player: MapPlayer): void {
        const zoom = Math.max(this.zoom, this.current.max);
        if (player.world !== this.world) {
            this.stopGlide();
            this.setWorld(player.world, false);
            this.cx = player.x;
            this.cz = player.z;
            this.zoom = Math.max(this.zoom, this.current.max);
            this.clearTiles();
            this.render();
            return;
        }
        this.glideTo(player.x, player.z, zoom);
    }

    /**
     * Moves the view to a point over a short time. The speed eases in and out,
     * and the time grows with the distance on screen, from 350 to 900 ms.
     * A drag, a scroll or a key press stops the glide where it is.
     */
    glideTo(x: number, z: number, zoom: number): void {
        this.stopGlide();
        const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const from = { cx: this.cx, cz: this.cz, zoom: this.zoom };
        const distance = Math.hypot(x - from.cx, z - from.cz) * this.ppb;
        if (reduce || (distance < 1 && zoom === from.zoom)) {
            this.cx = x;
            this.cz = z;
            this.zoom = zoom;
            this.render();
            return;
        }

        const duration = Math.min(900, 350 + distance * 0.35);
        const start = performance.now();
        // Ease in and out (cubic), so the move starts and ends slowly.
        const ease = (t: number) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
        this.els.viewport.classList.add('is-moving');

        const step = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const k = ease(t);
            this.zoom = from.zoom + (zoom - from.zoom) * k;
            this.cx = from.cx + (x - from.cx) * k;
            this.cz = from.cz + (z - from.cz) * k;
            this.render();
            if (t < 1) {
                this.glide = requestAnimationFrame(step);
            } else {
                this.glide = 0;
                this.els.viewport.classList.remove('is-moving');
            }
        };
        this.glide = requestAnimationFrame(step);
    }

    private stopGlide(): void {
        if (!this.glide) return;
        cancelAnimationFrame(this.glide);
        this.glide = 0;
        this.els.viewport.classList.remove('is-moving');
    }

    render(): void {
        if (this.opts.mode === 'squaremap') this.renderTiles();
        else this.renderGrid();
        this.renderPins();
    }

    // Loads each visible tile again in the background and swaps it in once it
    // arrives, so the map doesn't flicker. The query string skips the
    // browser's 30-second tile cache. Tiles that failed before get another try.
    reloadTiles(): void {
        const version = Date.now();
        this.tiles.forEach((img, key) => {
            const fresh = new Image();
            fresh.onload = () => {
                if (this.tiles.get(key) !== img) return;
                img.src = fresh.src;
                img.style.visibility = '';
            };
            fresh.src = `${this.opts.tileBase}tiles/${key}.png?v=${version}`;
        });
    }

    clearTiles(): void {
        this.tiles.forEach((img) => img.remove());
        this.tiles.clear();
    }

    zoomBy(delta: number, sx?: number, sy?: number): void {
        this.stopGlide();
        const world = this.current;
        const next = Math.max(0, Math.min(world.max + world.extra, this.zoom + delta));
        if (next === this.zoom) return;
        const { w, h } = this.size();
        sx ??= w / 2;
        sy ??= h / 2;
        const anchor = this.toBlock(sx, sy);
        this.zoom = next;
        this.cx = anchor.x - (sx - w / 2) / this.ppb;
        this.cz = anchor.z - (sy - h / 2) / this.ppb;
        this.render();
    }

    private renderTiles(): void {
        const world = this.current;
        const level = Math.min(Math.round(this.zoom), world.max);
        const blocks = TILE * Math.pow(2, world.max - level);
        const px = blocks * this.ppb;
        const { w, h } = this.size();
        const tl = this.toBlock(0, 0);
        const br = this.toBlock(w, h);
        const wanted = new Set<string>();

        for (let tx = Math.floor(tl.x / blocks); tx <= Math.floor(br.x / blocks); tx++) {
            for (let ty = Math.floor(tl.z / blocks); ty <= Math.floor(br.z / blocks); ty++) {
                const key = `${world.name}/${level}/${tx}_${ty}`;
                wanted.add(key);
                let img = this.tiles.get(key);
                if (!img) {
                    const tile = document.createElement('img');
                    tile.className = 'us-tile';
                    tile.alt = '';
                    tile.draggable = false;
                    tile.onerror = () => (tile.style.visibility = 'hidden');
                    tile.src = `${this.opts.tileBase}tiles/${key}.png`;
                    this.els.tiles.appendChild(tile);
                    this.tiles.set(key, tile);
                    img = tile;
                }
                const at = this.toScreen(tx * blocks, ty * blocks);
                // Round outward so neighbouring tiles never leave a hairline gap.
                img.style.left = Math.floor(at.x) + 'px';
                img.style.top = Math.floor(at.y) + 'px';
                img.style.width = Math.ceil(px) + 1 + 'px';
                img.style.height = Math.ceil(px) + 1 + 'px';
            }
        }

        this.tiles.forEach((img, key) => {
            if (!wanted.has(key)) {
                img.remove();
                this.tiles.delete(key);
            }
        });
    }

    private renderGrid(): void {
        const canvas = this.els.grid;
        const { w, h } = this.size();
        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
        const c = canvas.getContext('2d');
        if (!c) return;
        c.setTransform(dpr, 0, 0, dpr, 0, 0);
        c.clearRect(0, 0, w, h);

        // Grid lines every 16 * 2^n blocks, at least 64px apart on screen.
        let step = 16;
        while (step * this.ppb < 64) step *= 2;
        while (step > 16 && step * this.ppb > 160) step /= 2;

        const tl = this.toBlock(0, 0);
        const br = this.toBlock(w, h);
        const styles = getComputedStyle(this.els.viewport);
        const line = styles.getPropertyValue('--us-line');
        const axis = styles.getPropertyValue('--us-axis');
        const label = styles.getPropertyValue('--us-label');
        c.font = '11px ui-monospace, monospace';
        c.lineWidth = 1;

        for (let x = Math.ceil(tl.x / step) * step; x <= br.x; x += step) {
            const sx = Math.round(this.toScreen(x, 0).x) + 0.5;
            c.strokeStyle = x === 0 ? axis : line;
            c.beginPath();
            c.moveTo(sx, 0);
            c.lineTo(sx, h);
            c.stroke();
            c.fillStyle = label;
            c.fillText(String(x), sx + 4, h - 8);
        }
        for (let z = Math.ceil(tl.z / step) * step; z <= br.z; z += step) {
            const sy = Math.round(this.toScreen(0, z).y) + 0.5;
            c.strokeStyle = z === 0 ? axis : line;
            c.beginPath();
            c.moveTo(0, sy);
            c.lineTo(w, sy);
            c.stroke();
            c.fillStyle = label;
            c.fillText(String(z), 6, sy - 4);
        }
    }

    private renderPins(): void {
        const shown = new Set<string>();
        const { w, h } = this.size();

        this.players
            .filter((p) => p.world === this.world)
            .forEach((p) => {
                shown.add(p.name);
                let pin = this.pins.get(p.name);
                if (!pin) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'us-pin';
                    const tag = document.createElement('span');
                    tag.className = 'us-pin-tag';
                    tag.textContent = p.name;
                    const head = document.createElement('img');
                    head.className = 'us-pin-head';
                    head.alt = '';
                    head.src = this.opts.head(p.name);
                    button.append(tag, head);
                    button.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.opts.onPin(p.name);
                    });
                    this.els.pins.appendChild(button);
                    this.pins.set(p.name, button);
                    pin = button;
                }
                const at = this.toScreen(p.x + 0.5, p.z + 0.5);
                pin.style.left = at.x + 'px';
                pin.style.top = at.y + 'px';
                pin.style.display = at.x < -40 || at.y < -40 || at.x > w + 40 || at.y > h + 40 ? 'none' : '';
                pin.classList.toggle('is-selected', this.selected === p.name);
                pin.setAttribute('aria-label', `${p.name}, x ${p.x}, z ${p.z}`);
            });

        this.pins.forEach((pin, name) => {
            if (!shown.has(name)) {
                pin.remove();
                this.pins.delete(name);
            }
        });

        this.opts.onView();
    }

    // ---- input ----

    private onWheel(e: WheelEvent): void {
        e.preventDefault();
        this.stopGlide();
        const r = this.els.viewport.getBoundingClientRect();
        // deltaMode 1 is lines and 2 is pages. Convert both to pixels.
        const px = e.deltaY * [1, 33, 800][e.deltaMode];
        const delta = Math.max(-1, Math.min(1, -px / WHEEL_PX_PER_LEVEL));
        this.zoomBy(delta, e.clientX - r.left, e.clientY - r.top);
    }

    private onDown(e: PointerEvent): void {
        if (e.button !== 0 || (e.target as Element).closest('.us-pin, .us-pop, .us-point, .us-controls')) return;
        this.stopGlide();
        this.drag = { x: e.clientX, y: e.clientY, cx: this.cx, cz: this.cz, moved: false };
        this.els.viewport.setPointerCapture(e.pointerId);
    }

    private onMove(e: PointerEvent): void {
        if (!(e.target as Element).closest('.us-pop, .us-point, .us-controls')) {
            this.opts.onCoords(this.blockAt(e));
        }

        if (!this.drag) return;
        const dx = e.clientX - this.drag.x;
        const dy = e.clientY - this.drag.y;
        if (Math.abs(dx) + Math.abs(dy) > 3) {
            this.drag.moved = true;
            this.els.viewport.classList.add('is-dragging');
        }
        this.cx = this.drag.cx - dx / this.ppb;
        this.cz = this.drag.cz - dy / this.ppb;
        this.render();
    }

    private onUp(e: PointerEvent | null): void {
        if (this.drag && !this.drag.moved && e) this.opts.onClick(e);
        this.drag = null;
        this.els.viewport.classList.remove('is-dragging');
    }

    private onKey(e: KeyboardEvent): void {
        if ((e.target as Element).closest('.us-pop, .us-point, .us-controls')) return;
        const pan = 80 / this.ppb;
        const moves: Record<string, [number, number]> = { ArrowLeft: [-pan, 0], ArrowRight: [pan, 0], ArrowUp: [0, -pan], ArrowDown: [0, pan] };
        this.stopGlide();
        if (moves[e.key]) {
            this.cx += moves[e.key][0];
            this.cz += moves[e.key][1];
            this.render();
        } else if (e.key === '+' || e.key === '=') {
            this.zoomBy(1);
        } else if (e.key === '-') {
            this.zoomBy(-1);
        } else if (e.key === 'Escape') {
            this.opts.onEscape();
        } else {
            return;
        }
        e.preventDefault();
    }
}
