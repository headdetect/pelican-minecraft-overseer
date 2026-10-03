/*
 * Overseer Live Map: a small tile viewer for squaremap tiles, with live player markers.
 * No libraries. Loaded once per page through Livewire's @assets and used as
 * x-data="overseerLiveMap(config)".
 *
 * Coordinates follow squaremap: at zoom level `max` one pixel is one block, each
 * level below halves that, and tile (tx, ty) at a level holds blocks starting at
 * tx * 512 * 2^(max - level) on x and ty * the same on z.
 *
 * `zoom` can be fractional. The viewer scales the nearest tile level to fit.
 */
window.overseerLiveMap = function (cfg) {
    const TILE = 512;
    // Scroll distance, in pixels, for one zoom level (a 2x change in scale).
    // A mouse wheel notch is about 100px, so one notch zooms about 19%.
    const WHEEL_PX_PER_LEVEL = 400;

    return {
        cfg,
        worlds: cfg.worlds,
        world: null,
        zoom: 0,
        cx: 0,
        cz: 0,
        players: [],
        time: null,
        server: null,
        chat: [],
        // The point menu: a block clicked on the map, with its ground height once known.
        point: null,
        chatScrolled: false,
        draft: '',
        sending: false,
        selected: null,
        pop: null,
        state: 'loading',
        coords: '',
        busy: false,

        init() {
            const first = this.worlds[0];
            this.setWorld(first.name, false);

            this.tiles = new Map();
            this.pins = new Map();
            this.resize = new ResizeObserver(() => this.render());
            this.resize.observe(this.$refs.viewport);
            this.onVisible = () => !document.hidden && this.tick();
            document.addEventListener('visibilitychange', this.onVisible);

            this.tick();
            this.timer = setInterval(() => this.tick(), cfg.refresh * 1000);
        },

        destroy() {
            clearInterval(this.timer);
            this.resize?.disconnect();
            document.removeEventListener('visibilitychange', this.onVisible);
        },

        // ---- data ----

        async tick() {
            // Stop polling when the tab is hidden or the page was navigated away from.
            if (document.hidden || !this.$root.isConnected) {
                if (!this.$root.isConnected) this.destroy();
                return;
            }
            if (this.busy) return;
            this.busy = true;
            try {
                // A plain fetch, so a slow poll never holds up a Livewire button click.
                const response = await fetch(cfg.feedUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const result = await response.json();
                this.players = result.players;
                this.time = result.time ?? null;
                this.server = result.server ?? null;
                this.setChat(result.chat ?? []);
                this.state = result.ok ? 'live' : 'stale';
            } catch (e) {
                this.state = 'stale';
            } finally {
                this.busy = false;
            }
            this.renderPins();
        },

        get current() {
            return this.worlds.find((w) => w.name === this.world) ?? this.worlds[0];
        },

        get here() {
            return this.players.filter((p) => p.world === this.world);
        },


        // Keeps the chat scrolled to the newest line, unless someone scrolled up to read.
        // The chat box is inside a Filament section, which has its own x-data,
        // so $refs can't see it. Keep scrolling until it has been scrolled once.
        chatBox() {
            return this.$root.querySelector('.us-chat');
        },

        setChat(lines) {
            const box = this.chatBox();
            const atBottom = !this.chatScrolled || !box || box.scrollHeight - box.scrollTop - box.clientHeight < 24;
            this.chat = lines;
            if (!atBottom) return;
            this.$nextTick(() => requestAnimationFrame(() => {
                const el = this.chatBox();
                if (!el) return;
                el.scrollTop = el.scrollHeight;
                this.chatScrolled = lines.length > 0;
            }));
        },

        async sendChat() {
            const text = this.draft.trim();
            if (!text || this.sending) return;
            this.sending = true;
            try {
                if (await this.$wire.sendChat(text)) {
                    this.draft = '';
                    this.tick();
                }
            } finally {
                this.sending = false;
            }
        },

        phaseIcon(phase) {
            return { day: '\u2600\uFE0F', sunset: '\u{1F307}', night: '\u{1F319}', sunrise: '\u{1F305}' }[phase] ?? '';
        },


        worldLabel(name) {
            return this.worlds.find((w) => w.name === name)?.label ?? name;
        },

        get selectedPlayer() {
            return this.players.find((p) => p.name === this.selected) ?? null;
        },

        // ---- view ----

        setWorld(name, render = true) {
            const world = this.worlds.find((w) => w.name === name);
            if (!world) return;
            this.world = name;
            this.zoom = world.def;
            this.cx = world.spawn.x;
            this.cz = world.spawn.z;
            this.pop = null;
            if (render) {
                this.clearTiles();
                this.render();
            }
        },

        // Pixels per block at the current zoom.
        get ppb() {
            return Math.pow(2, this.zoom - this.current.max);
        },

        size() {
            const r = this.$refs.viewport.getBoundingClientRect();
            return { w: r.width, h: r.height };
        },

        toScreen(x, z) {
            const { w, h } = this.size();
            return { x: (x - this.cx) * this.ppb + w / 2, y: (z - this.cz) * this.ppb + h / 2 };
        },

        toBlock(sx, sy) {
            const { w, h } = this.size();
            return { x: this.cx + (sx - w / 2) / this.ppb, z: this.cz + (sy - h / 2) / this.ppb };
        },

        render() {
            if (!this.tiles) return;
            if (cfg.mode === 'squaremap') this.renderTiles();
            else this.renderGrid();
            this.renderPins();
        },

        clearTiles() {
            this.tiles.forEach((img) => img.remove());
            this.tiles.clear();
        },

        renderTiles() {
            const world = this.current;
            const level = Math.min(Math.round(this.zoom), world.max);
            const blocks = TILE * Math.pow(2, world.max - level);
            const px = blocks * this.ppb;
            const { w, h } = this.size();
            const tl = this.toBlock(0, 0);
            const br = this.toBlock(w, h);
            const wanted = new Set();

            for (let tx = Math.floor(tl.x / blocks); tx <= Math.floor(br.x / blocks); tx++) {
                for (let ty = Math.floor(tl.z / blocks); ty <= Math.floor(br.z / blocks); ty++) {
                    const key = `${world.name}/${level}/${tx}_${ty}`;
                    wanted.add(key);
                    let img = this.tiles.get(key);
                    if (!img) {
                        img = document.createElement('img');
                        img.className = 'us-tile';
                        img.alt = '';
                        img.draggable = false;
                        img.onerror = () => (img.style.visibility = 'hidden');
                        img.src = `${cfg.tileBase}tiles/${key}.png`;
                        this.$refs.tiles.appendChild(img);
                        this.tiles.set(key, img);
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
        },

        renderGrid() {
            const canvas = this.$refs.grid;
            const { w, h } = this.size();
            const dpr = window.devicePixelRatio || 1;
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(h * dpr);
            const c = canvas.getContext('2d');
            c.setTransform(dpr, 0, 0, dpr, 0, 0);
            c.clearRect(0, 0, w, h);

            // Grid lines every 16 * 2^n blocks, at least 64px apart on screen.
            let step = 16;
            while (step * this.ppb < 64) step *= 2;
            while (step > 16 && step * this.ppb > 160) step /= 2;

            const tl = this.toBlock(0, 0);
            const br = this.toBlock(w, h);
            const styles = getComputedStyle(this.$root);
            c.font = '11px ui-monospace, monospace';
            c.lineWidth = 1;

            for (let x = Math.ceil(tl.x / step) * step; x <= br.x; x += step) {
                const sx = Math.round(this.toScreen(x, 0).x) + 0.5;
                c.strokeStyle = x === 0 ? styles.getPropertyValue('--us-axis') : styles.getPropertyValue('--us-line');
                c.beginPath();
                c.moveTo(sx, 0);
                c.lineTo(sx, h);
                c.stroke();
                c.fillStyle = styles.getPropertyValue('--us-label');
                c.fillText(String(x), sx + 4, h - 8);
            }
            for (let z = Math.ceil(tl.z / step) * step; z <= br.z; z += step) {
                const sy = Math.round(this.toScreen(0, z).y) + 0.5;
                c.strokeStyle = z === 0 ? styles.getPropertyValue('--us-axis') : styles.getPropertyValue('--us-line');
                c.beginPath();
                c.moveTo(0, sy);
                c.lineTo(w, sy);
                c.stroke();
                c.fillStyle = styles.getPropertyValue('--us-label');
                c.fillText(String(z), 6, sy - 4);
            }
        },

        renderPins() {
            if (!this.pins) return;
            const shown = new Set();
            const { w, h } = this.size();

            this.here.forEach((p) => {
                shown.add(p.name);
                let pin = this.pins.get(p.name);
                if (!pin) {
                    pin = document.createElement('button');
                    pin.type = 'button';
                    pin.className = 'us-pin';
                    const tag = document.createElement('span');
                    tag.className = 'us-pin-tag';
                    tag.textContent = p.name;
                    const head = document.createElement('img');
                    head.className = 'us-pin-head';
                    head.alt = '';
                    head.src = this.head(p.name);
                    pin.append(tag, head);
                    pin.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.select(p.name);
                    });
                    this.$refs.pins.appendChild(pin);
                    this.pins.set(p.name, pin);
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

            this.placePop();
            this.placePoint();
        },

        openPoint(e) {
            const r = this.$refs.viewport.getBoundingClientRect();
            const b = this.toBlock(e.clientX - r.left, e.clientY - r.top);
            this.point = { world: this.world, x: Math.floor(b.x), z: Math.floor(b.z), y: null, loading: true, failed: false, player: this.players[0]?.name ?? '', sending: false };
            // Change it through Alpine's reactive copy, so the menu updates when the height arrives.
            const point = this.point;
            this.placePoint();
            // A plain fetch, so it doesn't wait behind the Livewire position poll.
            const url = `${cfg.surfaceUrl}?${new URLSearchParams({ world: point.world, x: point.x, z: point.z })}`;
            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : { y: null }))
                .then(({ y }) => {
                    if (this.point !== point) return;
                    point.y = y;
                    point.loading = false;
                    point.failed = y === null;
                }, () => {
                    point.loading = false;
                    point.failed = true;
                });
        },

        placePoint() {
            const p = this.point;
            if (!p) return;
            if (p.world !== this.world) {
                this.point = null;
                return;
            }
            const at = this.toScreen(p.x + 0.5, p.z + 0.5);
            const { w, h } = this.size();
            p.left = Math.max(8, Math.min(at.x + 12, w - 268));
            p.top = Math.max(8, Math.min(at.y - 20, h - 190));
            p.dotX = at.x;
            p.dotY = at.y;
        },

        async teleportHere() {
            const p = this.point;
            if (!p || p.y === null || !p.player || p.sending) return;
            p.sending = true;
            try {
                if (await this.$wire.teleportTo(p.player, p.world, p.x, p.y, p.z)) {
                    this.point = null;
                    this.tick();
                }
            } finally {
                p.sending = false;
            }
        },

        placePop() {
            const p = this.selectedPlayer;
            if (!p || p.world !== this.world) {
                this.pop = null;
                return;
            }
            const at = this.toScreen(p.x + 0.5, p.z + 0.5);
            const { w } = this.size();
            const left = at.x + 250 > w ? at.x - 256 : at.x + 18;
            this.pop = { left: Math.max(8, left), top: Math.max(8, at.y - 60) };
        },

        head(name) {
            return cfg.headUrl.replace('{name}', encodeURIComponent(name));
        },

        select(name) {
            this.selected = this.selected === name && this.pop ? null : name;
            this.renderPins();
        },

        focus(p) {
            if (p.world !== this.world) this.setWorld(p.world);
            this.cx = p.x;
            this.cz = p.z;
            if (this.zoom < this.current.max) this.zoom = this.current.max;
            this.selected = p.name;
            this.render();
        },

        act(action, name) {
            this.selected = null;
            this.pop = null;
            this.$wire.mountAction(action, { name });
        },

        // ---- input ----

        zoomBy(delta, sx, sy) {
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
        },

        onWheel(e) {
            const r = this.$refs.viewport.getBoundingClientRect();
            // deltaMode 1 is lines and 2 is pages. Convert both to pixels.
            const px = e.deltaY * [1, 33, 800][e.deltaMode];
            const delta = Math.max(-1, Math.min(1, -px / WHEEL_PX_PER_LEVEL));
            this.zoomBy(delta, e.clientX - r.left, e.clientY - r.top);
        },

        onDown(e) {
            if (e.button !== 0 || e.target.closest('.us-pin, .us-pop, .us-point, .us-controls')) return;
            this.drag = { x: e.clientX, y: e.clientY, cx: this.cx, cz: this.cz, moved: false };
            this.$refs.viewport.setPointerCapture(e.pointerId);
        },

        onMove(e) {
            const r = this.$refs.viewport.getBoundingClientRect();
            const b = this.toBlock(e.clientX - r.left, e.clientY - r.top);
            this.coords = `x ${Math.floor(b.x)} · z ${Math.floor(b.z)}`;

            if (!this.drag) return;
            const dx = e.clientX - this.drag.x;
            const dy = e.clientY - this.drag.y;
            if (Math.abs(dx) + Math.abs(dy) > 3) {
                this.drag.moved = true;
                this.$refs.viewport.classList.add('is-dragging');
            }
            this.cx = this.drag.cx - dx / this.ppb;
            this.cz = this.drag.cz - dy / this.ppb;
            this.render();
        },

        onUp(e) {
            if (this.drag && !this.drag.moved) {
                const hadPopup = this.selected || this.point;
                this.selected = null;
                this.point = null;
                // A click on empty map opens the point menu, unless it closed a popup.
                if (!hadPopup && cfg.canTeleport && e) this.openPoint(e);
            }
            this.drag = null;
            this.$refs.viewport.classList.remove('is-dragging');
            this.renderPins();
        },

        onKey(e) {
            const pan = 80 / this.ppb;
            const moves = { ArrowLeft: [-pan, 0], ArrowRight: [pan, 0], ArrowUp: [0, -pan], ArrowDown: [0, pan] };
            if (moves[e.key]) {
                this.cx += moves[e.key][0];
                this.cz += moves[e.key][1];
                this.render();
            } else if (e.key === '+' || e.key === '=') {
                this.zoomBy(1);
            } else if (e.key === '-') {
                this.zoomBy(-1);
            } else if (e.key === 'Escape') {
                this.selected = null;
                this.point = null;
                this.renderPins();
            } else {
                return;
            }
            e.preventDefault();
        },
    };
};
