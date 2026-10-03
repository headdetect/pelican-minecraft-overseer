import { useCallback, useEffect, useRef, useState } from 'react';
import { notifyError } from './notify';

/**
 * Runs one network action at a time and reports whether it is running, so the
 * button that started it can stay disabled. The finally block always clears
 * the busy state, whether the request worked, failed or threw.
 *
 *   const [busy, run] = useAction();
 *   <Button disabled={busy} onClick={() => run(() => postJson(...))}>
 *
 * Returns the action's result, or undefined when it failed (after a toast).
 */
export function useAction(): [boolean, <T>(action: () => Promise<T>) => Promise<T | undefined>] {
    const [busy, setBusy] = useState(false);
    const running = useRef(false);
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);

    const run = useCallback(async <T,>(action: () => Promise<T>): Promise<T | undefined> => {
        // A double click shouldn't send the command twice.
        if (running.current) return undefined;
        running.current = true;
        setBusy(true);
        try {
            return await action();
        } catch (error) {
            notifyError(error);
            return undefined;
        } finally {
            running.current = false;
            if (mounted.current) setBusy(false);
        }
    }, []);

    return [busy, run];
}
