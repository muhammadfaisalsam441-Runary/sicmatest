import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

function resolvePort(value, fallback) {
    if (value === undefined || value === null || value === '') {
        return fallback;
    }

    const port = Number(value);
    return Number.isInteger(port) && port > 0 ? port : fallback;
}

export function parseReverbConfig(
    doc = typeof document !== 'undefined' ? document : undefined,
    env = import.meta.env || {},
    win = typeof window !== 'undefined' ? window : undefined,
) {
    let metaConfig = null;
    const meta = doc?.querySelector?.('meta[name="reverb-config"]');
    const rawMeta = meta?.getAttribute?.('content') ?? meta?.content;

    if (typeof rawMeta === 'string' && rawMeta.trim() !== '') {
        try {
            const parsed = JSON.parse(rawMeta);
            if (parsed && typeof parsed === 'object') {
                metaConfig = parsed;
            }
        } catch {
            return null;
        }
    }

    const rawKey = metaConfig && 'key' in metaConfig ? metaConfig.key : env?.VITE_REVERB_APP_KEY;
    const key = typeof rawKey === 'string' ? rawKey.trim() : String(rawKey ?? '').trim();

    const rawChannel = metaConfig && 'channel' in metaConfig
        ? metaConfig.channel
        : (env?.VITE_SICMA_REALTIME_CHANNEL ?? 'sicma.availability');
    const channel = typeof rawChannel === 'string' ? rawChannel.trim() : String(rawChannel ?? '').trim();

    if (!key || !channel) {
        return null;
    }

    const rawHost = metaConfig && 'wsHost' in metaConfig && metaConfig.wsHost
        ? metaConfig.wsHost
        : env?.VITE_REVERB_HOST;
    const wsHost = String(rawHost ?? '').trim() || win?.location?.hostname || '127.0.0.1';

    const envPort = resolvePort(env?.VITE_REVERB_PORT, 8080);
    const wsPort = resolvePort(metaConfig?.wsPort, envPort);
    const wssPort = resolvePort(metaConfig?.wssPort ?? metaConfig?.wsPort, envPort);

    const forceTLS = typeof metaConfig?.forceTLS === 'boolean'
        ? metaConfig.forceTLS
        : String(env?.VITE_REVERB_SCHEME ?? 'http').trim().toLowerCase() === 'https';

    const rawEvent = metaConfig && 'event' in metaConfig
        ? metaConfig.event
        : (env?.VITE_SICMA_REALTIME_EVENT ?? 'availability.updated');
    const event = String(rawEvent ?? '').trim() || 'availability.updated';

    return {
        key,
        wsHost,
        wsPort,
        wssPort,
        forceTLS,
        channel,
        event,
    };
}

export function installRealtimePush(
    navigation,
    doc = typeof document !== 'undefined' ? document : undefined,
    win = typeof window !== 'undefined' ? window : undefined,
) {
    if (!doc || !win || !win.WebSocket) {
        return null;
    }

    const config = parseReverbConfig(doc, import.meta.env || {}, win);
    if (!config) {
        return null;
    }

    const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: config.key,
        wsHost: config.wsHost,
        wsPort: config.wsPort,
        wssPort: config.wssPort,
        forceTLS: config.forceTLS,
        enabledTransports: ['ws', 'wss'],
    });

    const eventName = config.event.startsWith('.') ? config.event : `.${config.event}`;

    echo.channel(config.channel).listen(eventName, (payload) => {
        if (typeof doc.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
            doc.dispatchEvent(new CustomEvent('sicma:realtime-update', { detail: payload }));
        }
        navigation?.triggerRealtimeRefresh?.();
    });

    return echo;
}
