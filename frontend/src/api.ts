import type { Boot } from './boot';

/** A failed request, with the title and reason the server sent for the notification. */
export class ApiError extends Error {
    constructor(
        public title: string,
        public body: string | null,
        public status: number,
    ) {
        super(body ? `${title}: ${body}` : title);
    }
}

let base = '';

export function setApiBase(boot: Boot): void {
    base = boot.api;
}

/** An API path ("/players") or a full URL, as one absolute URL. */
export function apiUrl(path: string): string {
    return /^https?:/.test(path) ? path : base + path;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function failure(response: Response): Promise<ApiError> {
    let title = `HTTP ${response.status}`;
    let body: string | null = null;
    try {
        const data = await response.json();
        if (typeof data?.message === 'string' && data.message) title = data.message;
        if (typeof data?.body === 'string' && data.body) body = data.body;
        // Laravel validation errors: show the first one as the reason.
        if (!body && data?.errors) body = String(Object.values(data.errors as Record<string, string[]>)[0]?.[0] ?? '') || null;
    } catch {
        // Not JSON, for example an HTML error page.
    }
    if (response.status === 419) body = 'Your session expired. Reload the page and try again.';
    return new ApiError(title, body, response.status);
}

/** GET as JSON. SWR uses this as its fetcher, with the API path as the key. */
export async function getJson<T>(path: string): Promise<T> {
    const response = await fetch(apiUrl(path), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw await failure(response);
    return response.json() as Promise<T>;
}

/** POST JSON with Laravel's CSRF token. */
export async function postJson<T>(path: string, data: unknown = {}): Promise<T> {
    const response = await fetch(apiUrl(path), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(data),
    });
    if (!response.ok) throw await failure(response);
    return response.json() as Promise<T>;
}
