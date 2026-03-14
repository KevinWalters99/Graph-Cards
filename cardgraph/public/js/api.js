/**
 * Card Graph — API Client
 * Handles all HTTP communication with the backend.
 */
const API = {
    csrfToken: null,
    _cache: {},
    _inflight: {},

    /**
     * Cached GET with TTL (default 30s). Returns cached data if fresh.
     * Deduplicates in-flight requests to the same URL.
     */
    async cachedGet(url, params, ttl = 30000) {
        const cacheKey = url + '|' + JSON.stringify(params || {});
        const cached = this._cache[cacheKey];
        if (cached && Date.now() - cached.time < ttl) {
            return cached.data;
        }
        // Deduplicate: if same request is already in-flight, return that promise
        if (this._inflight[cacheKey]) {
            return this._inflight[cacheKey];
        }
        const promise = this.get(url, params).then(data => {
            this._cache[cacheKey] = { data, time: Date.now() };
            delete this._inflight[cacheKey];
            return data;
        }).catch(err => {
            delete this._inflight[cacheKey];
            throw err;
        });
        this._inflight[cacheKey] = promise;
        return promise;
    },

    /**
     * Clear cached responses. Pass a URL prefix to clear specific caches,
     * or call with no args to clear everything.
     */
    clearCache(urlPrefix) {
        if (!urlPrefix) { this._cache = {}; return; }
        for (const key in this._cache) {
            if (key.startsWith(urlPrefix)) delete this._cache[key];
        }
    },

    async request(method, url, data = null) {
        const opts = {
            method,
            headers: { 'X-CSRF-Token': this.csrfToken || '' },
            credentials: 'same-origin',
        };

        if (data && method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data);
        }

        // Append query params for GET requests
        if (data && method === 'GET') {
            const params = new URLSearchParams();
            for (const [key, val] of Object.entries(data)) {
                if (val !== null && val !== undefined && val !== '') {
                    params.set(key, val);
                }
            }
            const qs = params.toString();
            if (qs) url += (url.includes('?') ? '&' : '?') + qs;
        }

        const response = await fetch(url, opts);

        if (response.status === 401) {
            window.location.href = '/login.html';
            return null;
        }

        const json = await response.json();

        if (!response.ok) {
            throw new Error(json.error || 'Request failed');
        }

        return json;
    },

    get(url, params)   { return this.request('GET', url, params); },
    post(url, data)    { return this.request('POST', url, data); },
    put(url, data)     { return this.request('PUT', url, data); },
    del(url)           { return this.request('DELETE', url); },

    async upload(url, formData) {
        const opts = {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.csrfToken || '' },
            credentials: 'same-origin',
            body: formData,
        };

        const response = await fetch(url, opts);
        const json = await response.json();

        if (!response.ok) {
            throw new Error(json.error || 'Upload failed');
        }

        return json;
    }
};
