export function registerStatusPolling(Alpine, navigation) {
    Alpine.data('statusPolling', (options) => ({
        elapsed: options.elapsed ?? 0,
        progress: options.progress ?? { stage: 'Menunggu antrean', total: 0, placed: 0 },
        timer: null,
        controller: null,
        realtimeHandler: null,
        init() {
            this.timer = setInterval(() => this.poll(), options.interval);
            this.realtimeHandler = () => {
                void this.poll();
            };
            globalThis.document?.addEventListener?.('sicma:realtime-update', this.realtimeHandler);
        },
        async poll() {
            if (this.controller || document.visibilityState === 'hidden') return;
            const controller = new AbortController();
            this.controller = controller;
            try {
                const response = await fetch(options.url, {
                    headers: { Accept: 'application/json' }, signal: controller.signal,
                });
                if (!response.ok) return;
                const data = await response.json();
                this.elapsed = data.elapsed ?? this.elapsed;
                this.progress = data.progress ?? this.progress;
                const changed = options.job
                    ? !['queued', 'running'].includes(data.status)
                    : data.lock_version !== options.version || data.status !== options.status;
                if (changed && !controller.signal.aborted) {
                    options.version = data.lock_version ?? options.version;
                    options.status = data.status ?? options.status;
                    if (navigation) await navigation.refresh();
                    else location.reload();
                }
            } catch {
                // A later poll retries without interrupting the current edit.
            } finally {
                this.controller = null;
            }
        },
        destroy() {
            clearInterval(this.timer);
            this.controller?.abort();
            if (this.realtimeHandler) {
                globalThis.document?.removeEventListener?.('sicma:realtime-update', this.realtimeHandler);
                this.realtimeHandler = null;
            }
        },
    }));
}
