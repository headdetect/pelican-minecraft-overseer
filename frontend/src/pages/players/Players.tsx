import { useCallback, useEffect, useMemo, useState } from 'react';
import useSWR from 'swr';
import {
    IconArrowsMove,
    IconCrown,
    IconCrownOff,
    IconDeviceGamepad2,
    IconDoorExit,
    IconDotsVertical,
    IconGift,
    IconHammer,
    IconPlaylistAdd,
    IconPlaylistX,
    IconRotate,
    IconSearch,
    IconUserPlus,
} from '@tabler/icons-react';
import { getJson } from '../../api';
import { headUrl, useBoot } from '../../boot';
import { t } from '../../lang';
import { useRefresh } from '../../refresh';
import { sendPlayerAction, usePlayerActions, type PlayerAction, type PlayerRef } from '../../shared/PlayerActions';
import { Badge, Button, Dropdown, Field, Modal, ModalActions, Select, Spinner, TextInput, cx, type Color, type MenuItem } from '../../ui';
import { useAction } from '../../useAction';
import { notifyDone } from '../../notify';

interface Row {
    name: string;
    is_online: boolean;
    last_seen: number | null;
    last_seen_text: string | null;
    last_seen_title: string | null;
    gamemode?: string | null;
    xp_level?: number | null;
    dimension?: string | null;
    x?: number | null;
    y?: number | null;
    z?: number | null;
}

interface Ban {
    name: string;
    reason: string | null;
    source: string | null;
    created: string | null;
    expires_text: string | null;
}

interface PlayerList {
    rcon: boolean;
    roster: string[];
    ops: string[];
    whitelist: string[];
    players: Record<string, Row>;
    banned: Ban[];
}

type Filter = 'all' | 'ops' | 'whitelist' | 'banned';
const FILTERS: Filter[] = ['all', 'ops', 'whitelist', 'banned'];
const PER_PAGE = [25, 50, 100];

function filterFromUrl(): Filter {
    const value = new URLSearchParams(window.location.search).get('tab');
    return FILTERS.includes(value as Filter) ? (value as Filter) : 'all';
}

const GAME_MODE_COLORS: Record<string, Color> = { creative: 'warning', spectator: 'gray', adventure: 'info', survival: 'success' };

function worldName(dimension: string): string {
    const id = dimension.replace(/^minecraft:/, '');
    return t(`map.worlds.${id === 'the_nether' ? 'nether' : id === 'the_end' ? 'end' : 'overworld'}`);
}

