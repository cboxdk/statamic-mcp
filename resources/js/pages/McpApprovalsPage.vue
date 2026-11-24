<template>
    <ui-header title="MCP Approvals" icon="earth" />

    <div class="mt-4 flex flex-col gap-4">
        <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-400">
            An AI assistant connected as you asked to do these things. Each runs once, only if you approve it, and only if
            you are still allowed to do it yourself. Approving asks you to confirm it is you.
        </p>

        <ui-card v-if="pushKey && pushState !== 'on'">
            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <div class="font-semibold">Get a notification when an assistant asks</div>
                    <p class="text-gray-600 dark:text-gray-400">
                        Turn on notifications in this browser. Sent through Cbox Relay; a notification only opens this page.
                    </p>
                </div>
                <ui-button text="Turn on notifications" :disabled="pushState === 'busy' || pushState === 'blocked'" @click="enablePush" />
            </div>
            <p v-if="pushState === 'blocked'" class="px-4 pb-4 text-xs text-gray-500">Notifications are blocked for this site in your browser settings.</p>
        </ui-card>

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

        <div v-if="cbox.promote && (!cbox.relay || !cbox.id)" class="flex flex-col gap-1 rounded-lg border border-gray-200 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400" data-cbox-promo>
            <span class="font-medium text-gray-800 dark:text-gray-200">Approve from anywhere</span>
            <span v-if="!cbox.relay">
                Get approval requests by mail, web push and webhooks with
                <a href="https://cboxrelay.com" target="_blank" rel="noopener" class="underline">Cbox Relay</a>.
            </span>
            <span v-if="!cbox.id">
                Approve on your phone with
                <a href="https://cboxid.com" target="_blank" rel="noopener" class="underline">Cbox ID</a>.
            </span>
            <span class="text-xs text-gray-500">Both are built into Statamic MCP: install the client and add its keys.</span>
        </div>

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
    pushKey: { type: String, default: null },
    cbox: { type: Object, default: () => ({ promote: false, relay: false, id: false }) },
});

const pushState = ref(null);
if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
    pushState.value = 'blocked';
}

function urlBase64ToUint8Array(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}

async function enablePush() {
    const cpUrl = Statamic.$config.get('cpUrl');
    pushState.value = 'busy';
    error.value = null;
    try {
        if (Notification.permission !== 'granted' && (await Notification.requestPermission()) !== 'granted') {
            pushState.value = 'blocked';
            return;
        }
        const registration = await navigator.serviceWorker.register(cpUrl + '/mcp/approvals/sw.js', { scope: cpUrl + '/mcp/approvals/' });
        await navigator.serviceWorker.ready;
        const subscription = (await registration.pushManager.getSubscription())
            || (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(props.pushKey) }));
        const response = await fetch(cpUrl + '/mcp/approvals/push', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
            },
            body: JSON.stringify(subscription.toJSON()),
        });
        if (!response.ok) {
            throw new Error();
        }
        pushState.value = 'on';
        Statamic.$toast.success('Notifications are on in this browser.');
    } catch {
        pushState.value = null;
        error.value = 'Could not turn on notifications in this browser.';
    }
}

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
