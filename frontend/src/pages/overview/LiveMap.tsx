import { forwardRef, useCallback, useEffect, useImperativeHandle, useLayoutEffect, useReducer, useRef, useState } from 'react';
import { useBoot, headUrl } from '../../boot';
import { getJson } from '../../api';
import { t } from '../../lang';
import { notifyDone } from '../../notify';
import { useRefreshState } from '../../refresh';
import { sendPlayerAction, type PlayerAction } from '../../shared/PlayerActions';
import { useAction } from '../../useAction';
import { cx } from '../../ui';
import { MapEngine } from './mapEngine';
import type { MapConfig, MapPlayer } from './types';

export interface LiveMapHandle {
    focus: (player: MapPlayer) => void;
    reloadTiles: () => void;
}

interface Point {
    world: string;
    x: number;
    z: number;
    y: number | null;
    loading: boolean;
    failed: boolean;
    player: string;
}

interface Props {
    config: MapConfig;
    players: MapPlayer[];
    state: 'loading' | 'live' | 'stale';
    fetching: boolean;
    updatedAt: number | null;
    selected: string | null;
    onSelect: (name: string | null) => void;
    onAct: (action: PlayerAction, player: MapPlayer) => void;
    onChanged: () => void;
}

/** The map viewport: tiles and markers from MapEngine, with the controls and popovers on top. */
export const LiveMap = forwardRef<LiveMapHandle, Props>(function LiveMap({ config, players, state, fetching, updatedAt, selected, onSelect, onAct, onChanged }, ref) {
    const boot = useBoot();
    const { seconds } = useRefreshState();
    const viewport = useRef<HTMLDivElement>(null);
    const tiles = useRef<HTMLDivElement>(null);
    const pins = useRef<HTMLDivElement>(null);
    const grid = useRef<HTMLCanvasElement>(null);
    const engine = useRef<MapEngine | null>(null);
    const [world, setWorld] = useState(config.worlds[0]?.name ?? '');
    const [coords, setCoords] = useState('');
    const [point, setPoint] = useState<Point | null>(null);
    // Bumped when the view moves, so the popovers follow their block.
    const [, redraw] = useReducer((n: number) => n + 1, 0);
    const [now, setNow] = useState(() => Date.now());
    const [sending, teleport] = useAction();

    // Kept in refs, because the engine's callbacks outlive each render.
    const latest = useRef({ selected, point, players, onSelect });
    latest.current = { selected, point, players, onSelect };

    useLayoutEffect(() => {
        if (!viewport.current || !tiles.current || !pins.current || !grid.current || config.worlds.length === 0) return;
        let frame = 0;
        const map = new MapEngine(
            { viewport: viewport.current, tiles: tiles.current, pins: pins.current, grid: grid.current },
            {
                mode: config.mode,
                tileBase: boot.tileBase,
                head: (name) => headUrl(boot, name),
                onPin: (name) => latest.current.onSelect(latest.current.selected === name ? null : name),
                onClick: (e) => {
                    const { selected, point } = latest.current;
                    // A click on empty map opens the point menu, unless it closed a popup.
                    if (selected || point) {
                        latest.current.onSelect(null);
                        setPoint(null);
                    } else if (boot.can.cheat) {
                        openPoint(map.blockAt(e), map.world);
                    }
                },
                onCoords: (b) => setCoords(b ? `x ${b.x} · z ${b.z}` : ''),
                onView: () => {
                    cancelAnimationFrame(frame);
                    frame = requestAnimationFrame(redraw);
                },
                onEscape: () => {
                    latest.current.onSelect(null);
                    setPoint(null);
                },
            },
            config.worlds,
        );
        engine.current = map;
        map.setPlayers(latest.current.players);
        map.render();
        setWorld(map.world);
        return () => {
            cancelAnimationFrame(frame);
            map.destroy();
            engine.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config.mode, config.worlds]);

    useEffect(() => {
        engine.current?.setPlayers(players);
    }, [players]);

    useEffect(() => {
        engine.current?.setSelected(selected);
    }, [selected]);

    // With automatic refresh off, "Updated 12s ago" counts up.
    useEffect(() => {
        if (seconds > 0) return;
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [seconds]);

    useImperativeHandle(ref, () => ({
        focus: (player) => {
            const map = engine.current;
            if (!map) return;
            setPoint(null);
            map.focus(player);
            setWorld(map.world);
        },
        reloadTiles: () => engine.current?.reloadTiles(),
    }));

    const openPoint = useCallback(
        (block: { x: number; z: number }, pointWorld: string) => {
            const next: Point = { world: pointWorld, x: block.x, z: block.z, y: null, loading: true, failed: false, player: latest.current.players[0]?.name ?? '' };
            setPoint(next);
            const url = `${boot.surfaceUrl}?${new URLSearchParams({ world: pointWorld, x: String(block.x), z: String(block.z) })}`;
            getJson<{ y: number | null }>(url).then(
                ({ y }) => setPoint((p) => (p && p.x === next.x && p.z === next.z && p.world === next.world ? { ...p, y, loading: false, failed: y === null } : p)),
                () => setPoint((p) => (p && p.x === next.x && p.z === next.z ? { ...p, loading: false, failed: true } : p)),
            );
        },
        [boot],
    );

    const teleportHere = async () => {
        const p = point;
        if (!p || p.y === null || !p.player) return;
        const result = await teleport(() => sendPlayerAction(p.player, 'teleport', { to: 'coords', world: p.world, x: p.x, y: p.y, z: p.z }));
        if (result) {
            notifyDone(result);
            setPoint(null);
            onChanged();
        }
    };

    const map = engine.current;
    const worldLabel = (name: string | undefined) => config.worlds.find((w) => w.name === name)?.label ?? name ?? '';
    const size = map?.size() ?? { w: 0, h: 0 };

    // The point menu, beside the clicked block.
    let pointView = null;
    if (map && point && point.world === world) {
        const at = map.toScreen(point.x + 0.5, point.z + 0.5);
        const left = Math.max(8, Math.min(at.x + 12, size.w - 268));
        const top = Math.max(8, Math.min(at.y - 20, size.h - 190));
        pointView = (
            <div>
                <span className="us-point-dot" style={{ left: at.x, top: at.y }} aria-hidden="true" />
                <div className="us-pop us-point" style={{ left, top }} onPointerDown={(e) => e.stopPropagation()} role="dialog" aria-label={t('map.point.title')}>
                    <div className="us-point-coords">
                        <span>{`x ${point.x}`}</span>
                        <span title={point.loading ? t('map.point.finding') : undefined}>{point.loading ? 'y …' : point.y === null ? 'y ?' : `y ${point.y}`}</span>
                        <span>{`z ${point.z}`}</span>
                    </div>
                    <div className="us-pop-where">{worldLabel(point.world)}</div>
                    {point.failed && <div className="us-point-note">{t('map.point.no_ground')}</div>}
                    {!point.loading && point.world.includes('nether') && <div className="us-point-note">{t('map.point.nether')}</div>}
                    {players.length > 0 ? (
                        <div className="us-point-tp">
                            <select value={point.player} onChange={(e) => setPoint({ ...point, player: e.target.value })} aria-label={t('map.point.player')} disabled={sending}>
                                {players.map((pl) => (
                                    <option key={pl.name} value={pl.name}>
                                        {pl.name}
                                    </option>
                                ))}
                            </select>
                            <button type="button" className="us-pop-btn is-primary" onClick={teleportHere} disabled={point.loading || point.y === null || sending} aria-busy={sending}>
                                {t('map.point.teleport')}
                            </button>
                        </div>
                    ) : (
                        <div className="us-point-note">{t('map.point.nobody')}</div>
                    )}
                </div>
            </div>
        );
    } else if (point && point.world !== world) {
        // The point belongs to another world. Drop it on the next render.
        queueMicrotask(() => setPoint(null));
    }

    // The selected player's popover.
    const player = players.find((p) => p.name === selected);
    let popView = null;
    if (map && player && player.world === world) {
        const at = map.toScreen(player.x + 0.5, player.z + 0.5);
        const left = at.x + 250 > size.w ? at.x - 256 : at.x + 18;
        const top = Math.max(8, Math.min(at.y - 60, size.h - 190));
        const act = (action: PlayerAction) => {
            onSelect(null);
            onAct(action, player);
        };
        popView = (
            <div className="us-pop" style={{ left: Math.max(8, left), top }} onPointerDown={(e) => e.stopPropagation()}>
                <div className="us-pop-head">
                    <img src={headUrl(boot, player.name)} alt="" />
                    <div>
                        <strong>{player.name}</strong>
                        {player.op && <span className="us-badge">{t('map.op')}</span>}
                        {player.health !== null && <div className="us-row-where">{`${player.health} / 20 ${t('map.health')}`}</div>}
                    </div>
                </div>
                <div className="us-pop-where">{`${worldLabel(player.world)} · ${player.x}, ${player.y ?? '?'}, ${player.z}`}</div>
                <div className="us-pop-actions">
                    {boot.can.kick && (
                        <button type="button" className="us-pop-btn is-warning" onClick={() => act('kick')}>
                            {t('players.actions.kick')}
                        </button>
                    )}
                    {boot.can.ban && (
                        <button type="button" className="us-pop-btn is-danger" onClick={() => act('ban')}>
                            {t('players.actions.ban')}
                        </button>
                    )}
                    {boot.can.cheat && (
                        <button type="button" className="us-pop-btn is-gray" onClick={() => act('gamemode')}>
                            {t('players.actions.gamemode')}
                        </button>
                    )}
                    {boot.can.op && (
                        <button type="button" className="us-pop-btn is-gray" onClick={() => act(player.op ? 'deop' : 'op')}>
                            {t(player.op ? 'players.actions.deop' : 'players.actions.op')}
                        </button>
                    )}
                </div>
            </div>
        );
    }

    const ago = Math.max(0, Math.round((now - (updatedAt ?? now)) / 1000));
    const updatedText = ago < 60 ? t('map.updated_seconds', { n: ago }) : t('map.updated_minutes', { n: Math.floor(ago / 60) });

    return (
        <div ref={viewport} className="us-viewport" tabIndex={0} role="application" aria-label={t('map.aria')}>
            <canvas ref={grid} className="us-grid" style={{ display: config.mode === 'grid' ? undefined : 'none' }} />
            <div ref={tiles} className="us-tiles" />
            <div ref={pins} className="us-pins" />

            <div className="us-controls">
                <div className="us-seg" role="group" aria-label={t('map.world')}>
                    {config.worlds.map((w) => (
                        <button
                            key={w.name}
                            type="button"
                            className={cx(w.name === world && 'is-on')}
                            aria-pressed={w.name === world}
                            onClick={() => {
                                engine.current?.setWorld(w.name);
                                setWorld(w.name);
                                onSelect(null);
                            }}
                        >
                            {w.label}
                        </button>
                    ))}
                </div>
                <div className="us-seg" role="group" aria-label={t('map.zoom')}>
                    <button type="button" onClick={() => engine.current?.zoomBy(1)} aria-label={t('map.zoom_in')}>
                        +
                    </button>
                    <button type="button" onClick={() => engine.current?.zoomBy(-1)} aria-label={t('map.zoom_out')}>
                        &minus;
                    </button>
                </div>
            </div>

            <div className="us-chip us-live">
                <span className={cx('us-led', state === 'stale' && 'is-stale', state === 'live' && seconds === 0 && 'is-paused', fetching && seconds > 0 && 'is-fetching')} />
                {state === 'live' && seconds > 0 && <span>{t('map.live')}</span>}
                {state === 'live' && seconds === 0 && <span>{updatedText}</span>}
                {state === 'loading' && <span>{t('map.loading')}</span>}
                {state === 'stale' && <span>{t('map.stale')}</span>}
            </div>
            {coords && <div className="us-chip us-coords">{coords}</div>}

            {pointView}
            {popView}
        </div>
    );
});
