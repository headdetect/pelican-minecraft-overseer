import { ApiError } from './api';
import { t } from './lang';

export type Status = 'success' | 'danger' | 'warning' | 'info';

interface FilamentNotificationApi {
    title(text: string): FilamentNotificationApi;
    body(text: string): FilamentNotificationApi;
    status(status: Status): FilamentNotificationApi;
    send(): void;
}

declare global {
    interface Window {
        FilamentNotification?: new () => FilamentNotificationApi;
    }
}

/** Shows a toast with Filament's notifications, so it looks like the rest of the panel. */
export function notify(status: Status, title: string, body?: string | null): void {
    const Notification = window.FilamentNotification;
    if (!Notification) {
        console[status === 'danger' ? 'error' : 'log'](title, body ?? '');
        return;
    }
    let notification = new Notification().title(title).status(status);
    if (body) notification = notification.body(body);
    notification.send();
}

/** A toast for a failed action. */
export function notifyError(error: unknown, fallbackTitle = t('players.notifications.failed')): void {
    if (error instanceof ApiError) {
        notify('danger', error.title, error.body);
    } else {
        notify('danger', fallbackTitle, error instanceof Error ? error.message : String(error));
    }
}

/** A toast for a finished action, from the server's {title, body}. */
export function notifyDone(result: { title?: string; body?: string | null } | null | undefined): void {
    if (result?.title) notify('success', result.title, result.body);
}
