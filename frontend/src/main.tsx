import { StrictMode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import App from './App';
import { setApiBase } from './api';
import { BootContext, type Boot } from './boot';
import { setLang } from './lang';
import './styles/app.css';
import './styles/map.css';

/**
 * Mounts the app into #overseer-app. The panel uses Livewire's wire:navigate,
 * which swaps the page body without a reload, so the app mounts again after
 * each visit and unmounts before Livewire leaves the page.
 */
let mounted: { el: Element; root: Root } | null = null;

function unmount(): void {
    mounted?.root.unmount();
    mounted = null;
}

function mount(): void {
    const el = document.getElementById('overseer-app');
    if (!el || mounted?.el === el) return;
    unmount();

    const boot = JSON.parse(el.getAttribute('data-boot') ?? '{}') as Boot;
    setLang(boot.lang);
    setApiBase(boot);

    const root = createRoot(el);
    root.render(
        <StrictMode>
            <BootContext.Provider value={boot}>
                <App />
            </BootContext.Provider>
        </StrictMode>,
    );
    mounted = { el, root };
}

document.addEventListener('livewire:navigating', unmount);
document.addEventListener('livewire:navigated', mount);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
} else {
    mount();
}
