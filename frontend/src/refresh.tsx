import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { IconCheck, IconChevronDown, IconRefresh } from '@tabler/icons-react';
import { t } from './lang';
import { cx } from './ui';
import { Icon } from './ui/icons';

/**
 * The refresh control on the tab row: a button that refreshes now, and a menu
 * of automatic intervals saved in this browser. Pages don't keep their own
 * timers. Each one registers a handler with useRefresh(), and the provider
 * calls every handler on each tick or click. The button spins and stays
 * disabled until every handler's promise settles.
 */

export const INTERVALS = [0, 5, 10, 30, 60];
const STORAGE_KEY = 'overseer.refresh';

type Handler = (manual: boolean) => Promise<unknown> | void;

interface RefreshState {
    seconds: number;
    setSeconds: (seconds: number) => void;
    busy: boolean;
    fire: (manual: boolean) => Promise<void>;
    register: (handler: Handler) => () => void;
}

const RefreshContext = createContext<RefreshState | null>(null);

function savedSeconds(): number {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved !== null && INTERVALS.includes(Number(saved))) return Number(saved);
    } catch {
        // Storage can be off in private windows.
    }
    return 5;
}

export function RefreshProvider({ children }: { children: ReactNode }) {
    const [seconds, setSecondsState] = useState(savedSeconds);
    const [busy, setBusy] = useState(false);
    const handlers = useRef(new Set<Handler>());
    const running = useRef(false);

    const fire = useCallback(async (manual: boolean) => {
        // Skip a tick while the last refresh is still running.
        if (running.current) return;
        running.current = true;
        setBusy(true);
        try {
            const pending = [...handlers.current].map((handler) => handler(manual));
            // Spin for at least half a second, so a fast refresh doesn't just flash.
            await Promise.allSettled([...pending, new Promise((resolve) => setTimeout(resolve, 500))]);
        } finally {
            running.current = false;
            setBusy(false);
        }
    }, []);

    const setSeconds = useCallback((value: number) => {
        setSecondsState(value);
        try {
            localStorage.setItem(STORAGE_KEY, String(value));
        } catch {
            // Keep it for this page only.
        }
    }, []);

    useEffect(() => {
        if (seconds === 0) return;
        const timer = setInterval(() => !document.hidden && fire(false), seconds * 1000);
        // Catch up when the tab comes back, since hidden tabs skip ticks.
        const onVisible = () => !document.hidden && fire(false);
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [seconds, fire]);

    const register = useCallback((handler: Handler) => {
        handlers.current.add(handler);
        return () => {
            handlers.current.delete(handler);
        };
    }, []);

    const value = useMemo(() => ({ seconds, setSeconds, busy, fire, register }), [seconds, setSeconds, busy, fire, register]);

    return <RefreshContext.Provider value={value}>{children}</RefreshContext.Provider>;
}

export function useRefreshState(): RefreshState {
    const state = useContext(RefreshContext);
    if (!state) throw new Error('useRefreshState outside RefreshProvider');
    return state;
}

/** Calls handler on every refresh. Return a promise to keep the button spinning until it settles. */
export function useRefresh(handler: Handler): void {
    const { register } = useRefreshState();
    const latest = useRef(handler);
    latest.current = handler;
    useEffect(() => register((manual) => latest.current(manual)), [register]);
}

export function intervalLabel(seconds: number): string {
    if (seconds === 0) return t('refresh.off');
    return seconds < 60 ? t('refresh.seconds', { n: seconds }) : t('refresh.minute');
}

export function RefreshControl() {
    const { seconds, setSeconds, busy, fire } = useRefreshState();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => !root.current?.contains(e.target as Node) && setOpen(false);
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onDown);
        window.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            window.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="us-refresh" ref={root}>
            <button type="button" className="us-refresh-now" onClick={() => fire(true)} disabled={busy} aria-busy={busy} title={t('refresh.now')}>
                <Icon icon={IconRefresh} className={cx('us-refresh-icon', busy && 'is-spinning')} />
                <span>{t('refresh.now')}</span>
            </button>
            <button type="button" className="us-refresh-menu" onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-haspopup="menu" title={t('refresh.auto')}>
                <span className="us-refresh-current">{intervalLabel(seconds)}</span>
                <Icon icon={IconChevronDown} className="us-refresh-icon" />
            </button>
            {open && (
                <div className="us-refresh-list" role="menu">
                    <div className="us-refresh-head">{t('refresh.auto')}</div>
                    {INTERVALS.map((option) => (
                        <button
                            key={option}
                            type="button"
                            role="menuitemradio"
                            aria-checked={seconds === option}
                            className={cx(seconds === option && 'is-on')}
                            onClick={() => {
                                setSeconds(option);
                                setOpen(false);
                            }}
                        >
                            <span>{intervalLabel(option)}</span>
                            {seconds === option && <Icon icon={IconCheck} className="us-refresh-icon" />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