export default function Players() {
    const boot = useBoot();
    const { data, error, mutate } = useSWR<PlayerList>('/players');
    const [filter, setFilterState] = useState<Filter>(filterFromUrl);
    const [search, setSearch] = useState('');
    const [perPage, setPerPage] = useState(25);
    const [page, setPage] = useState(1);
    const [adding, setAdding] = useState(false);

    // A manual refresh reads the player files again instead of the 30-second cache.
    useRefresh((manual) => (manual ? mutate(getJson<PlayerList>('/players?fresh=1'), { revalidate: false }) : mutate()));

    const reload = useCallback(() => {
        mutate();
    }, [mutate]);
    const actions = usePlayerActions(reload);

    const setFilter = (next: Filter) => {
        setFilterState(next);
        setPage(1);
        const url = new URL(window.location.href);
        if (next === 'all') url.searchParams.delete('tab');
        else url.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', url);
    };

    useEffect(() => setPage(1), [search, perPage]);

    const banned = useMemo(() => new Set((data?.banned ?? []).map((b) => b.name.toLowerCase())), [data]);
    const ops = useMemo(() => new Set(data?.ops ?? []), [data]);
    const whitelist = useMemo(() => new Set(data?.whitelist ?? []), [data]);

    const counts: Record<Filter, number> = {
        all: data?.roster.length ?? 0,
        ops: data?.ops.length ?? 0,
        whitelist: data?.whitelist.length ?? 0,
        banned: data?.banned.length ?? 0,
    };

    const rows: Array<Row | Ban> = useMemo(() => {
        if (!data) return [];
        const row = (name: string): Row => data.players[name] ?? { name, is_online: false, last_seen: null, last_seen_text: null, last_seen_title: null };
        const list = filter === 'banned' ? data.banned : (filter === 'ops' ? data.ops : filter === 'whitelist' ? data.whitelist : data.roster).map(row);
        const needle = search.trim().toLowerCase();
        return needle ? list.filter((r) => r.name.toLowerCase().includes(needle)) : list;
    }, [data, filter, search]);

    const pages = Math.max(1, Math.ceil(rows.length / perPage));
    const shown = rows.slice((Math.min(page, pages) - 1) * perPage, Math.min(page, pages) * perPage);
    const isBannedTab = filter === 'banned';

    const menu = (record: Row | Ban): MenuItem[] => {
        const ref: PlayerRef = 'is_online' in record ? record : { name: record.name };
        const online = 'is_online' in record && record.is_online;
        const item = (action: PlayerAction, label: string, icon: MenuItem['icon'], color: Color = 'gray'): MenuItem => ({ label, icon, color, onSelect: () => actions.open(action, ref) });
        const items: MenuItem[] = [];
        const isOp = ops.has(record.name);
        const listed = whitelist.has(record.name);

        if (!isBannedTab && boot.can.op) items.push(item(isOp ? 'deop' : 'op', t(isOp ? 'players.actions.deop' : 'players.actions.op'), isOp ? IconCrownOff : IconCrown));
        if (!isBannedTab && boot.can.whitelist) items.push(item(listed ? 'whitelist-remove' : 'whitelist-add', t(listed ? 'players.actions.unwhitelist' : 'players.actions.whitelist'), listed ? IconPlaylistX : IconPlaylistAdd));
        if (online && boot.can.cheat) {
            items.push(item('gamemode', t('players.actions.gamemode'), IconDeviceGamepad2));
            items.push(item('give', t('players.actions.give'), IconGift));
            items.push(item('teleport', t('players.actions.teleport'), IconArrowsMove));
        }
        if (online && boot.can.kick) items.push(item('kick', t('players.actions.kick'), IconDoorExit, 'warning'));
        if (!isBannedTab && !banned.has(record.name.toLowerCase()) && boot.can.ban) items.push(item('ban', t('players.actions.ban'), IconHammer, 'danger'));
        if (isBannedTab && boot.can.ban) items.push(item('unban', t('players.actions.unban'), IconRotate, 'success'));
        return items;
    };

    const empty = search.trim() ? t('players.empty.no_match') : filter === 'all' ? t('players.empty.nobody') : t('players.empty.none');
    const from = rows.length ? (Math.min(page, pages) - 1) * perPage + 1 : 0;
    const to = Math.min(rows.length, from + perPage - 1);

    return (
        <div className="fi-ta">
            <div className="fi-ta-ctn fi-ta-ctn-with-footer fi-ta-ctn-with-header">
                <div className="fi-ta-main">
                    <div className="fi-ta-header-ctn">
                        <div className="us-filters-bar">
                            <div className="us-filters" role="group" aria-label={t('players.title')}>
                                {FILTERS.map((key) => (
                                    <button key={key} type="button" className={cx(filter === key && 'is-on')} aria-pressed={filter === key} onClick={() => setFilter(key)}>
                                        {t(`players.tabs.${key}`)}
                                        {data && <span className="us-count">{counts[key]}</span>}
                                    </button>
                                ))}
                            </div>
                            <div className="us-filters-actions">
                                {boot.can.whitelist && (
                                    <Button size="sm" color="primary" icon={IconUserPlus} onClick={() => setAdding(true)}>
                                        {t('players.add_to_whitelist')}
                                    </Button>
                                )}
                            </div>
                        </div>
                        <div className="fi-ta-header-toolbar">
                            <div />
                            <div className="fi-ta-search-field">
                                <label className="fi-sr-only" htmlFor="us-player-search">
                                    {t('ui.search')}
                                </label>
                                <TextInput id="us-player-search" type="search" prefixIcon={IconSearch} placeholder={t('ui.search')} autoComplete="off" value={search} onChange={(e) => setSearch(e.target.value)} />
                            </div>
                        </div>
                    </div>

                    <div className="fi-ta-content-ctn">
                        {!data && !error ? (
                            <div className="us-loading">
                                <Spinner />
                            </div>
                        ) : shown.length === 0 ? (
                            <div className="fi-ta-empty-state">
                                <div className="fi-ta-empty-state-content">
                                    <h4 className="fi-ta-empty-state-heading">{error ? t('players.notifications.failed') : empty}</h4>
                                </div>
                            </div>
                        ) : (
                            <table className="fi-ta-table">
                                <thead>
                                    <tr>
                                        <th scope="col" className="fi-ta-header-cell" />
                                        <th scope="col" className="fi-ta-header-cell">{t('players.columns.name')}</th>
                                        <th scope="col" className="fi-ta-header-cell">{t('players.columns.role')}</th>
                                        {isBannedTab ? (
                                            <>
                                                <th scope="col" className="fi-ta-header-cell">{t('players.columns.reason')}</th>
                                                <th scope="col" className="fi-ta-header-cell">{t('players.columns.expires')}</th>
                                            </>
                                        ) : (
                                            <>
                                                <th scope="col" className="fi-ta-header-cell">{t('players.columns.game_mode')}</th>
                                                <th scope="col" className="fi-ta-header-cell">{t('players.columns.level')}</th>
                                                <th scope="col" className="fi-ta-header-cell">{t('players.columns.world')}</th>
                                                <th scope="col" className="fi-ta-header-cell">{t('players.columns.last_online')}</th>
                                            </>
                                        )}
                                        <th aria-label={t('players.actions.menu')} scope="col" className="fi-ta-actions-header-cell fi-ta-empty-header-cell" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {shown.map((record) => {
                                        const items = menu(record);
                                        const roles: Array<[string, Color]> = [];
                                        if (ops.has(record.name)) roles.push([t('map.op'), 'warning']);
                                        if (whitelist.has(record.name)) roles.push([t('players.whitelisted'), 'info']);
                                        if (!isBannedTab && banned.has(record.name.toLowerCase())) roles.push([t('players.banned'), 'danger']);
                                        return (
                                            <tr key={record.name} className="fi-ta-row">
                                                <td className="fi-ta-cell us-head-cell">
                                                    <div className="fi-ta-col">
                                                        <div className="fi-ta-image">
                                                            <img alt="" src={headUrl(boot, record.name)} style={{ height: 32, width: 32 }} />
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="fi-ta-cell">
                                                    <div className="fi-ta-col">
                                                        <div className="fi-ta-text-item fi-size-sm fi-font-medium fi-ta-text">{record.name}</div>
                                                    </div>
                                                </td>
                                                <td className="fi-ta-cell">
                                                    <div className="fi-ta-col">
                                                        <div className="fi-ta-text-item fi-ta-text fi-ta-text-has-badges us-badges">
                                                            {roles.map(([label, color]) => (
                                                                <Badge key={label} color={color} size="sm">
                                                                    {label}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </td>
                                                {'is_online' in record ? <PlayerCells row={record} /> : <BanCells ban={record} />}
                                                <td className="fi-ta-cell">
                                                    <div className="fi-ta-actions">
                                                        {items.length > 0 && (
                                                            <Dropdown
                                                                items={items}
                                                                trigger={({ open, toggle }) => (
                                                                    <Button size="sm" icon={IconDotsVertical} busy={actions.busyFor === record.name} aria-haspopup="true" aria-expanded={open} onClick={toggle}>
                                                                        {t('players.actions.menu')}
                                                                    </Button>
                                                                )}
                                                            />
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <nav aria-label="Pagination" className="fi-pagination us-pagination">
                        <span className="fi-pagination-overview">{rows.length === 1 ? t('ui.one_result') : t('ui.results', { from, to, total: rows.length })}</span>
                        <div className="us-pagination-controls">
                            {pages > 1 && (
                                <div className="us-pages">
                                    <Button size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                        {t('ui.previous')}
                                    </Button>
                                    <span className="us-page-of">{t('ui.page_of', { page: Math.min(page, pages), pages })}</span>
                                    <Button size="sm" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
                                        {t('ui.next')}
                                    </Button>
                                </div>
                            )}
                            <label className="us-per-page">
                                <span>{t('ui.per_page')}</span>
                                <Select value={String(perPage)} onChange={(e) => setPerPage(Number(e.target.value))} options={PER_PAGE.map((n): [string, string] => [String(n), String(n)])} />
                            </label>
                        </div>
                    </nav>
                </div>
            </div>
            {adding && <AddToWhitelist onClose={() => setAdding(false)} onDone={reload} />}
            {actions.modal}
        </div>
    );
}

function PlayerCells({ row }: { row: Row }) {
    return (
        <>
            <td className="fi-ta-cell">
                <div className="fi-ta-col">
                    {row.gamemode && (
                        <div className="fi-ta-text fi-ta-text-item fi-ta-text-has-badges">
                            <Badge color={GAME_MODE_COLORS[row.gamemode] ?? 'success'} size="sm">
                                {t(`players.game_modes.${row.gamemode}`)}
                            </Badge>
                        </div>
                    )}
                </div>
            </td>
            <td className="fi-ta-cell">
                <div className="fi-ta-col">
                    <div className="fi-ta-text fi-ta-text-item fi-size-sm">{row.xp_level ?? ''}</div>
                </div>
            </td>
            <td className="fi-ta-cell">
                <div className="fi-ta-col">
                    <div className="fi-ta-text-item fi-size-sm fi-ta-text">
                        {row.dimension ? worldName(row.dimension) : ''}
                        {row.x != null && <p className="fi-ta-text-description us-muted">{`${row.x}, ${row.y}, ${row.z}`}</p>}
                    </div>
                </div>
            </td>
            <td className="fi-ta-cell">
                <div className="fi-ta-col">
                    <div className={cx('fi-ta-text-item fi-size-sm fi-ta-text', row.is_online ? 'fi-color fi-color-success fi-text-color-600 dark:fi-text-color-400' : 'fi-color fi-color-gray fi-text-color-500 dark:fi-text-color-400')} title={!row.is_online ? (row.last_seen_title ?? undefined) : undefined}>
                        {row.is_online ? t('players.online_now') : (row.last_seen_text ?? t('players.never'))}
                    </div>
                </div>
            </td>
        </>
    );
}

function BanCells({ ban }: { ban: Ban }) {
    const description = [ban.source ? t('players.banned_by', { name: ban.source }) : null, ban.created].filter(Boolean).join(' · ');
    return (
        <>
            <td className="fi-ta-cell">
                <div className="fi-ta-col">
                    <div className="fi-ta-text-item fi-size-sm fi-ta-text us-wrap">
                        {ban.reason}
                        {description && <p className="fi-ta-text-description us-muted">{description}</p>}
                    </div>
                </div>
            </td>
            <td className="fi-ta-cell">
                <div className="fi-ta-col">
                    <div className="fi-ta-text-item fi-size-sm fi-ta-text">{ban.expires_text ?? t('players.never')}</div>
                </div>
            </td>
        </>
    );
}

function AddToWhitelist({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const [busy, run] = useAction();
    const [name, setName] = useState('');
    const valid = /^[.*]?[A-Za-z0-9_]{1,16}$/.test(name.trim());

    const submit = async () => {
        const result = await run(() => sendPlayerAction(name.trim(), 'whitelist-add'));
        if (result) {
            notifyDone(result);
            onClose();
            onDone();
        }
    };

    return (
        <Modal open onClose={onClose} heading={t('players.add_to_whitelist')} busy={busy} onSubmit={() => valid && submit()} footer={<ModalActions submitLabel={t('players.actions.whitelist')} busy={busy} disabled={!valid} onCancel={onClose} />}>
            <Field label={t('players.columns.name')}>
                <TextInput value={name} required maxLength={17} autoComplete="off" onChange={(e) => setName(e.target.value)} invalid={name !== '' && !valid} />
            </Field>
        </Modal>
    );
}
