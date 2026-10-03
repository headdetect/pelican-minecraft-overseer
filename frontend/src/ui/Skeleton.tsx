import type { CSSProperties } from 'react';
import { cx } from './classes';
import { t } from '../lang';

/**
 * Gray placeholder shapes for content that hasn't loaded yet. Pages show
 * them only before their first response arrives. A refresh keeps the data
 * already on screen, because SWR keeps it while it fetches again.
 */
export function Skeleton({ width, height = '1em', round = false, className, style }: { width?: string | number; height?: string | number; round?: boolean; className?: string; style?: CSSProperties }) {
    return <span className={cx('us-skeleton', round && 'is-round', className)} style={{ width, height, ...style }} aria-hidden="true" />;
}

/** A few lines of text, the last one shorter. */
export function SkeletonLines({ lines = 3, height = '0.85rem', gap = '0.6rem' }: { lines?: number; height?: string; gap?: string }) {
    return (
        <div className="us-skeleton-lines" style={{ gap }} aria-hidden="true">
            {Array.from({ length: lines }, (_, i) => (
                <Skeleton key={i} height={height} width={i === lines - 1 ? '60%' : `${92 - ((i * 13) % 20)}%`} />
            ))}
        </div>
    );
}

/** A row with a player head and two lines, as in Online now. */
export function SkeletonRow() {
    return (
        <div className="us-skeleton-row" aria-hidden="true">
            <Skeleton width={28} height={28} />
            <div className="us-skeleton-lines" style={{ gap: '0.35rem', flex: 1 }}>
                <Skeleton width="45%" height="0.8rem" />
                <Skeleton width="70%" height="0.7rem" />
            </div>
        </div>
    );
}

/** A field-sized block, for a select whose options are loading. */
export function SkeletonInput() {
    return <Skeleton className="us-skeleton-input" width="100%" height="2.25rem" />;
}

/** Tells screen readers the section is loading, since the shapes are hidden from them. */
export function Loading({ label = t('ui.loading') }: { label?: string }) {
    return (
        <span className="fi-sr-only" role="status">
            {label}
        </span>
    );
}
