export interface World {
    name: string;
    label: string;
    type: string;
    max: number;
    def: number;
    extra: number;
    spawn: { x: number; z: number };
}

export interface MapConfig {
    mode: 'squaremap' | 'grid';
    worlds: World[];
    setup: { title: string; body: string } | null;
}

export interface MapPlayer {
    name: string;
    world: string;
    x: number;
    y: number | null;
    z: number;
    yaw: number | null;
    health: number | null;
    op: boolean;
}

export interface UnmappedPlayer {
    name: string;
    dead: boolean;
    op: boolean;
}

export interface ChatLine {
    time: string;
    type: 'chat' | 'say' | 'join' | 'leave';
    name: string;
    text: string;
}

export interface GameTime {
    day: number;
    clock: string;
    phase: 'day' | 'sunset' | 'night' | 'sunrise';
}

export interface Feed {
    ok: boolean;
    players: MapPlayer[];
    unmapped: UnmappedPlayer[];
    time: GameTime | null;
    chat: ChatLine[];
    server: {
        version: string | null;
        modpack: { name: string; version: string | null; provider: string; url: string | null } | null;
        uptime: string | null;
    };
}

export interface ChunkyTask {
    world: string;
    chunks: number;
    percent: number;
    eta: string | null;
    rate: number | null;
}

export interface Stats {
    running: boolean;
    cpu: number | null;
    cpu_limit: number;
    memory: number | null;
    memory_text: string | null;
    memory_limit: number;
    memory_limit_text: string | null;
    disk: number | null;
    disk_text: string | null;
    disk_limit: number;
    disk_limit_text: string | null;
}
