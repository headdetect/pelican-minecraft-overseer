import { useCallback, useEffect, useId, useState, type ReactNode } from 'react';
import { IconAlertTriangle, IconRotate } from '@tabler/icons-react';
import { getJson, postJson } from '../api';
import { useBoot } from '../boot';
import { t } from '../lang';
import { notifyDone } from '../notify';
import { useAction } from '../useAction';
import { Field, Modal, ModalActions, Radios, Select, SkeletonInput, TextInput, ToggleField, type Color } from '../ui';

/** What a form needs to know about the player it acts on. */
export interface PlayerRef {
    name: string;
    gamemode?: string | null;
    dimension?: string | null;
    x?: number | null;
    y?: number | null;
    z?: number | null;
}

export type PlayerAction = 'kick' | 'ban' | 'unban' | 'op' | 'deop' | 'whitelist-add' | 'whitelist-remove' | 'gamemode' | 'give' | 'teleport';

interface Result {
    title: string;
    body: string | null;
}

export const GAME_MODES = ['survival', 'creative', 'adventure', 'spectator'];

export const DIMENSIONS = ['minecraft:overworld', 'minecraft:the_nether', 'minecraft:the_end'];

export function dimensionOptions(): Array<[string, string]> {
    return [
        ['minecraft:overworld', t('map.worlds.overworld')],
        ['minecraft:the_nether', t('map.worlds.nether')],
        ['minecraft:the_end', t('map.worlds.end')],
    ];
}

const GIVE_ITEMS = ['minecraft:diamond', 'minecraft:iron_ingot', 'minecraft:golden_apple', 'minecraft:ender_pearl', 'minecraft:cooked_beef', 'minecraft:torch', 'minecraft:oak_log', 'minecraft:elytra', 'minecraft:totem_of_undying'];

/** Sends one player action. Throws ApiError when it fails. */
export function sendPlayerAction(name: string, action: PlayerAction, data: Record<string, unknown> = {}): Promise<Result> {
    return postJson<Result>(`/players/${encodeURIComponent(name)}/${action}`, data);
}

/** Names of the players online now, from "list". */
export function useOnlineNames(enabled: boolean): string[] | null {
    const [names, setNames] = useState<string[] | null>(null);
    useEffect(() => {
        if (!enabled) return;
        let live = true;
        getJson<{ names: string[] }>('/online').then(
            (r) => live && setNames(r.names),
            () => live && setNames([]),
        );
        return () => {
            live = false;
        };
    }, [enabled]);
    return names;
}

function ReasonField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    const boot = useBoot();
    const id = useId();
    return (
        <Field label={t('players.reason')} help={t('players.reason_help')} htmlFor={id}>
            <TextInput id={id} value={value} maxLength={200} list={`${id}-reasons`} onChange={(e) => onChange(e.target.value)} />
            <datalist id={`${id}-reasons`}>
                {boot.reasons.map((reason) => (
                    <option key={reason} value={reason} />
                ))}
            </datalist>
        </Field>
    );
}

function Form({ children }: { children: ReactNode }) {
    return <div className="us-form">{children}</div>;
}

/**
 * The modal for one action. It keeps its own form values, sends the action
 * when submitted, and stays open with the values kept if the action fails.
 */
