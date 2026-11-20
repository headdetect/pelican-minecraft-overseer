import { useCallback, useEffect, useRef, useState } from 'react';
import { mutate } from 'swr';
import { postJson } from '../../api';
import type { RconState } from '../../boot';
import type { ChunkyTask, Feed } from './types';

/** Give up waiting for a poke's answer after this long, so the refresh button doesn't spin forever. */
const ANSWER_TIMEOUT = 15000;

interface FeedEvent {
    seq: number;
    feed: Feed;
    chunky?: ChunkyTask[];
    rcon: RconState['state'];
}

interface Waiter {
    seq: number;
    resolve: () => void;
}

/**
 * The Overview's live feed, over one long-lived request (see MapFeedController).
 * The server holds one RCON connection while the stream is open, and the
 * browser closes the stream when the page goes away.
 *
 * refresh() asks the stream for a fresh feed and resolves when it arrives.
 */
export function useFeedStream(url: string | null) {
    const [feed, setFeed] = useState<Feed | undefined>(undefined);
    const [chunky, setChunky] = useState<ChunkyTask[]>([]);
    const [error, setError] = useState(false);
    const stream = useRef<string | null>(null);
    const seq = useRef(0);
    const waiters = useRef<Waiter[]>([]);

    // Resolves the refreshes this answer covers. Seq 0 is a new stream's first feed, which covers every one.
    const settle = useCallback((answered: number) => {
        waiters.current = waiters.current.filter((waiter) => {
            if (answered !== 0 && waiter.seq > answered) return true;
            waiter.resolve();
            return false;
        });
    }, []);

    useEffect(() => {
        if (!url) return;
        const source = new EventSource(url, { withCredentials: true });

        source.addEventListener('hello', (e) => {
            stream.current = (JSON.parse((e as MessageEvent).data) as { stream: string }).stream;
        });
        source.addEventListener('feed', (e) => {
            const data = JSON.parse((e as MessageEvent).data) as FeedEvent;
            setFeed(data.feed);
            setError(false);
            if (data.chunky) setChunky(data.chunky);
            // The RCON warning's data, without a request of its own.
            mutate<RconState>('/rcon', (current) => (current ? { ...current, state: data.rcon } : current), { revalidate: false });
            settle(data.seq);
        });
        source.addEventListener('failed', (e) => {
            setError(true);
            settle((JSON.parse((e as MessageEvent).data) as { seq: number }).seq);
        });
        // The browser reconnects on its own after a drop. Until then, the feed is stale.
        source.onerror = () => {
            stream.current = null;
            setError(true);
        };

        return () => {
            source.close();
            stream.current = null;
            settle(0);
        };
    }, [url, settle]);

    const refresh = useCallback(
        (manual: boolean): Promise<void> =>
            new Promise((resolve) => {
                const next = ++seq.current;
                const timer = setTimeout(() => settle(next), ANSWER_TIMEOUT);
                waiters.current.push({
                    seq: next,
                    resolve: () => {
                        clearTimeout(timer);
                        resolve();
                    },
                });
                // Still connecting: the new stream's first feed answers this.
                if (!stream.current) return;
                postJson(`/feed/${stream.current}`, { seq: next, manual }).catch(() => settle(next));
            }),
        [settle],
    );

    return { feed, chunky, error, refresh };
}
