import {
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    type ButtonHTMLAttributes,
    type InputHTMLAttributes,
    type ReactNode,
    type SelectHTMLAttributes,
} from 'react';
import { createPortal } from 'react-dom';
import { IconX } from '@tabler/icons-react';
import { badgeClass, buttonClass, cx, menuItemClass, type Color } from './classes';
import { Icon, type IconComponent } from './icons';
import { t } from '../lang';

export { cx, type Color };

export function Spinner({ className = 'fi-icon fi-size-md' }: { className?: string }) {
    return (
        <svg className={cx(className, 'fi-loading-indicator')} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path clipRule="evenodd" d="M12 19C15.866 19 19 15.866 19 12C19 8.13401 15.866 5 12 5C8.13401 5 5 8.13401 5 12C5 15.866 8.13401 19 12 19ZM12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fillRule="evenodd" fill="currentColor" opacity="0.2" />
            <path d="M2 12C2 6.47715 6.47715 2 12 2V5C8.13401 5 5 8.13401 5 12H2Z" fill="currentColor" />
        </svg>
    );
}

type ButtonProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'color'> & {
    color?: Color;
    size?: 'sm' | 'md';
    icon?: IconComponent;
    /** Shows a spinner in place of the icon and disables the button. */
    busy?: boolean;
};

/** A Filament button. While busy it is disabled and shows a spinner. */
export function Button({ color = 'gray', size = 'md', icon, busy = false, disabled, className, children, type = 'button', ...rest }: ButtonProps) {
    const off = disabled || busy;
    return (
        <button type={type} className={cx(buttonClass(color, size, off), className)} disabled={off} aria-busy={busy || undefined} {...rest}>
            {busy ? <Spinner /> : icon ? <Icon icon={icon} /> : null}
            {children}
        </button>
    );
}

