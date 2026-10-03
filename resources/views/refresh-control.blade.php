{{--
    Refresh controls at the right end of the Overseer tab row: a button that
    refreshes now, and a menu of automatic intervals. The choice is saved in
    this browser and shared by every page that shows the control.

    Pages don't keep their own timers. This sends an overseer-refresh window
    event, with detail.manual true for a click, and each page decides what
    to reload. A page passes its reload's promise to detail.track(), and the
    button spins and stays disabled until every tracked reload finishes.
    overseer-refresh-interval tells pages the automatic interval.
--}}
<div class="us-refresh-anchor">
    <div
        class="us-refresh"
        x-data="{
            options: [0, 5, 10, 30, 60],
            seconds: 5,
            open: false,
            busy: false,
            timer: null,
            init() {
                try {
                    const saved = localStorage.getItem('overseer.refresh');
                    if (saved !== null && this.options.includes(Number(saved))) this.seconds = Number(saved);
                } catch (e) {}
                this.schedule();
                this.announce();
                this.onVisible = () => !document.hidden && this.seconds > 0 && this.fire(false);
                document.addEventListener('visibilitychange', this.onVisible);
            },
            destroy() {
                clearInterval(this.timer);
                document.removeEventListener('visibilitychange', this.onVisible);
            },
            schedule() {
                clearInterval(this.timer);
                if (this.seconds > 0) this.timer = setInterval(() => !document.hidden && this.fire(false), this.seconds * 1000);
            },
            pick(seconds) {
                this.seconds = seconds;
                this.open = false;
                try { localStorage.setItem('overseer.refresh', String(seconds)); } catch (e) {}
                this.schedule();
                this.announce();
            },
            announce() {
                window.dispatchEvent(new CustomEvent('overseer-refresh-interval', { detail: { seconds: this.seconds } }));
            },
            async fire(manual) {
                if (!this.$root.isConnected) return this.destroy();
                // Skip an automatic refresh while the last one is still running.
                if (this.busy) return;
                const pending = [];
                window.dispatchEvent(new CustomEvent('overseer-refresh', { detail: { manual, track: (promise) => pending.push(promise) } }));
                this.busy = true;
                // Spin for at least half a second, so a fast refresh doesn't just flash.
                await Promise.allSettled([...pending, new Promise((resolve) => setTimeout(resolve, 500))]);
                this.busy = false;
            },
            label(seconds) {
                return seconds === 0 ? @js(trans('overseer::overseer.refresh.off')) : (seconds < 60 ? @js(trans('overseer::overseer.refresh.seconds')).replace(':n', seconds) : @js(trans('overseer::overseer.refresh.minute')));
            },
        }"
        x-on:keydown.escape.window="open = false"
        x-on:click.outside="open = false"
    >
        <button type="button" class="us-refresh-now" x-on:click="fire(true)" x-bind:disabled="busy" :aria-busy="busy" title="{{ trans('overseer::overseer.refresh.now') }}">
            <x-filament::icon icon="tabler-refresh" x-bind:class="{ 'is-spinning': busy }" />
            <span>{{ trans('overseer::overseer.refresh.now') }}</span>
        </button>
        <button type="button" class="us-refresh-menu" x-on:click="open = !open" :aria-expanded="open" aria-haspopup="menu" title="{{ trans('overseer::overseer.refresh.auto') }}">
            <span class="us-refresh-current" x-text="label(seconds)"></span>
            <x-filament::icon icon="tabler-chevron-down" />
        </button>
        <div class="us-refresh-list" x-show="open" x-cloak role="menu">
            <div class="us-refresh-head">{{ trans('overseer::overseer.refresh.auto') }}</div>
            <template x-for="option in options" :key="option">
                <button type="button" role="menuitemradio" :aria-checked="seconds === option" x-on:click="pick(option)" :class="{ 'is-on': seconds === option }">
                    <span x-text="label(option)"></span>
                    <x-filament::icon icon="tabler-check" x-show="seconds === option" />
                </button>
            </template>
        </div>
    </div>
</div>

<style>
    /* Sits on the tab row's right edge without changing its height or centering. */
    /* Filament puts 2rem between page blocks; the negative margin cancels it so the tabs don't move. */
    .us-refresh-anchor { position: relative; height: 0; margin-bottom: -2rem; }
    .us-refresh { position: absolute; right: 0; top: 0.55rem; z-index: 20; display: inline-flex; border: 1px solid rgb(127 127 127 / 0.25); border-radius: 0.6rem; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / 0.05); }
    .dark .us-refresh { background: rgb(24 24 27); border-color: rgb(255 255 255 / 0.1); }
    .us-refresh > button { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.7rem; font-size: 0.8rem; font-weight: 500; color: inherit; }
    .us-refresh > button:hover { background: rgb(127 127 127 / 0.08); }
    .us-refresh > button:focus-visible { outline: 2px solid var(--primary-500, #3b82f6); outline-offset: -2px; }
    .us-refresh-now { border-radius: 0.6rem 0 0 0.6rem; }
    .us-refresh-menu { border-left: 1px solid rgb(127 127 127 / 0.25); border-radius: 0 0.6rem 0.6rem 0; }
    .us-refresh-current { font-variant-numeric: tabular-nums; color: rgb(107 114 128); }
    .dark .us-refresh-current { color: rgb(161 161 170); }
    .us-refresh svg { width: 1rem; height: 1rem; }
    .us-refresh svg.is-spinning { animation: us-spin 0.8s linear infinite; }
    .us-refresh-now:disabled { cursor: progress; }
    @keyframes us-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .us-refresh svg.is-spinning { animation: none; } }
    .us-refresh-list { position: absolute; right: 0; top: calc(100% + 4px); min-width: 11rem; padding: 0.3rem; border: 1px solid rgb(127 127 127 / 0.2); border-radius: 0.6rem; background: #fff; box-shadow: 0 8px 24px rgb(0 0 0 / 0.12); }
    .dark .us-refresh-list { background: rgb(24 24 27); border-color: rgb(255 255 255 / 0.1); }
    .us-refresh-head { padding: 0.3rem 0.6rem; font-size: 0.7rem; color: rgb(107 114 128); }
    .us-refresh-list button { display: flex; width: 100%; align-items: center; justify-content: space-between; padding: 0.4rem 0.6rem; border-radius: 0.4rem; font-size: 0.8rem; text-align: left; }
    .us-refresh-list button:hover, .us-refresh-list button.is-on { background: rgb(127 127 127 / 0.1); }
    /* On narrow screens the tab row fills the width, so put the control above it. */
    @media (max-width: 1023px) { .us-refresh-anchor { height: auto; margin-bottom: 0; display: flex; justify-content: flex-end; } .us-refresh { position: relative; top: 0; } }
</style>
