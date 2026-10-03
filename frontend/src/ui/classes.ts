// Filament's own class names, copied from the markup its Blade components
// render. The panel already loads Filament's CSS, so these match the rest of
// Pelican in both themes.

export type Color = 'primary' | 'gray' | 'danger' | 'warning' | 'success' | 'info';

export function cx(...parts: Array<string | false | null | undefined>): string {
    return parts.filter(Boolean).join(' ');
}

const BUTTON: Record<Color, string> = {
    primary: 'fi-color fi-color-primary fi-bg-color-600 hover:fi-bg-color-500 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-0 hover:fi-text-color-0 dark:fi-text-color-0 dark:hover:fi-text-color-0',
    danger: 'fi-color fi-color-danger fi-bg-color-600 hover:fi-bg-color-500 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-0 hover:fi-text-color-0 dark:fi-text-color-0 dark:hover:fi-text-color-0',
    warning: 'fi-color fi-color-warning fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950',
    success: 'fi-color fi-color-success fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950',
    info: 'fi-color fi-color-info fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950',
    gray: '',
};

export function buttonClass(color: Color, size: 'sm' | 'md' = 'md', disabled = false): string {
    return cx(BUTTON[color], 'fi-btn', disabled && 'fi-disabled', `fi-size-${size}`);
}

const BADGE: Record<Color, string> = {
    primary: 'fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-200',
    warning: 'fi-color fi-color-warning fi-text-color-700 dark:fi-text-color-400',
    danger: 'fi-color fi-color-danger fi-text-color-700 dark:fi-text-color-200',
    info: 'fi-color fi-color-info fi-text-color-700 dark:fi-text-color-300',
    success: 'fi-color fi-color-success fi-text-color-700 dark:fi-text-color-300',
    gray: '',
};

export function badgeClass(color: Color, size: 'sm' | 'md' = 'md'): string {
    return cx(BADGE[color], 'fi-badge', `fi-size-${size}`);
}

const MENU_ITEM: Record<Color, string> = {
    gray: '',
    primary: 'fi-color fi-color-primary fi-text-color-600 hover:fi-text-color-700 dark:fi-text-color-400 dark:hover:fi-text-color-400',
    warning: 'fi-color fi-color-warning fi-text-color-700 hover:fi-text-color-700 dark:fi-text-color-400 dark:hover:fi-text-color-400',
    success: 'fi-color fi-color-success fi-text-color-700 hover:fi-text-color-700 dark:fi-text-color-400 dark:hover:fi-text-color-400',
    danger: 'fi-color fi-color-danger fi-text-color-600 hover:fi-text-color-700 dark:fi-text-color-400 dark:hover:fi-text-color-400',
    info: 'fi-color fi-color-info fi-text-color-700 hover:fi-text-color-700 dark:fi-text-color-400 dark:hover:fi-text-color-400',
};

export function menuItemClass(color: Color): string {
    return cx(MENU_ITEM[color], 'fi-dropdown-list-item');
}
