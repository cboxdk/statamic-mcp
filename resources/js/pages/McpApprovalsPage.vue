<template>
    <ui-header title="MCP Approvals" icon="earth" />

    <div class="mt-4 flex flex-col gap-4">
        <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-400">
            An AI assistant connected as you asked to do these things. Each runs once, only if you approve it, and only if
            you are still allowed to do it yourself. Approving asks you to confirm it is you.
        </p>

        <div v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/60 dark:bg-red-950/30 dark:text-red-200">
            {{ error }}
        </div>

        <ui-card v-if="items.length === 0">
            <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
                <h3 class="mb-1.5 text-base font-semibold">Nothing waiting for you</h3>
                <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                    When an assistant asks to delete something, or another action that needs your approval, it shows up here.
                </p>
            </div>
        </ui-card>

        <ui-card v-for="item in items" :key="item.id" :data-approval="item.id">
            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ item.preview.description }}</span>
                        <ui-badge :text="item.preview.tool" size="sm" />
                        <ui-badge v-if="item.preview.site" :text="'Site: ' + item.preview.site" size="sm" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Asked {{ relative(item.requested_at) }} · expires {{ relative(item.expires_at) }}
                    </p>
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Exact call</summary>
                        <pre class="mt-2 max-h-64 overflow-auto rounded-md bg-gray-50 p-3 font-mono text-xs dark:bg-gray-850">{{ JSON.stringify(item.preview.arguments, null, 2) }}</pre>
                    </details>
                </div>
                <div class="flex shrink-0 gap-2">
                    <ui-button text="Reject" variant="ghost" :disabled="busy === item.id" @click="decide(item, 'reject')" />
                    <ui-button text="Approve" variant="primary" :disabled="busy === item.id" @click="decide(item, 'approve')" />
                </div>
            </div>
        </ui-card>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    approvals: { type: Array, default: () => [] },
    elevatedSessions: { type: Boolean, default: true },
});

const items = ref([...props.approvals]);
const busy = ref(null);
const error = ref(null);

function relative(timestamp) {
    const seconds = Math.round(timestamp - Date.now() / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const minutes = Math.round(seconds / 60);
    return Math.abs(minutes) < 1 ? format.format(seconds, 'second') : format.format(minutes, 'minute');
}

async function post(item, decision) {
    return fetch(Statamic.$config.get('cpUrl') + '/mcp/approvals/' + item.id + '/' + decision, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
        },
    });
}

async function decide(item, decision) {
    busy.value = item.id;
    error.value = null;
    try {
        if (decision === 'approve' && props.elevatedSessions) {
            // Statamic's own re-authentication: password, passkey or emailed code.
            await window.__STATAMIC__.core.requireElevatedSession();
        }
        const response = await post(item, decision);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            error.value = data.message || 'That did not work. Reload the page and try again.';
            if (response.status === 409) {
                items.value = items.value.filter((other) => other.id !== item.id);
            }
            return;
        }
        items.value = items.value.filter((other) => other.id !== item.id);
        Statamic.$toast.success(decision === 'approve'
            ? 'Approved. The assistant can now run it once.'
            : 'Rejected. Nothing will run.');
    } catch {
        // The person closed the confirmation dialog: nothing was decided.
    } finally {
        busy.value = null;
    }
}
</script>
