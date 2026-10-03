import { useCallback, useEffect, useLayoutEffect, useRef, useState, type FormEvent } from 'react';
import useSWR from 'swr';
import {
    IconArrowsMove,
    IconBolt,
    IconCloudRain,
    IconDeviceFloppy,
    IconMapOff,
    IconMoon,
    IconRefresh,
    IconSend,
    IconSpeakerphone,
    IconSun,
    IconSunHigh,
    IconSunrise,
    IconSunset2,
    IconTerminal2,
} from '@tabler/icons-react';
import { postJson } from '../../api';
import { headUrl, useBoot } from '../../boot';
import { t } from '../../lang';
import { notifyDone } from '../../notify';
import { useRefresh } from '../../refresh';
import { TeleportCoordsModal, usePlayerActions } from '../../shared/PlayerActions';
import { Badge, Button, Field, Loading, Modal, ModalActions, Section, Skeleton, SkeletonLines, SkeletonRow, Spinner, TextInput, cx } from '../../ui';
import { Icon, type IconComponent } from '../../ui/icons';
import { useAction } from '../../useAction';
import { LiveMap, type LiveMapHandle } from './LiveMap';
import type { ChatLine, Feed, GameTime, MapConfig, Stats } from './types';

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

export default function Overview() {
    const boot = useBoot();
    const canSeeMap = boot.can.mapView;
    const canCommand = boot.can.commandsWorld || boot.can.commandsOps;

    const map = useSWR<MapConfig>(canSeeMap ? '/map' : null);
    const feed = useSWR<Feed>(canSeeMap ? boot.feedUrl : null);
    const stats = useSWR<Stats>(canSeeMap ? '/stats' : null);
    const recent = useSWR<{ lines: string[] }>(canCommand ? '/recent' : null);

    const mapRef = useRef<LiveMapHandle>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const [fetching, setFetching] = useState(false);
    const [updatedAt, setUpdatedAt] = useState<number | null>(null);
    const counters = useRef({ refreshes: 0, statsAt: Date.now() });

    const reloadFeed = useCallback(async () => {
        const data = await feed.mutate();
        if (data) setUpdatedAt(Date.now());
        return data;
    }, [feed]);

    // Positions on every refresh. The stat cards at most every 15 s, since Pelican
    // caches resource usage that long, or right away for a click.
    useRefresh((manual) => {
        const jobs: Promise<unknown>[] = [];
        if (canSeeMap) {
            jobs.push(reloadFeed());
            // Terrain changes as players build and explore, so reload the visible
            // tiles every fifth refresh, or right away for a click.
            counters.current.refreshes++;
            if (manual || counters.current.refreshes % 5 === 0) mapRef.current?.reloadTiles();
        }
        const now = Date.now();
        if (manual || now - counters.current.statsAt >= 15000) {
            counters.current.statsAt = now;
            if (canSeeMap) jobs.push(stats.mutate());
            if (canCommand) jobs.push(recent.mutate());
        }
        if (!canSeeMap) return Promise.allSettled(jobs);

        // The Live dot pulses for the refresh, at least 600 ms so a fast one is still seen.
        setFetching(true);
        const all = Promise.allSettled([...jobs, sleep(600)]);
        all.then(() => setFetching(false));
        return all;
    });

    // The first feed counts as an update for the "Updated Xs ago" chip.
    useEffect(() => {
        if (feed.data && updatedAt === null) setUpdatedAt(Date.now());
    }, [feed.data, updatedAt]);

    const afterCommand = useCallback(() => {
        recent.mutate();
    }, [recent]);

    const afterPlayerAction = useCallback(() => {
        reloadFeed();
        recent.mutate();
    }, [reloadFeed, recent]);

    const actions = usePlayerActions(afterPlayerAction);

    // True only before the first response. A refresh keeps the old data on screen.
    const feedLoading = !feed.data && !feed.error;
    const statsLoading = !stats.data && !stats.error;
    const feedState: 'loading' | 'live' | 'stale' = feed.error ? 'stale' : !feed.data ? 'loading' : feed.data.ok ? 'live' : 'stale';
    const players = feed.data?.players ?? [];
    const unmapped = feed.data?.unmapped ?? [];
    const worldLabel = (name: string) => map.data?.worlds.find((w) => w.name === name)?.label ?? name;

    return (
        <div className="us-overview">
            {canSeeMap && (
                <div className="us-stats">
                    <StatCards stats={stats.data} loading={statsLoading} />
                    {canCommand && <ServerCard onDone={afterCommand} />}
                </div>
            )}

            {canSeeMap && map.data?.setup && <MapSetup setup={map.data.setup} onChecked={(config) => map.mutate(config, { revalidate: false })} />}

            {canSeeMap && (
                <div className="us-map-wrap">
                    <div className="us-map">
                        <div>
                            {map.data ? (
                                <LiveMap
                                    ref={mapRef}
                                    config={map.data}
                                    players={players}
                                    state={feedState}
                                    fetching={fetching}
                                    updatedAt={updatedAt}
                                    selected={selected}
                                    onSelect={setSelected}
                                    onAct={(action, player) => actions.open(action, { name: player.name })}
                                    onChanged={afterPlayerAction}
                                />
                            ) : (
                                <div className="us-viewport is-skeleton">
                                    <Skeleton width="100%" height="100%" />
                                    <Loading />
                                </div>
                            )}
                        </div>

                        <div className="us-side">
                            <Chat lines={feed.data?.chat ?? []} loading={feedLoading} onSent={reloadFeed} />

                            <Section heading={t('map.online')} compact afterHeader={feedLoading ? <Skeleton width="1.75rem" height="1.4rem" /> : <Badge color="success">{players.length + unmapped.length}</Badge>}>
                                <div className="us-list">
                                    {feedLoading && (
                                        <>
                                            <SkeletonRow />
                                            <SkeletonRow />
                                        </>
                                    )}
                                    {players.map((p) => (
                                        <button
                                            key={p.name}
                                            type="button"
                                            className={cx('us-row', selected === p.name && 'is-selected')}
                                            aria-pressed={selected === p.name}
                                            onClick={() => {
                                                mapRef.current?.focus(p);
                                                setSelected(p.name);
                                            }}
                                        >
                                            <img src={headUrl(boot, p.name)} alt="" />
                                            <div style={{ minWidth: 0 }}>
                                                <div className="us-row-name">
                                                    <span>{p.name}</span>
                                                    {p.op && <span className="us-badge">{t('map.op')}</span>}
                                                </div>
                                                <div className="us-row-where">{`${worldLabel(p.world)} · ${p.x}, ${p.z}`}</div>
                                            </div>
                                        </button>
                                    ))}
                                    {unmapped.map((u) => (
                                        <div key={u.name} className="us-row is-unmapped">
                                            <img src={headUrl(boot, u.name)} alt="" />
                                            <div style={{ minWidth: 0 }}>
                                                <div className="us-row-name">
                                                    <span>{u.name}</span>
                                                    {u.op && <span className="us-badge">{t('map.op')}</span>}
                                                </div>
                                                <div className="us-row-where">{u.dead ? t('map.dead') : t('map.not_on_map')}</div>
                                            </div>
                                        </div>
                                    ))}
                                    {players.length === 0 && unmapped.length === 0 && feedState === 'live' && <div className="us-empty">{t('map.nobody')}</div>}
                                    {feedState === 'stale' && <div className="us-empty">{map.data?.mode === 'squaremap' ? t('map.no_positions_squaremap') : t('map.no_positions_rcon')}</div>}
                                </div>
                            </Section>

                            {boot.can.commandsWorld && <QuickActions time={feed.data?.time ?? null} withClock loading={feedLoading} onDone={afterCommand} onTeleported={afterPlayerAction} />}

                            <MinecraftCard server={feed.data?.server} loading={feedLoading} />
                        </div>
                    </div>
                </div>
            )}

            {!canSeeMap && canCommand && (
                <div className="us-side us-side-alone">
                    <div className="us-stats us-stats-alone">
                        <ServerCard onDone={afterCommand} />
                    </div>
                    {boot.can.commandsWorld && <QuickActions time={null} withClock={false} onDone={afterCommand} onTeleported={afterPlayerAction} />}
                </div>
            )}

            {canCommand && (
                <Section heading={t('commands.recent.title')} description={t('commands.recent.help')} compact>
                    {!recent.data && !recent.error ? (
                        <SkeletonLines lines={5} />
                    ) : recent.data?.lines.length ? (
                        <ul className="us-log">
                            {recent.data.lines.map((line, i) => (
                                <li key={i}>{line}</li>
                            ))}
                        </ul>
                    ) : (
                        <p className="us-help">{recent.data ? t('commands.recent.empty') : ''}</p>
                    )}
                </Section>
            )}

            {actions.modal}
        </div>
    );
}

