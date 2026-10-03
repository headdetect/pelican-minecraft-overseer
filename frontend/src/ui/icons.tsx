import type { ComponentType } from 'react';
import {
    IconAdjustments,
    IconFileCode,
    IconFileSettings,
    IconServerCog,
    IconSettings,
    type IconProps,
} from '@tabler/icons-react';

export type IconComponent = ComponentType<IconProps>;

/** Filament's icon size classes on a Tabler icon. */
export function Icon({ icon: Glyph, className = 'fi-icon fi-size-md', ...rest }: { icon: IconComponent; className?: string } & IconProps) {
    return <Glyph className={className} aria-hidden="true" {...rest} />;
}

// Icons named in PHP (the config schema files), by their Blade icon name.
const NAMED: Record<string, IconComponent> = {
    'tabler-server-cog': IconServerCog,
    'tabler-adjustments': IconAdjustments,
    'tabler-file-settings': IconFileSettings,
    'tabler-file-code': IconFileCode,
};

export function namedIcon(name: string): IconComponent {
    return NAMED[name] ?? IconSettings;
}