function ActionModal({ action, player, online, onClose, onDone }: { action: PlayerAction; player: PlayerRef; online: string[] | null; onClose: () => void; onDone: () => void }) {
    const [busy, run] = useAction();
    const [reason, setReason] = useState('');
    const [duration, setDuration] = useState('forever');
    const [mode, setMode] = useState(player.gamemode ?? '');
    const [item, setItem] = useState('');
    const [count, setCount] = useState('1');
    const others = (online ?? []).filter((name) => name !== player.name);
    const [to, setTo] = useState<'player' | 'coords'>('coords');
    const [target, setTarget] = useState('');
    const [x, setX] = useState(String(player.x ?? 0));
    const [y, setY] = useState(String(player.y ?? 0));
    const [z, setZ] = useState(String(player.z ?? 0));
    const [dimension, setDimension] = useState(`minecraft:${String(player.dimension ?? 'overworld').replace(/^minecraft:/, '')}`);
    const itemsId = useId();

    // The Game mode form starts on the player's mode, read over RCON when the caller doesn't know it.
    useEffect(() => {
        if (action !== 'gamemode' || player.gamemode) return;
        let live = true;
        getJson<{ mode: string }>(`/players/${encodeURIComponent(player.name)}/gamemode`).then(
            (r) => live && setMode(r.mode),
            () => live && setMode('survival'),
        );
        return () => {
            live = false;
        };
    }, [action, player.name, player.gamemode]);

    // Teleport to another player when someone else is online.
    useEffect(() => {
        if (action === 'teleport' && others.length > 0 && !target) {
            setTo('player');
            setTarget(others[0]);
        }
    }, [action, others.length]); // eslint-disable-line react-hooks/exhaustive-deps

    const submit = async () => {
        const data: Record<string, unknown> = {};
        if (action === 'kick') data.reason = reason;
        if (action === 'ban') Object.assign(data, { reason, duration });
        if (action === 'gamemode') data.mode = mode;
        if (action === 'give') Object.assign(data, { item: item.trim(), count: Number(count) });
        if (action === 'teleport') Object.assign(data, to === 'player' ? { to, target } : { to, x: Number(x), y: Number(y), z: Number(z), dimension });

        const result = await run(() => sendPlayerAction(player.name, action, data));
        if (result) {
            notifyDone(result);
            onClose();
            onDone();
        }
    };

    const name = player.name;
    let heading = '';
    let description: string | undefined;
    let submitLabel = '';
    let color: Color = 'primary';
    let body: ReactNode = null;
    let icon = undefined;
    let valid = true;

    switch (action) {
        case 'kick':
            heading = t('players.kick_heading', { name });
            submitLabel = t('players.actions.kick');
            color = 'warning';
            body = <ReasonField value={reason} onChange={setReason} />;
            break;
        case 'ban':
            heading = t('players.ban_heading', { name });
            submitLabel = t('players.actions.ban');
            color = 'danger';
            body = (
                <Form>
                    <ReasonField value={reason} onChange={setReason} />
                    <Field label={t('players.duration')}>
                        <Select
                            value={duration}
                            onChange={(e) => setDuration(e.target.value)}
                            options={[
                                ['1', t('players.durations.hour')],
                                ['24', t('players.durations.day')],
                                ['168', t('players.durations.week')],
                                ['forever', t('players.durations.forever')],
                            ]}
                        />
                    </Field>
                </Form>
            );
            break;
        case 'unban':
            heading = t('players.unban_heading', { name });
            submitLabel = t('players.actions.unban');
            color = 'success';
            icon = IconRotate;
            break;
        case 'op':
            heading = t('map.op_heading', { name });
            description = t('players.op_warning');
            submitLabel = t('players.actions.op');
            color = 'danger';
            icon = IconAlertTriangle;
            break;
        case 'deop':
            heading = t('map.deop_heading', { name });
            submitLabel = t('players.actions.deop');
            color = 'danger';
            icon = IconAlertTriangle;
            break;
        case 'gamemode':
            heading = t('players.gamemode_heading', { name });
            submitLabel = t('players.actions.gamemode');
            valid = mode !== '';
            body = (
                <Field label={t('players.game_mode')}>
                    {mode === '' ? (
                        <SkeletonInput />
                    ) : (
                        <Select value={mode} onChange={(e) => setMode(e.target.value)} options={GAME_MODES.map((m) => [m, t(`players.game_modes.${m}`)])} />
                    )}
                </Field>
            );
            break;
        case 'give':
            heading = t('players.give_heading', { name });
            submitLabel = t('players.actions.give');
            valid = /^(?:[a-z0-9_.-]+:)?[a-z0-9_./-]{1,100}$/i.test(item.trim()) && Number(count) >= 1 && Number(count) <= 6400;
            body = (
                <div className="us-grid-3">
                    <div className="us-span-2">
                        <Field label={t('players.item')} help={t('players.item_help')}>
                            <TextInput value={item} required placeholder="minecraft:diamond" list={itemsId} onChange={(e) => setItem(e.target.value)} />
                            <datalist id={itemsId}>
                                {GIVE_ITEMS.map((option) => (
                                    <option key={option} value={option} />
                                ))}
                            </datalist>
                        </Field>
                    </div>
                    <Field label={t('players.count')}>
                        <TextInput type="number" inputMode="numeric" min={1} max={6400} step={1} required value={count} onChange={(e) => setCount(e.target.value)} />
                    </Field>
                </div>
            );
            break;
        case 'teleport':
            heading = t('players.teleport_heading', { name });
            submitLabel = t('players.actions.teleport');
            valid = to === 'player' ? target !== '' : [x, y, z].every((v) => /^-?\d+$/.test(v.trim()));
            body = (
                <Form>
                    <Field label={t('players.teleport_to')}>
                        <Radios
                            name="teleport-to"
                            value={to}
                            onChange={(value) => setTo(value as 'player' | 'coords')}
                            options={[
                                ['player', t('players.teleport_player')],
                                ['coords', t('players.teleport_coords')],
                            ]}
                        />
                    </Field>
                    {to === 'player' ? (
                        <Field label={t('players.columns.name')}>
                            {online === null ? <SkeletonInput /> : <Select value={target} onChange={(e) => setTarget(e.target.value)} options={[['', ''], ...others.map((n): [string, string] => [n, n])]} required />}
                        </Field>
                    ) : (
                        <>
                            <div className="us-grid-3">
                                <Field label="X">
                                    <TextInput type="number" step={1} min={-29999984} max={29999984} value={x} onChange={(e) => setX(e.target.value)} required />
                                </Field>
                                <Field label="Y">
                                    <TextInput type="number" step={1} min={-2048} max={2048} value={y} onChange={(e) => setY(e.target.value)} required />
                                </Field>
                                <Field label="Z">
                                    <TextInput type="number" step={1} min={-29999984} max={29999984} value={z} onChange={(e) => setZ(e.target.value)} required />
                                </Field>
                            </div>
                            <Field label={t('players.dimension')}>
                                <Select value={dimension} onChange={(e) => setDimension(e.target.value)} options={dimensionOptions()} />
                            </Field>
                        </>
                    )}
                </Form>
            );
            break;
        default:
            return null;
    }

    return (
        <Modal
            open
            onClose={onClose}
            heading={heading}
            description={description}
            icon={icon}
            iconColor={color}
            busy={busy}
            onSubmit={() => valid && submit()}
            footer={<ModalActions submitLabel={submitLabel} color={color} busy={busy} disabled={!valid} onCancel={onClose} />}
        >
            {body}
        </Modal>
    );
}