function percent(used: number | null, limit: number): number | null {
    return used !== null && limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : null;
}

function StatCard({ label, value, sub, bar, loading }: { label: string; value: string | null; sub: string; bar: number | null; loading: boolean }) {
    if (loading) {
        return (
            <div className="us-stat">
                <div className="us-stat-label">{label}</div>
                <Skeleton width="55%" height="1.35rem" style={{ margin: '0.3rem 0 0.35rem' }} />
                <Skeleton width="40%" height="0.7rem" />
                <Loading />
            </div>
        );
    }
    return (
        <div className="us-stat">
            <div className="us-stat-label">{label}</div>
            <div className={cx('us-stat-value', value === null && 'is-empty')}>{value ?? t('overview.none')}</div>
            <div className="us-stat-sub">{sub}</div>
            {bar !== null && (
                <div className="us-bar">
                    <span style={{ width: `${bar}%` }} />
                </div>
            )}
        </div>
    );
}

function StatCards({ stats, loading }: { stats: Stats | undefined; loading: boolean }) {
    const of = (limit: string | null) => (limit ? t('overview.of', { limit }) : t('overview.no_limit'));
    return (
        <>
            <StatCard label={t('overview.cpu')} value={stats?.cpu != null ? `${stats.cpu.toFixed(1)}%` : null} sub={stats && stats.cpu_limit > 0 ? of(`${stats.cpu_limit}%`) : of(null)} bar={stats ? percent(stats.cpu, stats.cpu_limit) : null} loading={loading} />
            <StatCard label={t('overview.memory')} value={stats?.memory_text ?? null} sub={of(stats?.memory_limit_text ?? null)} bar={stats ? percent(stats.memory, stats.memory_limit) : null} loading={loading} />
            <StatCard label={t('overview.disk')} value={stats?.disk_text ?? null} sub={of(stats?.disk_limit_text ?? null)} bar={stats ? percent(stats.disk, stats.disk_limit) : null} loading={loading} />
        </>
    );
}

