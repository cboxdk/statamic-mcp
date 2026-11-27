<template>
    <ui-header :title="__('statamic-mcp::cp.approvals_title')" icon="earth" />

    <div class="mt-4 flex flex-col gap-4">
        <ui-description class="max-w-2xl">
            {{ __('statamic-mcp::cp.approvals_help') }}
        </ui-description>

        <ui-card v-if="pushKey && pushState !== 'on'">
            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <div class="font-semibold">{{ __('statamic-mcp::cp.notification_heading') }}</div>
                    <p class="text-gray-600 dark:text-gray-400">
                        {{ __('statamic-mcp::cp.notification_help') }}
                    </p>
                </div>
                <ui-button :text="__('statamic-mcp::cp.enable_notifications')" :disabled="pushState === 'busy' || pushState === 'blocked'" @click="enablePush" />
            </div>
            <p v-if="pushState === 'blocked'" class="px-4 pb-4 text-xs text-gray-500">{{ __('statamic-mcp::cp.notifications_blocked') }}</p>
        </ui-card>

        <ui-alert v-if="error" variant="error">
            {{ error }}
        </ui-alert>

        <ui-card v-if="items.length === 0">
            <ui-heading :text="__('statamic-mcp::cp.no_approvals')" />
            <ui-description>{{ __('statamic-mcp::cp.no_approvals_help') }}</ui-description>
        </ui-card>

        <ui-alert v-if="cbox.promote && (!cbox.relay || !cbox.id)" data-cbox-promo>
            <ui-heading :text="__('statamic-mcp::cp.approve_anywhere')" />
            <span v-if="!cbox.relay">
                {{ __('statamic-mcp::cp.relay_promotion') }}
                <a href="https://cboxrelay.com" target="_blank" rel="noopener" class="underline">Cbox Relay</a>.
            </span>
            <span v-if="!cbox.id">
                {{ __('statamic-mcp::cp.id_promotion') }}
                <a href="https://cboxid.com" target="_blank" rel="noopener" class="underline">Cbox ID</a>.
            </span>
            <span class="text-xs text-gray-500">{{ __('statamic-mcp::cp.promotion_setup') }}</span>
        </ui-alert>

        <ui-card v-for="item in items" :key="item.id" :data-approval="item.id">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ item.preview.description }}</span>
                        <ui-badge :text="item.preview.tool" size="sm" />
                        <ui-badge v-if="item.preview.site" :text="__('statamic-mcp::cp.site_name', { site: item.preview.site })" size="sm" />
                    </div>
                    <ui-description class="mt-2">
                        {{ __('statamic-mcp::cp.approval_times', { requested: formatTime(item.requested_at), expires: formatTime(item.expires_at) }) }}
                    </ui-description>
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">{{ __('statamic-mcp::cp.exact_call') }}</summary>
                        <pre class="mt-2 max-h-64 overflow-auto rounded-md bg-gray-50 p-3 font-mono text-xs dark:bg-gray-850">{{ JSON.stringify(item.preview.arguments, null, 2) }}</pre>
                    </details>
                </div>
                <div class="flex shrink-0 gap-2">
                    <ui-button :text="__('statamic-mcp::cp.reject')" variant="ghost" :disabled="!!busy" @click="decide(item, 'reject')" />
                    <ui-button :text="__('statamic-mcp::cp.approve')" variant="primary" :disabled="!!busy" @click="decide(item, 'approve')" />
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
        Statamic.$toast.success(__('statamic-mcp::cp.notifications_on'));
    } catch {
        pushState.value = null;
        error.value = __('statamic-mcp::cp.notifications_failed');
    }
}

const items = ref([...props.approvals]);
const busy = ref(null);
const error = ref(null);

function formatTime(timestamp) {
    return new Date(timestamp * 1000).toLocaleString(Statamic.$config.get('locale')?.replaceAll('_', '-'));
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
    if (busy.value) return;
    busy.value = item.id;
    error.value = null;
    try {
        if (decision === 'approve' && props.elevatedSessions) {
            // Statamic's own re-authentication: password, passkey or emailed code.
            try {
                await window.__STATAMIC__.core.requireElevatedSession();
            } catch (failure) {
                // Cancellation rejects without a reason; transport failures carry one.
                if (failure) error.value = __('statamic-mcp::cp.identity_failed');
                return;
            }
        }
        const response = await post(item, decision);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            error.value = data.message || __('statamic-mcp::cp.decision_failed');
            if (response.status === 409) {
                items.value = items.value.filter((other) => other.id !== item.id);
            }
            return;
        }
        items.value = items.value.filter((other) => other.id !== item.id);
        Statamic.$toast.success(decision === 'approve'
            ? __('statamic-mcp::cp.approved')
            : __('statamic-mcp::cp.rejected'));
    } catch {
        error.value = __('statamic-mcp::cp.decision_unknown');
    } finally {
        busy.value = null;
    }
}
</script>
