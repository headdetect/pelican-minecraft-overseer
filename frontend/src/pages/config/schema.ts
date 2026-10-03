// The checks from src/Support/ConfigSchema.php and ConfigEditor::changes(),
// so the page can mark bad values and count changes while the admin types.
// The server runs the PHP versions again before it saves. Keep them in step.

import { t } from '../../lang';

export type Value = boolean | number | string;

export interface Entry {
    title: string;
    help: string;
    type: 'bool' | 'int' | 'range' | 'enum' | 'password' | 'string';
    options?: Array<[string, string]>;
    min?: number;
    max?: number;
    unit?: string;
    placeholder?: string;
    default?: Value;
    group: string;
    restart?: boolean;
    advanced?: boolean;
}

export interface Source {
    key: string;
    title: string;
    icon: string;
    format: 'properties' | 'yaml' | 'gamerules';
    unavailable: string | null;
    entries: Record<string, Entry>;
    values: Record<string, Value>;
}

export interface Change {
    entry: Entry;
    old: string | null;
    new: string | null;
    error: string | null;
}

/** A form or file value as plain text, for comparing without checking it. */
export function plain(value: unknown): string {
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    return String(value ?? '').trim();
}

/** Whether a form value equals the entry's default. */
export function isDefault(entry: Entry, value: unknown): boolean {
    if (entry.default === undefined) return true;
    const def = entry.default;
    switch (entry.type) {
        case 'bool':
            return toBool(value) === def;
        case 'int':
        case 'range':
            return /^-?\d+$/.test(String(value).trim()) && Number(value) === Number(def);
        default:
            return String(value ?? '').trim() === String(def).trim();
    }
}

function toBool(value: unknown): boolean | null {
    if (typeof value === 'boolean') return value;
    const text = String(value).toLowerCase().trim();
    if (['1', 'true', 'on', 'yes'].includes(text)) return true;
    if (['0', 'false', 'off', 'no', ''].includes(text)) return false;
    return null;
}

/** Checks a form value and returns the text for the file. Throws Error with a message an admin can act on. */
export function toFile(entry: Entry, value: unknown): string {
    const title = entry.title;
    switch (entry.type) {
        case 'bool':
            return toBool(value) ? 'true' : 'false';
        case 'int':
        case 'range': {
            const text = typeof value === 'number' ? String(value) : String(value ?? '').trim();
            if (!/^-?\d+$/.test(text)) throw new Error(`${title} must be a whole number.`);
            const n = Number(text);
            if (entry.min !== undefined && n < entry.min) throw new Error(`${title} can't be less than ${entry.min}.`);
            if (entry.max !== undefined && n > entry.max) throw new Error(`${title} can't be more than ${entry.max}.`);
            return String(n);
        }
        case 'enum': {
            const text = String(value);
            if (!(entry.options ?? []).some(([key]) => key === text)) throw new Error(`"${text}" is not a choice for ${title}.`);
            return text;
        }
        default: {
            // One line of text with no control characters: a newline would start a new setting.
            // eslint-disable-next-line no-control-regex
            let text = String(value ?? '').replace(/[\x00-\x1F\x7F]+/gu, ' ');
            // \n typed in the form is a line break in the file, as Minecraft reads it.
            text = text.split('\\n').join('\n');
            const max = entry.max ?? 1000;
            if ([...text].length > max) throw new Error(`${title} can be at most ${max} characters.`);
            return text.trim();
        }
    }
}

/** Settings whose form value differs from the loaded one. */
export function changes(source: Source, form: Record<string, Value>): Record<string, Change> {
    const result: Record<string, Change> = {};
    for (const [key, entry] of Object.entries(source.entries)) {
        const current = form[key];
        if (entry.type === 'password') {
            const text = String(current ?? '');
            if (text.trim() !== '') {
                // eslint-disable-next-line no-control-regex
                const error = /[\x00-\x1F\x7F\s]/.test(text) ? t('config.password_spaces') : [...text].length > 100 ? t('config.password_long') : null;
                result[key] = { entry, old: null, new: error ? null : text, error };
            }
            continue;
        }
        const old = plain(source.values[key]);
        // Untouched: don't re-check it, so a value outside our limits that was already in the file stays as it is.
        if (plain(current) === old) continue;
        let next: string | null = null;
        let error: string | null = null;
        try {
            next = toFile(entry, current);
        } catch (e) {
            error = (e as Error).message;
        }
        if (next !== old) result[key] = { entry, old, new: next, error };
    }
    return result;
}

/** Whether a setting matches what was typed in the search box. */
export function matches(key: string, entry: Entry, search: string): boolean {
    const needle = search.trim().toLowerCase();
    return needle === '' || `${entry.title} ${entry.help} ${key}`.toLowerCase().includes(needle);
}

/** A value as the reset confirmation shows it. */
export function shown(entry: Entry, value: Value): string {
    if (typeof value === 'boolean') return t(value ? 'config.on' : 'config.off');
    if (entry.type === 'enum') return entry.options?.find(([key]) => key === String(value))?.[1] ?? String(value);
    if (value === '') return t('config.empty');
    return `${value}${entry.unit ? ` ${entry.unit}` : ''}`;
}

/** One line of the review diff, in the file's own syntax. */
export function diffLine(format: Source['format'], key: string, value: string | null): string {
    if (format === 'properties') return `${key}=${value}`;
    if (format === 'yaml') return `${key}: ${value}`;
    return `gamerule ${key} ${value}`;
}
