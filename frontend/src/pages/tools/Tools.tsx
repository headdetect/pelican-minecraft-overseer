import { useState } from 'react';
import useSWR from 'swr';
import { IconAlertTriangle, IconGridDots, IconMap, IconPlayerPause, IconPlayerPlay, IconPlayerStop, IconPlayerTrackNext } from '@tabler/icons-react';
import { postJson } from '../../api';
import { t } from '../../lang';
import { notifyDone } from '../../notify';
import { useRefresh } from '../../refresh';
import { Button, Field, Modal, ModalActions, Section, Select, Spinner, TextInput, ToggleField } from '../../ui';
import { useAction } from '../../useAction';

interface Task {
    world: string;
    chunks: number;
    percent: number;
    eta: string | null;
}

interface SavedTask {
    world: string;
    chunks: number;
    percent: number | null;
}

interface ToolsState {
    running: boolean;
    starting: boolean;
    installed: boolean | null;
    tasks: Task[];
    saved: SavedTask[];
    squaremap: boolean;
    worlds: string[];
    max_radius: number;
}

interface Result {
    title: string;
    body: string | null;
}

const number = (n: number, digits = 0) => n.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });

/** About how many chunks a radius covers, as Chunky::chunkCount() works it out. */
export function chunkCount(radius: number, shape: string): number {
    const side = Math.floor((2 * radius) / 16) + 1;
    return shape === 'circle' ? Math.round(Math.PI * (side / 2) ** 2) : side * side;
}

