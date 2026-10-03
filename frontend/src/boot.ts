import { createContext, useContext } from 'react';

export type TabKey = 'overview' | 'players' | 'config' | 'tools';

export interface Tab {
    key: TabKey;
    label: string;
    url: string;
}

export interface Abilities {
    mapView: boolean;
    playersView: boolean;
    kick: boolean;
    ban: boolean;
    op: boolean;
    whitelist: boolean;
    cheat: boolean;
    commandsWorld: boolean;
    commandsOps: boolean;
    configView: boolean;
    configEdit: boolean;
    tools: boolean;
    restart: boolean;
}

export interface RconState {
    state: 'ok' | 'off' | 'unreachable' | null;
    exposed: number | null;
}

/** What the server renders into the page for the first paint. See AppBoot.php. */
export interface Boot {
    tab: TabKey;
    tabs: Tab[];
    cluster: string;
    api: string;
    feedUrl: string;
    surfaceUrl: string;
    tileBase: string;
    headUrl: string;
    fileEditorUrl: string;
    reasons: string[];
    can: Abilities;
    rcon: RconState;
    lang: Record<string, unknown>;
}

export const BootContext = createContext<Boot | null>(null);

export function useBoot(): Boot {
    const boot = useContext(BootContext);
    if (!boot) throw new Error('useBoot outside BootContext');
    return boot;
}

export function headUrl(boot: Boot, name: string | undefined | null): string {
    return boot.headUrl.replace('{name}', encodeURIComponent(name ?? ''));
}
