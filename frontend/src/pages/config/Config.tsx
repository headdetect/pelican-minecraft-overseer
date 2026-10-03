import { useEffect, useId, useMemo, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import useSWR from 'swr';
import { IconAlertCircle, IconArrowBackUp, IconDeviceFloppy, IconEye, IconEyeOff, IconFileCode, IconInfoCircle, IconReload, IconSearch } from '@tabler/icons-react';
import { postJson } from '../../api';
import { useBoot } from '../../boot';
import { choice, t } from '../../lang';
import { notify, type Status } from '../../notify';
import { Button, Callout, Field, IconButton, Modal, ModalActions, Select, Spinner, TabItem, Tabs, TextInput, Toggle } from '../../ui';
import { Icon, namedIcon } from '../../ui/icons';
import { useAction } from '../../useAction';
import { changes as changesFor, diffLine, isDefault, matches, shown, type Change, type Entry, type Source, type Value } from './schema';

interface ConfigState {
    sources: Source[];
    restart_needed: boolean;
}

interface SaveResult {
    saved: number;
    failed: boolean;
    notifications: Array<{ status: Status; title: string; body?: string | null }>;
}

type Form = Record<string, Record<string, Value>>;

const RCON_KEYS = ['enable-rcon', 'rcon.port', 'rcon.password'];

function formFrom(state: ConfigState): Form {
    return Object.fromEntries(state.sources.map((source) => [source.key, { ...source.values }]));
}

function sourceFromUrl(): string | null {
    return new URLSearchParams(window.location.search).get('source');
}

/** Puts the page's buttons in the header, beside the title. */
function HeaderActions({ children }: { children: ReactNode }) {
    const [target, setTarget] = useState<HTMLElement | null>(null);
    useEffect(() => setTarget(document.getElementById('us-header-actions')), []);
    return target ? createPortal(children, target) : null;
}

export default function Config() {
    const boot = useBoot();
    const canEdit = boot.can.configEdit;
    const { data, mutate } = useSWR<ConfigState>('/config', { revalidateIfStale: true });
    const [form, setForm] = useState<Form | null>(null);
    const [loadedFrom, setLoadedFrom] = useState<ConfigState | null>(null);
    const [search, setSearch] = useState('');
    const [advanced, setAdvanced] = useState(false);
    const [tab, setTabState] = useState<string | null>(sourceFromUrl);
    const [review, setReview] = useState<'save' | 'restart' | null>(null);
    const [confirmRestart, setConfirmRestart] = useState(false);

    const changes = useMemo(() => {
        if (!data || !form) return {} as Record<string, Record<string, Change>>;
        const all: Record<string, Record<string, Change>> = {};
        for (const source of data.sources) {
            if (source.unavailable) continue;
            const found = changesFor(source, form[source.key] ?? {});
            if (Object.keys(found).length) all[source.key] = found;
        }
        return all;
    }, [data, form]);

    const changeCount = Object.values(changes).reduce((n, list) => n + Object.keys(list).length, 0);
    const changed = canEdit && changeCount > 0;

    // Fill the form from what the server sent, unless that would drop edits.
    useEffect(() => {
        if (!data || data === loadedFrom) return;
        if (form && changeCount > 0) return;
        setForm(formFrom(data));
        setLoadedFrom(data);
    }, [data, loadedFrom, form, changeCount]);

    if (!data || !form) {
        return (
            <div className="us-loading">
                <Spinner />
            </div>
        );
    }

    const invalid = Object.values(changes).some((list) => Object.values(list).some((c) => c.error !== null));
    const restartCount = Object.entries(changes).reduce((n, [source, list]) => n + (source === 'rules' ? 0 : Object.values(list).filter((c) => c.entry.restart).length), 0);
    const changesRcon = Object.keys(changes.server ?? {}).some((key) => RCON_KEYS.includes(key));
    const canRestart = boot.can.restart;

    const tabs = [...data.sources.map((s) => s.key), 'files'];
    const active = tab && tabs.includes(tab) ? tab : tabs[0];
    const setTab = (next: string) => {
        setTabState(next);
        const url = new URL(window.location.href);
        url.searchParams.set('source', next);
        window.history.replaceState(window.history.state, '', url);
    };

    const setValue = (source: string, key: string, value: Value) => setForm((f) => (f ? { ...f, [source]: { ...f[source], [key]: value } } : f));

    const discard = () => setForm(formFrom(data));

    const afterSave = async () => {
        const fresh = await mutate();
        if (fresh) {
            setForm(formFrom(fresh));
            setLoadedFrom(fresh);
        }
    };

    return (
        <div className="us-config">
            <HeaderActions>
                {changed && restartCount > 0 && (
                    <span className="us-config-note">
                        <Icon icon={IconInfoCircle} />
                        {t(changesRcon ? 'config.rcon_note' : 'config.restart_note')}
                    </span>
                )}
                {changed && invalid && (
                    <span className="us-config-note is-danger">
                        <Icon icon={IconAlertCircle} />
                        {t('config.invalid_note')}
                    </span>
                )}
                {changed && <Button onClick={discard}>{t('config.discard')}</Button>}
                {changed && !(changesRcon && canRestart) && (
                    <Button color={restartCount > 0 && canRestart ? 'gray' : 'primary'} icon={IconDeviceFloppy} disabled={invalid} onClick={() => setReview('save')}>
                        {choice('config.save', changeCount, { count: changeCount })}
                    </Button>
                )}
                {changed && restartCount > 0 && canRestart && (
                    <Button color="primary" icon={IconReload} disabled={invalid} onClick={() => setReview('restart')}>
                        {t('config.save_restart')}
                    </Button>
                )}
                {data.restart_needed && !changed && canRestart && (
                    <Button color="warning" icon={IconReload} onClick={() => setConfirmRestart(true)}>
                        {t('config.restart')}
                    </Button>
                )}
            </HeaderActions>

            <div className="us-config-search">
                <TextInput type="search" prefixIcon={IconSearch} placeholder={t('config.search')} aria-label={t('config.search')} value={search} onChange={(e) => setSearch(e.target.value)} />
                <div className="us-toggle-field">
                    <Toggle id="us-config-advanced" checked={advanced} onChange={setAdvanced} />
                    <label htmlFor="us-config-advanced">
                        <span className="fi-fo-field-label-content">{t('config.advanced')}</span>
                        <span className="fi-fo-field-wrp-helper-text">{t('config.advanced_help')}</span>
                    </label>
                </div>
            </div>

            <div className="us-config-body">
                <Tabs vertical className="us-config-tabs">
                    {data.sources.map((source) => (
                        <TabItem key={source.key} active={active === source.key} icon={namedIcon(source.icon)} badge={source.unavailable ? undefined : Object.values(source.entries).filter((e) => !e.advanced).length} onClick={() => setTab(source.key)}>
                            {source.title}
                        </TabItem>
                    ))}
                    <TabItem active={active === 'files'} icon={IconFileCode} onClick={() => setTab('files')}>
                        {t('config.files.title')}
                    </TabItem>
                </Tabs>

                <div className="fi-section us-config-panel">
                    <div className="fi-section-content-ctn">
                        <div className="fi-section-content">
                            {active === 'files' ? (
                                <FilesTab />
                            ) : (
                                <SourceTab source={data.sources.find((s) => s.key === active)!} values={form[active] ?? {}} search={search} advanced={advanced} canEdit={canEdit} onChange={(key, value) => setValue(active, key, value)} />
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {review && (
                <ReviewModal
                    restart={review === 'restart'}
                    sources={data.sources}
                    changes={changes}
                    form={form}
                    onClose={() => setReview(null)}
                    onSaved={afterSave}
                />
            )}
            {confirmRestart && <RestartModal onClose={() => setConfirmRestart(false)} onDone={() => mutate()} />}
        </div>
    );
}

function SourceTab({ source, values, search, advanced, canEdit, onChange }: { source: Source; values: Record<string, Value>; search: string; advanced: boolean; canEdit: boolean; onChange: (key: string, value: Value) => void }) {
    if (source.unavailable) {
        return <Callout color="warning" heading={t(`config.unavailable.${source.unavailable}`)} description={t(`config.unavailable.${source.unavailable}_help`)} />;
    }

    const visible = (key: string, entry: Entry) => (!entry.advanced || advanced) && matches(key, entry, search);
    const groups = new Map<string, Array<[string, Entry]>>();
    for (const [key, entry] of Object.entries(source.entries)) {
        if (!visible(key, entry)) continue;
        if (!groups.has(entry.group)) groups.set(entry.group, []);
        groups.get(entry.group)!.push([key, entry]);
    }

    if (groups.size === 0) return <Callout color="info" heading={t('config.no_match')} />;

    return (
        <div className="us-config-groups">
            {[...groups].map(([group, entries]) => (
                <section key={group} className="us-config-group">
                    <h3 className="us-config-group-heading">{group}</h3>
                    {entries.map(([key, entry]) => (
                        <Setting key={key} name={key} entry={entry} value={values[key]} canEdit={canEdit} onChange={(value) => onChange(key, value)} />
                    ))}
                </section>
            ))}
        </div>
    );
}

/** One setting: title and description on the left, the input on the right. */
function Setting({ name, entry, value, canEdit, onChange }: { name: string; entry: Entry; value: Value | undefined; canEdit: boolean; onChange: (value: Value) => void }) {
    const id = useId();
    const [confirmReset, setConfirmReset] = useState(false);
    const [reveal, setReveal] = useState(false);
    const disabled = !canEdit;
    const current = value ?? '';
    const help = entry.type === 'range' && entry.unit ? `${entry.help} (${entry.min}–${entry.max} ${entry.unit})` : entry.help;
    const canReset = canEdit && entry.default !== undefined && entry.type !== 'password' && !isDefault(entry, current);

    let input: ReactNode;
    switch (entry.type) {
        case 'bool':
            input = <Toggle id={id} checked={current === true || current === 'true'} disabled={disabled} onChange={onChange} />;
            break;
        case 'int':
            input = <TextInput id={id} type="number" inputMode="numeric" step={1} min={entry.min} max={entry.max} suffix={entry.unit} disabled={disabled} value={String(current)} onChange={(e) => onChange(e.target.value)} />;
            break;
        case 'range':
            input = (
                <div className="us-range">
                    <input id={id} type="range" min={entry.min} max={entry.max} step={1} disabled={disabled} value={Number(current)} onChange={(e) => onChange(Number(e.target.value))} />
                    <output htmlFor={id}>{String(current)}</output>
                </div>
            );
            break;
        case 'enum':
            input = <Select id={id} disabled={disabled} value={String(current)} onChange={(e) => onChange(e.target.value)} options={entry.options ?? []} />;
            break;
        case 'password':
            input = (
                <TextInput
                    id={id}
                    type={reveal ? 'text' : 'password'}
                    autoComplete="new-password"
                    placeholder={t('config.unchanged')}
                    disabled={disabled}
                    value={String(current)}
                    onChange={(e) => onChange(e.target.value)}
                    actions={<IconButton icon={reveal ? IconEyeOff : IconEye} label={t(reveal ? 'ui.hide_password' : 'ui.show_password')} onClick={() => setReveal((v) => !v)} />}
                />
            );
            break;
        default:
            input = <TextInput id={id} maxLength={entry.max ?? 1000} placeholder={entry.placeholder} disabled={disabled} value={String(current)} onChange={(e) => onChange(e.target.value)} />;
    }

    return (
        <div className="us-setting">
            <div className="us-setting-label">
                <label htmlFor={id}>
                    <span className="us-config-title" title={name}>
                        {entry.title}
                    </span>
                    <span className="us-config-help">{help}</span>
                </label>
                {canReset && <IconButton icon={IconArrowBackUp} label={t('config.reset_tooltip', { value: shown(entry, entry.default!) })} onClick={() => setConfirmReset(true)} />}
            </div>
            <div className="us-setting-input">{input}</div>
            {confirmReset && (
                <Modal
                    open
                    onClose={() => setConfirmReset(false)}
                    icon={IconArrowBackUp}
                    heading={t('config.reset_heading', { setting: entry.title })}
                    description={t('config.reset_help', { value: shown(entry, entry.default!) })}
                    footer={
                        <ModalActions
                            submitLabel={t('config.reset')}
                            busy={false}
                            onCancel={() => setConfirmReset(false)}
                            onSubmit={() => {
                                onChange(entry.default!);
                                setConfirmReset(false);
                            }}
                        />
                    }
                />
            )}
        </div>
    );
}

/** The changes as a diff, then save, or save and restart. */
function ReviewModal({ restart, sources, changes, form, onClose, onSaved }: { restart: boolean; sources: Source[]; changes: Record<string, Record<string, Change>>; form: Form; onClose: () => void; onSaved: () => Promise<void> }) {
    const [busy, run] = useAction();

    const submit = async () => {
        const values = Object.fromEntries(Object.entries(changes).map(([source, list]) => [source, Object.fromEntries(Object.keys(list).map((key) => [key, form[source][key]]))]));
        const result = await run(async () => {
            const response = await postJson<SaveResult>('/config', { values, restart });
            response.notifications.forEach((n) => notify(n.status, n.title, n.body));
            await onSaved();
            return response;
        });
        if (result) onClose();
    };

    return (
        <Modal
            open
            onClose={onClose}
            width="lg"
            heading={t('config.review_heading')}
            description={t(restart ? 'config.save_restart_help' : 'config.review_help')}
            busy={busy}
            footer={<ModalActions submitLabel={t(restart ? 'config.save_restart' : 'config.save_changes')} busy={busy} onCancel={onClose} onSubmit={submit} />}
        >
            <div className="us-diff">
                {Object.entries(changes).map(([key, list]) => {
                    const source = sources.find((s) => s.key === key)!;
                    return (
                        <div key={key}>
                            <div className="us-diff-source">{source.title}</div>
                            {Object.entries(list).map(([name, change]) => {
                                const secret = change.entry.type === 'password';
                                return (
                                    <div key={name}>
                                        <div className="us-diff-title">{change.entry.title}</div>
                                        {change.error !== null ? (
                                            <div className="us-diff-error">{change.error}</div>
                                        ) : (
                                            <>
                                                <div className="us-diff-line is-old">- {diffLine(source.format, name, secret ? '••••••' : change.old)}</div>
                                                <div className="us-diff-line is-new">+ {diffLine(source.format, name, secret ? '••••••' : change.new)}</div>
                                            </>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    );
                })}
            </div>
        </Modal>
    );
}

function RestartModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
    const [busy, run] = useAction();
    const submit = async () => {
        const result = await run(() => postJson<{ status: Status; title: string }>('/config/restart'));
        if (result) {
            notify(result.status, result.title);
            onClose();
            onDone();
        }
    };
    return (
        <Modal
            open
            onClose={onClose}
            icon={IconReload}
            iconColor="warning"
            heading={t('config.restart_heading')}
            description={t('config.restart_help')}
            busy={busy}
            footer={<ModalActions submitLabel={t('config.restart')} color="warning" busy={busy} onCancel={onClose} onSubmit={submit} />}
        />
    );
}

declare global {
    interface Window {
        Livewire?: { navigate: (url: string) => void };
    }
}

/** Config files of mods and plugins. Picking one opens it in Pelican's file editor. */
function FilesTab() {
    const boot = useBoot();
    const canEdit = boot.can.configEdit;
    const { data } = useSWR<{ files: string[] }>(canEdit ? '/config/files' : null);
    const [picked, setPicked] = useState('');

    const open = (path: string) => {
        setPicked(path);
        if (!path) return;
        const url = boot.fileEditorUrl.replace('__PATH__', path.split('/').map(encodeURIComponent).join('/'));
        if (window.Livewire?.navigate) window.Livewire.navigate(url);
        else window.location.assign(url);
    };

    return (
        <div className="us-form">
            <Callout color="info" heading={t('config.files.help')} />
            <Field label={t('config.files.file')}>
                {canEdit && !data ? (
                    <Spinner />
                ) : (
                    <Select disabled={!canEdit} value={picked} onChange={(e) => open(e.target.value)} options={[['', t('config.files.pick')], ...(data?.files ?? []).map((path): [string, string] => [path, path])]} />
                )}
            </Field>
        </div>
    );
}
