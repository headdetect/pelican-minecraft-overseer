import { useCallback, useEffect, useState } from 'react';
import useSWR, { SWRConfig } from 'swr';
import { IconAlertTriangle, IconLayoutDashboard, IconSettings2, IconShieldExclamation, IconTool, IconUsersGroup } from '@tabler/icons-react';
import { getJson } from './api';
import { useBoot, type RconState, type TabKey } from './boot';
import { t } from './lang';
import { RefreshControl, RefreshProvider, useRefresh } from './refresh';
import { Callout, TabItem, Tabs } from './ui';
import type { IconComponent } from './ui/icons';
import Config from './pages/config/Config';
import Overview from './pages/overview/Overview';
import Players from './pages/players/Players';
import Tools from './pages/tools/Tools';

const ICONS: Record<TabKey, IconComponent> = {
    overview: IconLayoutDashboard,
    players: IconUsersGroup,
    config: IconSettings2,
    tools: IconTool,
};

function tabFromPath(tabs: { key: TabKey; url: string }[]): TabKey | null {
    const path = window.location.pathname.replace(/\/+$/, '');
    return tabs.find((tab) => new URL(tab.url, window.location.origin).pathname.replace(/\/+$/, '') === path)?.key ?? null;
}

/**
 * Switches tabs without a page load. The tab's URL goes into the address bar,
 * so a reload or a shared link opens the same tab, and Back and Forward move
 * between tabs.
 */
function useTabs(): [TabKey, (tab: TabKey) => void] {
    const boot = useBoot();
    // The address bar wins over the boot tab: after Back from another panel page,
    // Livewire restores the HTML of the tab the page first loaded with.
    const [tab, setTab] = useState<TabKey>(() => tabFromPath(boot.tabs) ?? boot.tab);

    useEffect(() => {
        const onPop = (e: PopStateEvent) => {
            const next = tabFromPath(boot.tabs);
            if (!next) return;
            // Livewire's own Back handler would swap in the whole page again. This one
            // runs first (capture phase), and the app shows the tab in place.
            e.stopImmediatePropagation();
            setTab(next);
        };
        window.addEventListener('popstate', onPop, { capture: true });
        return () => window.removeEventListener('popstate', onPop, { capture: true });
    }, [boot.tabs]);

    useEffect(() => {
        const label = boot.tabs.find((item) => item.key === tab)?.label;
        if (!label) return;
        // Pelican titles pages "<title> - <app name>".
        const [, ...rest] = document.title.split(' - ');
        document.title = [label, ...rest].join(' - ');
    }, [tab, boot.tabs]);

    const go = useCallback(
        (next: TabKey) => {
            const target = boot.tabs.find((item) => item.key === next);
            if (!target) return;
            if (next !== tab) {
                window.history.pushState({ overseer: next }, '', target.url);
                window.scrollTo({ top: 0 });
            }
            setTab(next);
        },
        [boot.tabs, tab],
    );

    return [tab, go];
}

function RconWarning() {
    const boot = useBoot();
    const { data, mutate } = useSWR<RconState>('/rcon', getJson, { fallbackData: boot.rcon, revalidateOnMount: false });
    // Recheck on manual refresh only. The state rarely changes while the page is open.
    useRefresh((manual) => (manual ? mutate() : undefined));

    return (
        <>
            {data?.exposed && <Callout color="danger" icon={IconShieldExclamation} heading={t('rcon.exposed.title', { port: data.exposed })} description={t('rcon.exposed.body', { port: data.exposed })} />}
            {(data?.state === 'off' || data?.state === 'unreachable') && <Callout color="warning" icon={IconAlertTriangle} heading={t(`rcon.${data.state}.title`)} description={t(`rcon.${data.state}.body`)} />}
        </>
    );
}

function Page({ tab }: { tab: TabKey }) {
    switch (tab) {
        case 'overview':
            return <Overview />;
        case 'players':
            return <Players />;
        case 'config':
            return <Config />;
        case 'tools':
            return <Tools />;
    }
}

export default function App() {
    const boot = useBoot();
    const [tab, go] = useTabs();
    const title = boot.tabs.find((item) => item.key === tab)?.label ?? '';

    return (
        <SWRConfig
            value={{
                fetcher: getJson,
                // The refresh control decides when to fetch again, so focus doesn't.
                revalidateOnFocus: false,
                revalidateIfStale: true,
                shouldRetryOnError: false,
                keepPreviousData: true,
            }}
        >
            <RefreshProvider>
                <div className="fi-page fi-page-has-sub-navigation fi-page-has-sub-navigation-top us-app">
                    <div className="fi-page-header-main-ctn">
                        <header className="fi-header fi-header-has-breadcrumbs">
                            <div>
                                <nav className="fi-breadcrumbs" aria-label="Breadcrumb">
                                    <ol className="fi-breadcrumbs-list">
                                        <li className="fi-breadcrumbs-item">
                                            <span className="fi-breadcrumbs-item-label">{boot.cluster}</span>
                                        </li>
                                    </ol>
                                </nav>
                                <h1 className="fi-header-heading">{title}</h1>
                            </div>
                            {/* Pages put their header buttons here with a portal, like Config's Save. */}
                            <div id="us-header-actions" className="us-header-actions" />
                        </header>
                        <div className="fi-page-main">
                            <div className="us-tabrow">
                                <Tabs className="us-tabs">
                                    {boot.tabs.map((item) => (
                                        <TabItem key={item.key} active={item.key === tab} icon={ICONS[item.key]} href={item.url} onClick={() => go(item.key)}>
                                            {item.label}
                                        </TabItem>
                                    ))}
                                </Tabs>
                                {/* Not on Config, where a refresh would drop unsaved edits. */}
                                {tab !== 'config' && <RefreshControl />}
                            </div>
                            <div className="fi-page-content us-page-content">
                                <RconWarning />
                                <Page tab={tab} />
                            </div>
                        </div>
                    </div>
                </div>
            </RefreshProvider>
        </SWRConfig>
    );
}