export function IconButton({ icon, label, busy = false, disabled, className, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { icon: IconComponent; label: string; busy?: boolean }) {
    return (
        <button type="button" className={cx('fi-icon-btn fi-size-sm', className)} title={label} aria-label={label} disabled={disabled || busy} {...rest}>
            {busy ? <Spinner /> : <Icon icon={icon} />}
        </button>
    );
}

export function Badge({ color = 'gray', size = 'md', children, title }: { color?: Color; size?: 'sm' | 'md'; children: ReactNode; title?: string }) {
    return (
        <span className={badgeClass(color, size)} title={title}>
            <span className="fi-badge-label-ctn">
                <span className="fi-badge-label">{children}</span>
            </span>
        </span>
    );
}

export function Section({
    heading,
    description,
    icon,
    afterHeader,
    compact = false,
    className,
    children,
}: {
    heading?: ReactNode;
    description?: ReactNode;
    icon?: IconComponent;
    afterHeader?: ReactNode;
    compact?: boolean;
    className?: string;
    children?: ReactNode;
}) {
    const hasHeader = heading !== undefined || description !== undefined;
    return (
        <section className={cx('fi-section', hasHeader && 'fi-section-has-header', compact && 'fi-compact', className)}>
            {hasHeader && (
                <header className="fi-section-header">
                    {icon && <Icon icon={icon} className="fi-icon fi-size-lg fi-section-header-icon" />}
                    <div className="fi-section-header-text-ctn">
                        {heading !== undefined && <h2 className="fi-section-header-heading">{heading}</h2>}
                        {description !== undefined && <p className="fi-section-header-description">{description}</p>}
                    </div>
                    {afterHeader && <div className="fi-section-header-after-ctn">{afterHeader}</div>}
                </header>
            )}
            <div className="fi-section-content-ctn">
                <div className="fi-section-content">{children}</div>
            </div>
        </section>
    );
}

export function Callout({ color, icon, heading, description, children }: { color: Color; icon?: IconComponent; heading: ReactNode; description?: ReactNode; children?: ReactNode }) {
    return (
        <div className={cx('fi-callout fi-color', `fi-color-${color}`)}>
            {icon && <Icon icon={icon} className="fi-icon fi-size-lg fi-callout-icon" />}
            <div className="fi-callout-main">
                <div className="fi-callout-text">
                    <h4 className="fi-callout-heading">{heading}</h4>
                    {description && <p className="fi-callout-description">{description}</p>}
                </div>
                {children}
            </div>
        </div>
    );
}

// ---- forms ----

export function Field({
    label,
    help,
    error,
    htmlFor,
    inline = false,
    hint,
    children,
}: {
    label?: ReactNode;
    help?: ReactNode;
    error?: string | null;
    htmlFor?: string;
    inline?: boolean;
    hint?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className={cx('fi-fo-field', inline && 'fi-fo-field-has-inline-label')}>
            {label !== undefined && (
                <div className={cx('fi-fo-field-label-col', inline && 'fi-vertical-align-center')}>
                    <div className="fi-fo-field-label-ctn">
                        <label htmlFor={htmlFor} className="fi-fo-field-label">
                            <span className="fi-fo-field-label-content">{label}</span>
                        </label>
                        {hint}
                    </div>
                </div>
            )}
            <div className="fi-fo-field-content-col">
                {children}
                {error && <p className="fi-fo-field-wrp-error-message">{error}</p>}
                {help && <div className="fi-fo-field-wrp-helper-text fi-sc-text">{help}</div>}
            </div>
        </div>
    );
}

type TextInputProps = InputHTMLAttributes<HTMLInputElement> & {
    prefixIcon?: IconComponent;
    prefix?: string;
    suffix?: ReactNode;
    actions?: ReactNode;
    invalid?: boolean;
};

export function TextInput({ prefixIcon, prefix, suffix, actions, invalid, className, disabled, ...rest }: TextInputProps) {
    return (
        <div className={cx('fi-input-wrp', disabled && 'fi-disabled', invalid && 'fi-invalid', className)}>
            {(prefixIcon || prefix) && (
                <div className={cx('fi-input-wrp-prefix fi-input-wrp-prefix-has-content', prefix ? 'fi-input-wrp-prefix-has-label' : 'fi-inline')}>
                    {prefixIcon && <Icon icon={prefixIcon} className="fi-icon fi-size-md fi-input-wrp-icon" />}
                    {prefix && <span className="fi-input-wrp-label">{prefix}</span>}
                </div>
            )}
            <div className="fi-input-wrp-content-ctn">
                <input className={cx('fi-input', (prefixIcon || prefix) && 'fi-input-has-inline-prefix')} disabled={disabled} {...rest} />
            </div>
            {suffix !== undefined && suffix !== null && (
                <div className="fi-input-wrp-suffix fi-input-wrp-suffix-has-label">
                    <span className="fi-input-wrp-label">{suffix}</span>
                </div>
            )}
            {actions && (
                <div className="fi-input-wrp-suffix">
                    <div className="fi-input-wrp-actions">{actions}</div>
                </div>
            )}
        </div>
    );
}

export function Select({ options, className, disabled, ...rest }: SelectHTMLAttributes<HTMLSelectElement> & { options: Array<[string, string]> }) {
    return (
        <div className={cx('fi-input-wrp', disabled && 'fi-disabled', className)}>
            <div className="fi-input-wrp-content-ctn">
                <select className="fi-select-input" disabled={disabled} {...rest}>
                    {options.map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );
}

export function Toggle({ checked, onChange, disabled, id, label }: { checked: boolean; onChange: (value: boolean) => void; disabled?: boolean; id?: string; label?: string }) {
    return (
        <button
            type="button"
            role="switch"
            id={id}
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cx('fi-fo-toggle fi-toggle', checked ? 'fi-toggle-on fi-color fi-color-primary fi-bg-color-500 fi-text-color-500 dark:fi-bg-color-400' : 'fi-toggle-off')}
        >
            <div>
                <div aria-hidden="true"></div>
                <div aria-hidden="true"></div>
            </div>
        </button>
    );
}

/** A labelled toggle row, for forms in modals. */
export function ToggleField({ label, help, checked, onChange }: { label: string; help?: string; checked: boolean; onChange: (value: boolean) => void }) {
    const id = useId();
    return (
        <div className="us-toggle-field">
            <Toggle id={id} checked={checked} onChange={onChange} />
            <label htmlFor={id}>
                <span className="fi-fo-field-label-content">{label}</span>
                {help && <span className="fi-fo-field-wrp-helper-text">{help}</span>}
            </label>
        </div>
    );
}

export function Radios({ value, options, onChange, name }: { value: string; options: Array<[string, string]>; onChange: (value: string) => void; name: string }) {
    return (
        <div className="us-radios" role="radiogroup">
            {options.map(([key, label]) => (
                <label key={key} className="us-radio">
                    <input type="radio" className="fi-radio-input" name={name} value={key} checked={value === key} onChange={() => onChange(key)} />
                    <span>{label}</span>
                </label>
            ))}
        </div>
    );
}

// ---- modal ----

/**
 * A Filament modal, rendered in a portal on <body>. Escape and a click on
 * the backdrop close it, unless `busy` says a request is running.
 */
export function Modal({
    open,
    onClose,
    heading,
    description,
    icon,
    iconColor = 'primary',
    width = 'md',
    busy = false,
    footer,
    children,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    heading: ReactNode;
    description?: ReactNode;
    icon?: IconComponent;
    iconColor?: Color;
    width?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    busy?: boolean;
    footer?: ReactNode;
    children?: ReactNode;
    /** Makes the modal a form, so Enter submits it. */
    onSubmit?: () => void;
}) {
    const id = useId();
    const windowRef = useRef<HTMLDivElement>(null);
    const [entered, setEntered] = useState(false);

    useLayoutEffect(() => {
        if (!open) {
            setEntered(false);
            return;
        }
        const frame = requestAnimationFrame(() => setEntered(true));
        return () => cancelAnimationFrame(frame);
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const previous = document.activeElement as HTMLElement | null;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !busy) {
                e.stopPropagation();
                onClose();
            }
        };
        window.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        // Focus the first field, or the window itself.
        const first = windowRef.current?.querySelector<HTMLElement>('input:not([type=hidden]):not([disabled]), select, textarea');
        (first ?? windowRef.current)?.focus();
        return () => {
            window.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
            previous?.focus?.();
        };
    }, [open, busy, onClose]);

    if (!open) return null;

    const body = (
        <>
            <div className="fi-modal-header">
                <button type="button" className="fi-icon-btn fi-size-md fi-modal-close-btn" title={t('ui.close')} aria-label={t('ui.close')} tabIndex={-1} onClick={() => !busy && onClose()}>
                    <Icon icon={IconX} className="fi-icon fi-size-lg" />
                </button>
                {icon && (
                    <div className="fi-modal-icon-ctn">
                        <div className={cx('fi-color fi-modal-icon-bg', `fi-color-${iconColor}`)}>
                            <Icon icon={icon} className="fi-icon fi-size-lg fi-modal-icon" />
                        </div>
                    </div>
                )}
                <div>
                    <h2 id={`${id}-heading`} className="fi-modal-heading">
                        {heading}
                    </h2>
                    {description && (
                        <p id={`${id}-description`} className="fi-modal-description">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            {children && <div className="fi-modal-content">{children}</div>}
            {footer && (
                <div className="fi-modal-footer fi-align-start">
                    <div className="fi-modal-footer-actions">{footer}</div>
                </div>
            )}
        </>
    );

    const windowClass = cx(
        'fi-modal-window fi-modal-window-has-close-btn fi-align-start',
        `fi-width-${width}`,
        Boolean(children) && 'fi-modal-window-has-content',
        Boolean(footer) && 'fi-modal-window-has-footer',
        Boolean(icon) && 'fi-modal-window-has-icon',
        entered ? 'fi-transition-enter-end' : 'fi-transition-enter-start',
        'fi-transition-enter',
    );

    return createPortal(
        <div role="dialog" aria-modal="true" aria-labelledby={`${id}-heading`} className="fi-modal fi-modal-open fi-absolute-positioning-context us-modal">
            <div aria-hidden="true" className="fi-modal-close-overlay" />
            <div
                className="fi-modal-window-ctn fi-clickable"
                onMouseDown={(e) => {
                    if (e.target === e.currentTarget && !busy) onClose();
                }}
            >
                {onSubmit ? (
                    <form
                        ref={windowRef as unknown as React.RefObject<HTMLFormElement>}
                        tabIndex={-1}
                        className={windowClass}
                        noValidate={false}
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!busy) onSubmit();
                        }}
                    >
                        {body}
                    </form>
                ) : (
                    <div ref={windowRef} tabIndex={-1} className={windowClass}>
                        {body}
                    </div>
                )}
            </div>
        </div>,
        document.body,
    );
}