/** Actions that run right away, without a form or a confirmation. */
const IMMEDIATE: PlayerAction[] = ['whitelist-add', 'whitelist-remove'];

/**
 * Opens the form for a player action, or runs it right away when it has no
 * form. `busy` is true while a form-less action runs, so the menu that
 * started it can show a spinner.
 *
 *   const actions = usePlayerActions(() => mutate());
 *   actions.open('kick', { name });
 *   return <>{...}{actions.modal}</>;
 */
export function usePlayerActions(onDone: () => void) {
    const [current, setCurrent] = useState<{ action: PlayerAction; player: PlayerRef } | null>(null);
    const [busyFor, setBusyFor] = useState<string | null>(null);
    const [, run] = useAction();
    const online = useOnlineNames(current?.action === 'teleport');

    const open = useCallback(
        async (action: PlayerAction, player: PlayerRef) => {
            if (!IMMEDIATE.includes(action)) {
                setCurrent({ action, player });
                return;
            }
            setBusyFor(player.name);
            try {
                const result = await run(() => sendPlayerAction(player.name, action));
                if (result) {
                    notifyDone(result);
                    onDone();
                }
            } finally {
                setBusyFor(null);
            }
        },
        [run, onDone],
    );

    const close = useCallback(() => setCurrent(null), []);

    const modal = current ? <ActionModal key={`${current.action}:${current.player.name}`} action={current.action} player={current.player} online={online} onClose={close} onDone={onDone} /> : null;

    return { open, modal, busyFor };
}

/**
 * Teleports any online player to coordinates, from the Overview's Quick
 * actions. With "Land on the ground" on, it asks the server for the ground
 * height at x and z first.
 */
export function TeleportCoordsModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const boot = useBoot();
    const online = useOnlineNames(true);
    const [busy, run] = useAction();
    const [player, setPlayer] = useState('');
    const [dimension, setDimension] = useState('minecraft:overworld');
    const [x, setX] = useState('');
    const [y, setY] = useState('');
    const [z, setZ] = useState('');
    const [ground, setGround] = useState(true);

    useEffect(() => {
        if (online?.length && !player) setPlayer(online[0]);
    }, [online, player]);

    const isInt = (v: string) => /^-?\d+$/.test(v.trim());
    const valid = player !== '' && isInt(x) && isInt(z) && (ground || isInt(y));

    const submit = async () => {
        const result = await run(async () => {
            let height = Number(y);
            if (ground) {
                const url = `${boot.surfaceUrl}?${new URLSearchParams({ world: dimension, x: x.trim(), z: z.trim() })}`;
                const surface = await getJson<{ y: number | null }>(url);
                if (surface.y === null) throw new Error(t('map.point.no_ground'));
                height = surface.y;
            }
            return sendPlayerAction(player, 'teleport', { to: 'coords', dimension, x: Number(x), y: height, z: Number(z) });
        });
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
            heading={t('map.teleport.heading')}
            busy={busy}
            onSubmit={() => valid && submit()}
            footer={<ModalActions submitLabel={t('map.point.teleport')} busy={busy} disabled={!valid} onCancel={onClose} />}
        >
            <Form>
                <Field label={t('map.point.player')}>
                    {online === null ? (
                        <SkeletonInput />
                    ) : online.length === 0 ? (
                        <p className="us-help">{t('map.point.nobody')}</p>
                    ) : (
                        <Select value={player} onChange={(e) => setPlayer(e.target.value)} options={online.map((n): [string, string] => [n, n])} />
                    )}
                </Field>
                <Field label={t('players.dimension')}>
                    <Select value={dimension} onChange={(e) => setDimension(e.target.value)} options={dimensionOptions()} />
                </Field>
                <div className="us-grid-3">
                    <Field label="X">
                        <TextInput type="number" step={1} min={-29999984} max={29999984} value={x} onChange={(e) => setX(e.target.value)} required />
                    </Field>
                    <Field label="Y">
                        <TextInput type="number" step={1} min={-2048} max={2048} value={ground ? '' : y} placeholder={ground ? t('map.teleport.auto') : undefined} disabled={ground} onChange={(e) => setY(e.target.value)} />
                    </Field>
                    <Field label="Z">
                        <TextInput type="number" step={1} min={-29999984} max={29999984} value={z} onChange={(e) => setZ(e.target.value)} required />
                    </Field>
                </div>
                <ToggleField label={t('map.teleport.ground')} help={t('map.teleport.ground_help')} checked={ground} onChange={setGround} />
            </Form>
        </Modal>
    );
}