function MapSetup({ setup, onChecked }: { setup: { title: string; body: string }; onChecked: (config: MapConfig) => void }) {
    const [busy, run] = useAction();
    return (
        <Section icon={IconMapOff} heading={setup.title} description={setup.body} compact className="us-setup">
            <Button
                size="sm"
                icon={IconRefresh}
                busy={busy}
                onClick={async () => {
                    // Asks the server to look for squaremap again, for after an admin sets it up.
                    const config = await run(() => postJson<MapConfig>('/map'));
                    if (config) onChecked(config);
                }}
            >
                {t('map.check_again')}
            </Button>
        </Section>
    );
}

/** One command button. Only this button is disabled while its command runs. */
function CommandButton({ name, icon, label, title, className, iconStyle, onDone }: { name: string; icon: IconComponent; label: string; title: string; className?: string; iconStyle?: React.CSSProperties; onDone: () => void }) {
    const [busy, run] = useAction();
    return (
        <button
            type="button"
            className={className}
            title={title}
            disabled={busy}
            aria-busy={busy}
            onClick={async () => {
                const result = await run(() => postJson<{ title: string; body: string | null }>(`/commands/${name}`));
                if (result) {
                    notifyDone(result);
                    onDone();
                }
            }}
        >
            {busy ? <Spinner className="fi-icon us-tile-icon" /> : <Icon icon={icon} className="us-tile-icon" style={iconStyle} />}
            <span className={className === 'us-tile-btn' ? 'us-tile-title' : undefined}>{label}</span>
        </button>
    );
}

/** The Server card in the top row: save, broadcast and run a command. */
function ServerCard({ onDone }: { onDone: () => void }) {
    const boot = useBoot();
    const [form, setForm] = useState<'broadcast' | 'custom' | null>(null);

    return (
        <div className="us-stat">
            <div className="us-stat-label">{t('commands.server.title')}</div>
            <div className="us-btn-group" role="group" aria-label={t('commands.server.title')}>
                {boot.can.commandsWorld && <CommandButton name="save" icon={IconDeviceFloppy} label={t('commands.short.save')} title={t('commands.buttons.save')} onDone={onDone} />}
                {boot.can.commandsOps && (
                    <>
                        <button type="button" title={t('commands.buttons.broadcast')} onClick={() => setForm('broadcast')}>
                            <Icon icon={IconSpeakerphone} />
                            <span>{t('commands.short.broadcast')}</span>
                        </button>
                        <button type="button" title={t('commands.buttons.custom')} onClick={() => setForm('custom')}>
                            <Icon icon={IconTerminal2} />
                            <span>{t('commands.short.custom')}</span>
                        </button>
                    </>
                )}
            </div>
            {form && <CommandForm kind={form} onClose={() => setForm(null)} onDone={onDone} />}
        </div>
    );
}

