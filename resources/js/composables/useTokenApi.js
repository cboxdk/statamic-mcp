import { ref } from 'vue';

function cp_url(path) {
    return Statamic.$config.get('cpUrl') + '/' + path;
}

function headers(extra = {}) {
    return {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
        ...extra,
    };
}

function jsonHeaders() {
    return headers({ 'Content-Type': 'application/json' });
}

/**
 * Parse Laravel validation errors from a 422 response into a flat object.
 */
function parseValidationErrors(data) {
    const errors = {};
    if (data.errors) {
        for (const [key, value] of Object.entries(data.errors)) {
            errors[key] = Array.isArray(value) ? value[0] : value;
        }
    }
    return errors;
}

/**
 * The server's message for a failed request, or the fallback when it sent none.
 */
async function errorMessage(response, fallback) {
    try {
        const data = await response.json();
        return data.message || fallback;
    } catch {
        return fallback;
    }
}

export function useTokenApi() {
    const submitting = ref(false);

    async function createToken({ name, scopes, expires_at, capability_offer }) {
        submitting.value = true;
        try {
            const response = await fetch(cp_url('mcp/tokens'), {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({ name, scopes, expires_at: expires_at || null, capability_offer }),
            });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422 && data.errors) {
                    return { ok: false, errors: parseValidationErrors(data) };
                }
                return { ok: false, errors: { name: data.message || __('statamic-mcp::cp.generic_error') } };
            }
            return { ok: true, token: data.token };
        } catch {
            return { ok: false, errors: { name: __('statamic-mcp::cp.generic_retry') } };
        } finally {
            submitting.value = false;
        }
    }

    async function updateToken(tokenId, { name, scopes, expires_at, clear_expiry }) {
        submitting.value = true;
        try {
            const body = { name, scopes };
            if (expires_at) body.expires_at = expires_at;
            else if (clear_expiry) body.clear_expiry = true;

            const response = await fetch(cp_url('mcp/tokens/' + tokenId), {
                method: 'PUT',
                headers: jsonHeaders(),
                body: JSON.stringify(body),
            });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422 && data.errors) {
                    return { ok: false, errors: parseValidationErrors(data) };
                }
                return { ok: false, errors: { name: data.message || __('statamic-mcp::cp.generic_error') } };
            }
            return { ok: true };
        } catch {
            return { ok: false, errors: { name: __('statamic-mcp::cp.generic_retry') } };
        } finally {
            submitting.value = false;
        }
    }

    async function deleteToken(tokenId) {
        try {
            const response = await fetch(cp_url('mcp/tokens/' + tokenId), {
                method: 'DELETE',
                headers: headers(),
            });
            if (response.ok) return { ok: true };
            return { ok: false, message: await errorMessage(response, __('statamic-mcp::cp.delete_failed')) };
        } catch {
            return { ok: false, message: __('statamic-mcp::cp.delete_connection_failed') };
        }
    }

    async function regenerateToken(tokenId) {
        try {
            const response = await fetch(cp_url('mcp/tokens/' + tokenId + '/regenerate'), {
                method: 'POST',
                headers: jsonHeaders(),
            });
            if (response.ok) {
                const result = await response.json();
                return { ok: true, token: result.token };
            }
            return { ok: false, message: await errorMessage(response, __('statamic-mcp::cp.regenerate_failed')) };
        } catch {
            return { ok: false, message: __('statamic-mcp::cp.regenerate_connection_failed') };
        }
    }

    async function fetchClientConfig(clientId, mode = 'bearer') {
        try {
            const response = await fetch(cp_url('mcp/config/' + clientId) + '?' + new URLSearchParams({ mode }), {
                headers: headers(),
            });
            if (response.ok) {
                return { ok: true, data: await response.json() };
            }
            return { ok: false, message: await errorMessage(response, __('statamic-mcp::cp.config_failed')) };
        } catch {
            return { ok: false, message: __('statamic-mcp::cp.config_connection_failed') };
        }
    }

    return {
        submitting,
        createToken,
        updateToken,
        deleteToken,
        regenerateToken,
        fetchClientConfig,
    };
}