/**
 * The usual footer: a submit button that shows a spinner while busy, and Cancel.
 */
export function ModalActions({ submitLabel, color = 'primary', busy, disabled, onCancel, cancelLabel = t('ui.cancel'), onSubmit }: { submitLabel: string; color?: Color; busy: boolean; disabled?: boolean; onCancel: () => void; cancelLabel?: string; onSubmit?: () => void }) {
    return (
        <>
            <Button type={onSubmit ? 'button' : 'submit'} color={color} busy={busy} disabled={disabled} onClick={onSubmit}>
                {submitLabel}
            </Button>
            <Button color="gray" onClick={onCancel} disabled={busy}>
                {cancelLabel}
            </Button>
        </>
    );
}

// ---- dropdown ----

export interface MenuItem {
    label: string;
    icon?: IconComponent;
    color?: Color;
    onSelect: () => void;
}

/** A Filament dropdown menu, placed under its trigger and flipped up when there is no room. */
export function Dropdown({ trigger, items, align = 'end' }: { trigger: (props: { open: boolean; toggle: () => void }) => ReactNode; items: MenuItem[]; align?: 'start' | 'end' }) {
    const [open, setOpen] = useState(false);
    const [style, setStyle] = useState<React.CSSProperties>({});
    const anchor = useRef<HTMLDivElement>(null);
    const panel = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        if (!open || !anchor.current || !panel.current) return;
        const a = anchor.current.getBoundingClientRect();
        const p = panel.current.getBoundingClientRect();
        const below = a.bottom + 8 + p.height <= window.innerHeight;
        const top = below ? a.bottom + 8 : a.top - 8 - p.height;
        const left = align === 'end' ? a.right - p.width : a.left;
        setStyle({ position: 'fixed', top: Math.max(8, top), left: Math.max(8, left), zIndex: 30 });
    }, [open, align]);

    useEffect(() => {
        if (!open) return;
        const close = (e: Event) => {
            if (anchor.current?.contains(e.target as Node) || panel.current?.contains(e.target as Node)) return;
            setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', close);
        window.addEventListener('keydown', onKey);
        window.addEventListener('scroll', () => setOpen(false), { once: true, capture: true });
        return () => {
            document.removeEventListener('mousedown', close);
            window.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="fi-dropdown" ref={anchor}>
            <div className="fi-dropdown-trigger">{trigger({ open, toggle: () => setOpen((v) => !v) })}</div>
            {open &&
                createPortal(
                    <div ref={panel} className="fi-dropdown-panel us-dropdown-panel" style={{ ...style, visibility: style.position ? 'visible' : 'hidden' }} role="menu">
                        <div className="fi-dropdown-list">
                            {items.map((item) => (
                                <button
                                    key={item.label}
                                    type="button"
                                    role="menuitem"
                                    className={menuItemClass(item.color ?? 'gray')}
                                    onClick={() => {
                                        setOpen(false);
                                        item.onSelect();
                                    }}
                                >
                                    {item.icon && <Icon icon={item.icon} className="fi-icon fi-size-md fi-dropdown-list-item-icon" />}
                                    <span className="fi-dropdown-list-item-label">{item.label}</span>
                                </button>
                            ))}
                        </div>
                    </div>,
                    document.body,
                )}
        </div>
    );
}

export function Tabs({ children, vertical = false, label = 'Tabs', className }: { children: ReactNode; vertical?: boolean; label?: string; className?: string }) {
    return (
        <nav className={cx('fi-tabs', vertical && 'fi-vertical', className)} aria-label={label}>
            {children}
        </nav>
    );
}

export function TabItem({ active, icon, badge, onClick, href, children }: { active: boolean; icon?: IconComponent; badge?: ReactNode; onClick: () => void; href?: string; children: ReactNode }) {
    const content = (
        <>
            {icon && <Icon icon={icon} />}
            <span className="fi-tabs-item-label">{children}</span>
            {badge !== undefined && badge !== null && <Badge color="primary" size="sm">{badge}</Badge>}
        </>
    );
    if (href) {
        return (
            <a
                href={href}
                className={cx('fi-tabs-item', active && 'fi-active')}
                aria-current={active ? 'page' : undefined}
                onClick={(e) => {
                    // Let ctrl/cmd-click open a new tab.
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
                    e.preventDefault();
                    onClick();
                }}
            >
                {content}
            </a>
        );
    }
    return (
        <button type="button" className={cx('fi-tabs-item', active && 'fi-active')} aria-current={active ? 'true' : undefined} onClick={onClick}>
            {content}
        </button>
    );
}