export default function Tools() {
    const { data, mutate } = useSWR<ToolsState>('/tools');
    const [form, setForm] = useState<'start' | 'cancel' | 'render' | null>(null);
    useRefresh(() => mutate());

    const reload = () => {
        mutate();
    };

    return (
        <div className="us-tools">
            <Section icon={IconGridDots} heading={t('tools.pregen.title')} description={t('tools.pregen.help')}>
                {!data ? (
                    <Spinner />
                ) : (
                    <div className="us-stack">
                        {!data.running ? (
                            <p className="us-help">{t(data.starting ? 'tools.starting' : 'tools.offline')}</p>
                        ) : data.installed === null ? (
                            <p className="us-help">{t('tools.pregen.no_rcon')}</p>
                        ) : data.installed === false ? (
                            <p className="us-help">
                                {t('tools.pregen.missing')}{' '}
                                <a href="https://modrinth.com/plugin/chunky" target="_blank" rel="noopener noreferrer" className="us-link">
                                    modrinth.com/plugin/chunky
                                </a>
                            </p>
                        ) : (
                            <>
                                {data.tasks.map((task) => (
                                    <div key={task.world}>
                                        <div className="us-task-line">
                                            <span className="us-strong">{task.world}</span>
                                            <span className="us-muted us-tabular">
                                                {t('tools.pregen.progress', { chunks: number(task.chunks), percent: number(task.percent, 1) })}
                                                {task.eta && task.eta.replace(/[0:]/g, '') !== '' && ` · ${t('tools.pregen.eta', { eta: task.eta })}`}
                                            </span>
                                        </div>
                                        <div className="us-progress" role="progressbar" aria-valuenow={task.percent} aria-valuemin={0} aria-valuemax={100}>
                                            <div style={{ width: `${task.percent}%` }} />
                                        </div>
                                    </div>
                                ))}
                                {data.tasks.length === 0 && data.saved.length === 0 && <p className="us-help">{t('tools.pregen.idle')}</p>}
                                {data.saved.map((task) => (
                                    <p key={task.world} className="us-small">
                                        <span className="us-strong">{task.world}</span>{' '}
                                        <span className="us-muted">
                                            ·{' '}
                                            {task.percent !== null
                                                ? t('tools.pregen.paused_at', { chunks: number(task.chunks), percent: number(task.percent, 1) })
                                                : t('tools.pregen.paused_chunks', { chunks: number(task.chunks) })}
                                        </span>
                                    </p>
                                ))}
                                <div className="us-buttons">
                                    <Button color="primary" icon={IconPlayerPlay} onClick={() => setForm('start')}>
                                        {t('tools.pregen.start')}
                                    </Button>
                                    {data.tasks.length > 0 && <ChunkyButton action="pause" icon={IconPlayerPause} label={t('tools.pregen.pause')} onDone={reload} />}
                                    {data.saved.length > 0 && <ChunkyButton action="continue" icon={IconPlayerTrackNext} label={t('tools.pregen.continue')} title={t('tools.pregen.continue_help')} onDone={reload} />}
                                    {(data.tasks.length > 0 || data.saved.length > 0) && (
                                        <Button color="danger" icon={IconPlayerStop} onClick={() => setForm('cancel')}>
                                            {t('tools.pregen.cancel')}
                                        </Button>
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                )}
            </Section>

            {data?.squaremap && (
                <Section icon={IconMap} heading={t('tools.render.title')} description={t('tools.render.help')}>
                    <div className="us-buttons">
                        <Button color="primary" icon={IconMap} onClick={() => setForm('render')}>
                            {t('tools.render.start')}
                        </Button>
                    </div>
                </Section>
            )}

            {form === 'start' && data && <StartForm state={data} onClose={() => setForm(null)} onDone={reload} />}
            {form === 'cancel' && <CancelForm onClose={() => setForm(null)} onDone={reload} />}
            {form === 'render' && data && <RenderForm worlds={data.worlds} onClose={() => setForm(null)} />}
        </div>
    );
}

function ChunkyButton({ action, icon, label, title, onDone }: { action: string; icon: typeof IconPlayerPause; label: string; title?: string; onDone: () => void }) {
    const [busy, run] = useAction();
    return (
        <Button
            icon={icon}
            busy={busy}
            title={title}
            onClick={async () => {
                const result = await run(() => postJson<Result>(`/tools/chunky/${action}`));
                if (result) {
                    notifyDone(result);
                    onDone();
                }
            }}
        >
            {label}
        </Button>
    );
}

function StartForm({ state, onClose, onDone }: { state: ToolsState; onClose: () => void; onDone: () => void }) {
    const [busy, run] = useAction();
    const [world, setWorld] = useState(state.worlds[0] ?? 'minecraft:overworld');
    const [radius, setRadius] = useState('2000');
    const [shape, setShape] = useState('square');
    const [spawn, setSpawn] = useState(true);
    const [x, setX] = useState('0');
    const [z, setZ] = useState('0');

    const r = Number(radius);
    const radiusOk = /^\d+$/.test(radius.trim()) && r >= 16 && r <= state.max_radius;
    const valid = radiusOk && (spawn || (/^-?\d+$/.test(x.trim()) && /^-?\d+$/.test(z.trim())));

    const submit = async () => {
        const result = await run(() => postJson<Result>('/tools/chunky/start', { world, radius: r, shape, spawn, ...(spawn ? {} : { x: Number(x), z: Number(z) }) }));
        if (result) {
            notifyDone(result);
            onClose();
            onDone();
        }
    };

    const description = t('tools.pregen.start_help') + (state.saved.length ? ` ${t('tools.pregen.replaces_saved')}` : '');

    return (
        <Modal open onClose={onClose} heading={t('tools.pregen.start_heading')} description={description} busy={busy} onSubmit={() => valid && submit()} footer={<ModalActions submitLabel={t('tools.pregen.start')} busy={busy} disabled={!valid} onCancel={onClose} />}>
            <div className="us-form">
                <Field label={t('tools.world')}>
                    <Select value={world} onChange={(e) => setWorld(e.target.value)} options={state.worlds.map((w): [string, string] => [w, w])} />
                </Field>
                <div className="us-grid-2">
                    <Field label={t('tools.pregen.radius')}>
                        <TextInput type="number" step={1} min={16} max={state.max_radius} required value={radius} suffix={t('tools.pregen.blocks')} invalid={!radiusOk} onChange={(e) => setRadius(e.target.value)} />
                    </Field>
                    <Field label={t('tools.pregen.shape')}>
                        <Select
                            value={shape}
                            onChange={(e) => setShape(e.target.value)}
                            options={[
                                ['square', t('tools.pregen.shapes.square')],
                                ['circle', t('tools.pregen.shapes.circle')],
                            ]}
                        />
                    </Field>
                </div>
                <ToggleField label={t('tools.pregen.around_spawn')} checked={spawn} onChange={setSpawn} />
                {!spawn && (
                    <div className="us-grid-2">
                        <Field label="X">
                            <TextInput type="number" step={1} min={-29999984} max={29999984} required value={x} onChange={(e) => setX(e.target.value)} />
                        </Field>
                        <Field label="Z">
                            <TextInput type="number" step={1} min={-29999984} max={29999984} required value={z} onChange={(e) => setZ(e.target.value)} />
                        </Field>
                    </div>
                )}
                {radiusOk && <p className="us-help">{t('tools.pregen.estimate', { chunks: number(chunkCount(r, shape)) })}</p>}
            </div>
        </Modal>
    );
}

function CancelForm({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const [busy, run] = useAction();
    const submit = async () => {
        const result = await run(() => postJson<Result>('/tools/chunky/cancel'));
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
            heading={t('tools.pregen.cancel')}
            description={t('tools.pregen.cancel_help')}
            icon={IconAlertTriangle}
            iconColor="danger"
            busy={busy}
            footer={<ModalActions submitLabel={t('tools.pregen.cancel')} color="danger" busy={busy} onCancel={onClose} onSubmit={submit} />}
        />
    );
}

function RenderForm({ worlds, onClose }: { worlds: string[]; onClose: () => void }) {
    const [busy, run] = useAction();
    const [world, setWorld] = useState(worlds[0] ?? 'minecraft:overworld');
    const submit = async () => {
        const result = await run(() => postJson<Result>('/tools/render', { world }));
        if (result) {
            notifyDone(result);
            onClose();
        }
    };
    return (
        <Modal open onClose={onClose} heading={t('tools.render.title')} description={t('tools.render.start_help')} busy={busy} onSubmit={submit} footer={<ModalActions submitLabel={t('tools.render.start')} busy={busy} onCancel={onClose} />}>
            <Field label={t('tools.world')}>
                <Select value={world} onChange={(e) => setWorld(e.target.value)} options={worlds.map((w): [string, string] => [w, w])} />
            </Field>
        </Modal>
    );
}