function CommandForm({ kind, onClose, onDone }: { kind: 'broadcast' | 'custom'; onClose: () => void; onDone: () => void }) {
    const [busy, run] = useAction();
    const [text, setText] = useState('');
    const valid = text.trim() !== '';

    const submit = async () => {
        const result = await run(() => postJson<{ title: string; body: string | null }>(`/commands/${kind}`, kind === 'broadcast' ? { message: text } : { command: text }));
        if (result) {
            notifyDone(result);
            onClose();
            onDone();
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            heading={t(`commands.buttons.${kind}`)}
            busy={busy}
            onSubmit={() => valid && submit()}
            footer={<ModalActions submitLabel={t(kind === 'broadcast' ? 'commands.send' : 'commands.run')} busy={busy} disabled={!valid} onCancel={onClose} />}
        >
            {kind === 'broadcast' ? (
                <Field label={t('commands.server.message')}>
                    <div className="fi-input-wrp">
                        <div className="fi-input-wrp-content-ctn">
                            <textarea className="fi-input us-textarea" rows={2} maxLength={200} required value={text} onChange={(e) => setText(e.target.value)} />
                        </div>
                    </div>
                </Field>
            ) : (
                <Field label={t('commands.server.command')}>
                    <TextInput prefix="/" placeholder="gamerule players_sleeping_percentage 50" required value={text} onChange={(e) => setText(e.target.value)} />
                </Field>
            )}
        </Modal>
    );
}

const PHASE_ICONS: Record<GameTime['phase'], string> = { day: '☀️', sunset: '\u{1F307}', night: '\u{1F319}', sunrise: '\u{1F305}' };

const TIME_TILES: Array<[string, IconComponent, string, string]> = [
    ['sunrise', IconSunrise, 'time set 0', '#f97316'],
    ['noon', IconSun, 'time set noon', '#eab308'],
    ['sunset', IconSunset2, 'time set 12000', '#ea580c'],
    ['midnight', IconMoon, 'time set midnight', '#818cf8'],
];

const WEATHER_TILES: Array<[string, IconComponent, string, string]> = [
    ['clear', IconSunHigh, 'weather clear', '#38bdf8'],
    ['rain', IconCloudRain, 'weather rain', '#3b82f6'],
    ['thunder', IconBolt, 'weather thunder', '#a855f7'],
];

function Tiles({ tiles, onDone }: { tiles: Array<[string, IconComponent, string, string]>; onDone: () => void }) {
    return (
        <div className="us-tiles-grid" style={{ '--us-tile-count': tiles.length } as React.CSSProperties}>
            {tiles.map(([name, icon, command, color]) => (
                <CommandButton key={name} name={name} icon={icon} label={t(`commands.buttons.${name}`)} title={`/${command}`} className="us-tile-btn" iconStyle={{ color }} onDone={onDone} />
            ))}
        </div>
    );
}

/** The Quick actions card: time, weather and teleport. */
function QuickActions({ time, withClock, loading = false, onDone, onTeleported }: { time: GameTime | null; withClock: boolean; loading?: boolean; onDone: () => void; onTeleported: () => void }) {
    const boot = useBoot();
    const [teleporting, setTeleporting] = useState(false);

    return (
        <Section heading={t('commands.quick')} compact>
            <div className="us-qa">
                <div className="us-qa-head">
                    <span className="us-stat-label">{t('overview.game_time')}</span>
                    {withClock && loading && <Skeleton width="9rem" height="1.25rem" />}
                    {withClock && time && (
                        <span className="us-time">
                            <span className="us-time-icon" title={t(`overview.phases.${time.phase}`)} aria-hidden="true">
                                {PHASE_ICONS[time.phase] ?? ''}
                            </span>
                            <span className="us-qa-clock">{time.clock}</span>
                            <span className="us-stat-sub">{t('overview.day', { day: time.day.toLocaleString() })}</span>
                        </span>
                    )}
                </div>
                <Tiles tiles={TIME_TILES} onDone={onDone} />

                <div className="us-qa-head">
                    <span className="us-stat-label">{t('commands.weather.title')}</span>
                </div>
                <Tiles tiles={WEATHER_TILES} onDone={onDone} />

                {boot.can.cheat && (
                    <>
                        <div className="us-qa-head">
                            <span className="us-stat-label">{t('commands.players')}</span>
                        </div>
                        <div className="us-tiles-grid" style={{ '--us-tile-count': 1 } as React.CSSProperties}>
                            <button type="button" className="us-tile-btn" title={t('commands.buttons.teleport')} onClick={() => setTeleporting(true)}>
                                <Icon icon={IconArrowsMove} className="us-tile-icon" style={{ color: '#10b981' }} />
                                <span className="us-tile-title">{t('commands.buttons.teleport')}</span>
                            </button>
                        </div>
                    </>
                )}
            </div>
            {teleporting && <TeleportCoordsModal onClose={() => setTeleporting(false)} onDone={onTeleported} />}
        </Section>
    );
}

function MinecraftCard({ server, loading }: { server: Feed['server'] | undefined; loading: boolean }) {
    const modpack = server?.modpack;
    if (loading) {
        return (
            <Section compact>
                <div className="us-stat-label">{t('overview.minecraft')}</div>
                <Skeleton width="45%" height="1.35rem" style={{ margin: '0.3rem 0 0.4rem' }} />
                <Skeleton width="65%" height="0.7rem" style={{ marginBottom: '0.3rem' }} />
                <Skeleton width="35%" height="0.7rem" />
            </Section>
        );
    }
    return (
        <Section compact>
            <div className="us-stat-label">{t('overview.minecraft')}</div>
            <div className={cx('us-stat-value', !server?.version && 'is-empty')}>{server?.version ?? t('overview.none')}</div>
            <div className="us-stat-sub">
                {modpack ? (
                    <span title={`${modpack.name} ${modpack.version ?? ''}`}>
                        {modpack.url ? (
                            <a href={modpack.url} target="_blank" rel="noopener noreferrer">
                                {modpack.name}
                            </a>
                        ) : (
                            modpack.name
                        )}{' '}
                        {modpack.version ?? ''}
                    </span>
                ) : (
                    t('overview.no_modpack')
                )}
            </div>
            <div className="us-stat-sub">{server?.uptime ?? t('overview.offline')}</div>
        </Section>
    );
}

/**
 * Chat, joins and leaves, with a box to message everyone. It stays scrolled
 * to the newest line unless someone scrolled up to read.
 */
function Chat({ lines, loading, onSent }: { lines: ChatLine[]; loading: boolean; onSent: () => void }) {
    const boot = useBoot();
    const box = useRef<HTMLDivElement>(null);
    const stick = useRef(true);
    const [draft, setDraft] = useState('');
    const [sending, run] = useAction();

    useLayoutEffect(() => {
        const el = box.current;
        if (el && stick.current) el.scrollTop = el.scrollHeight;
    }, [lines]);

    const send = async (e: FormEvent) => {
        e.preventDefault();
        const text = draft.trim();
        if (!text) return;
        const result = await run(() => postJson('/chat', { message: text }));
        if (result) {
            setDraft('');
            stick.current = true;
            onSent();
        }
    };

    return (
        <Section heading={t('chat.title')} compact>
            <div
                className="us-chat"
                ref={box}
                aria-live="polite"
                onScroll={(e) => {
                    const el = e.currentTarget;
                    stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
                }}
            >
                {lines.map((m, i) => (
                    <div key={i} className={`us-chat-line is-${m.type}`}>
                        <span className="us-chat-time" title={t('chat.server_time')}>
                            {m.time.slice(0, 5)}
                        </span>
                        {m.type === 'chat' && (
                            <span>
                                <b>{m.name}</b> <span>{m.text}</span>
                            </span>
                        )}
                        {m.type === 'say' && (
                            <span>
                                <b>{`[${m.name}]`}</b> <span>{m.text}</span>
                            </span>
                        )}
                        {m.type === 'join' && <span>{t('chat.joined', { name: m.name })}</span>}
                        {m.type === 'leave' && <span>{t('chat.left', { name: m.name })}</span>}
                    </div>
                ))}
                {loading && <SkeletonLines lines={4} height="0.8rem" gap="0.5rem" />}
                {!loading && lines.length === 0 && <div className="us-empty">{t('chat.empty')}</div>}
            </div>
            {boot.can.commandsOps && (
                <form className="us-chat-send" onSubmit={send}>
                    <input type="text" value={draft} maxLength={200} placeholder={t('chat.placeholder')} aria-label={t('chat.placeholder')} disabled={sending} onChange={(e) => setDraft(e.target.value)} />
                    <button type="submit" disabled={sending || !draft.trim()} aria-label={t('chat.send')} title={t('chat.send')} aria-busy={sending}>
                        {sending ? <Spinner className="fi-icon" /> : <Icon icon={IconSend} />}
                    </button>
                </form>
            )}
        </Section>
    );
}

