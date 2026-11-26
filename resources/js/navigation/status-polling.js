export function registerStatusPolling(Alpine, navigation) {
    Alpine.data('statusPolling', (options) => ({
        elapsed: options.elapsed ?? 0,
        progress: options.progress ?? { stage: 'Menunggu antrean', total: 0, placed: 0 },
        finished: false,
        inspectUrl: null,
        draftVersionNo: null,
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
                    if (options.job) {
                        clearInterval(this.timer);
                        this.timer = null;
                        this.finished = true;
                        this.inspectUrl = data.inspect_url ?? null;
                        this.draftVersionNo = data.draft_version_no ?? null;
                        if (data.inspect_url) {
                            if (typeof navigation?.navigate === 'function') {
                                await navigation.navigate(data.inspect_url);
                            } else {
                                globalThis.location?.assign?.(data.inspect_url);
                            }
                        } else {
                            const refreshed = navigation ? await navigation.refresh() : false;
                            if (refreshed === false) {
                                globalThis.location?.reload?.();
                            }
                        }
                    } else {
                        options.version = data.lock_version ?? options.version;
                        options.status = data.status ?? options.status;
                        if (navigation) await navigation.refresh();
                        else location.reload();
                    }
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
