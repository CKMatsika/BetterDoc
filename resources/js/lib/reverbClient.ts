export interface ReverbClientConfig {
    enabled: boolean;
    key: string;
    host: string;
    port: number;
    scheme: string;
}

export interface PresenceMember {
    id: string;
    info: {
        name?: string;
        clearance_rank?: number;
        org_unit?: string | null;
    };
}

export type ChannelEventHandler = (eventName: string, data: unknown) => void;

/**
 * Lightweight zero-dependency Pusher/Reverb WebSocket client speaking LAN WebSocket + Laravel /broadcasting/auth.
 * Operates strictly within the local network with zero external CDN scripts.
 */
export class BetterDocReverbClient {
    private ws: WebSocket | null = null;
    private socketId: string | null = null;
    private subscriptions = new Map<string, ChannelEventHandler>();
    private presenceMembers = new Map<string, PresenceMember[]>();
    private presenceListeners = new Map<string, (members: PresenceMember[]) => void>();
    private closed = false;

    constructor(private readonly config: ReverbClientConfig) {}

    public connect(): void {
        if (!this.config.enabled || this.closed || typeof WebSocket === 'undefined') {
            return;
        }

        const wsProto = this.config.scheme === 'https' ? 'wss' : 'ws';
        const url = `${wsProto}://${this.config.host}:${this.config.port}/app/${this.config.key}?protocol=7&client=js&version=8.4.0&flash=false`;

        try {
            this.ws = new WebSocket(url);
        } catch {
            return;
        }

        this.ws.onmessage = (msg) => {
            try {
                const frame = JSON.parse(String(msg.data)) as { event?: string; channel?: string; data?: string };
                if (frame.event === 'pusher:connection_established' && typeof frame.data === 'string') {
                    const parsed = JSON.parse(frame.data) as { socket_id?: string };
                    this.socketId = parsed.socket_id ?? null;
                    for (const channelName of this.subscriptions.keys()) {
                        void this.authorizeAndSubscribe(channelName);
                    }
                    return;
                }

                if (frame.event === 'pusher:ping') {
                    this.sendFrame('pusher:pong', {});
                    return;
                }

                if (frame.channel && frame.event === 'pusher_internal:subscription_succeeded' && typeof frame.data === 'string') {
                    const payload = JSON.parse(frame.data) as { presence?: { hash?: Record<string, PresenceMember['info']> } };
                    if (payload.presence?.hash) {
                        const list = Object.entries(payload.presence.hash).map(([id, info]) => ({ id, info }));
                        this.presenceMembers.set(frame.channel, list);
                        this.presenceListeners.get(frame.channel)?.(list);
                    }
                    return;
                }

                if (frame.channel && frame.event) {
                    const handler = this.subscriptions.get(frame.channel);
                    if (handler) {
                        const decoded = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data;
                        handler(frame.event, decoded);
                    }
                }
            } catch {
                // Ignore malformed non-JSON control frames gracefully
            }
        };

        this.ws.onerror = () => {
            // Silent LAN fallback when Reverb daemon is stopped in single-process dev mode
        };
    }

    public subscribe(channelName: string, handler: ChannelEventHandler, onPresence?: (members: PresenceMember[]) => void): void {
        this.subscriptions.set(channelName, handler);
        if (onPresence) {
            this.presenceListeners.set(channelName, onPresence);
        }
        if (this.socketId) {
            void this.authorizeAndSubscribe(channelName);
        }
    }

    public disconnect(): void {
        this.closed = true;
        this.subscriptions.clear();
        this.presenceListeners.clear();
        this.ws?.close();
        this.ws = null;
    }

    private async authorizeAndSubscribe(channelName: string): Promise<void> {
        if (!this.socketId) {
            return;
        }

        const xsrfMatch = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
        const xsrf = xsrfMatch ? decodeURIComponent(xsrfMatch[1]) : '';

        try {
            const res = await fetch('/broadcasting/auth', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf,
                },
                body: JSON.stringify({
                    socket_id: this.socketId,
                    channel_name: channelName,
                }),
            });

            if (!res.ok) {
                return;
            }

            const authPayload = (await res.json()) as { auth: string; channel_data?: string };
            this.sendFrame('pusher:subscribe', {
                channel: channelName,
                auth: authPayload.auth,
                channel_data: authPayload.channel_data,
            });
        } catch {
            // Silent ignore when channel authorization or socket unavailable
        }
    }

    private sendFrame(event: string, data: Record<string, unknown>): void {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({ event, data }));
        }
    }
}
