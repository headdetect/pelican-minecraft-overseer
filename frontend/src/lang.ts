// Translations from lang/en/overseer.php, sent with the boot payload.
let strings: Record<string, unknown> = {};

export function setLang(lang: Record<string, unknown>): void {
    strings = lang;
}

function lookup(key: string): unknown {
    return key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), strings);
}

function fill(text: string, params: Record<string, string | number>): string {
    // Longest names first, so :name doesn't eat part of :names.
    return Object.keys(params)
        .sort((a, b) => b.length - a.length)
        .reduce((out, name) => out.split(`:${name}`).join(String(params[name])), text);
}

/** A translated string, like Laravel's trans(). Returns the key when it is missing. */
export function t(key: string, params: Record<string, string | number> = {}): string {
    const value = lookup(key);
    return typeof value === 'string' ? fill(value, params) : key;
}

/** A translated list or map, like trans() on a group. */
export function tGroup<T = Record<string, string>>(key: string): T {
    return (lookup(key) ?? {}) as T;
}

/** "one|many" strings, like Laravel's trans_choice() for English. */
export function choice(key: string, count: number, params: Record<string, string | number> = {}): string {
    const [one, many = one] = t(key, params).split('|');
    return fill(count === 1 ? one : many, { count, ...params });
}
