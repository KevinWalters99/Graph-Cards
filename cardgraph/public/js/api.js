/**
 * Card Graph — API Client
 * Handles all HTTP communication with the backend.
 */
const API = {
    csrfToken: null,

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
