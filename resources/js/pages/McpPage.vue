<template>
    <ui-header :title="__('statamic-mcp::cp.server_title')" icon="earth">
        <template v-if="webEnabled">
            <ui-badge :text="__('statamic-mcp::cp.endpoint_active')" color="green" />
        </template>
        <template v-else>
            <ui-badge :text="__('statamic-mcp::cp.endpoint_disabled')" color="default" />
        </template>
        <template #actions>
            <ui-button :href="approvalsUrl" :text="__('statamic-mcp::cp.approvals_count', { count: `${pendingApprovals}${pendingApprovals >= 50 ? '+' : ''}` })" />
        </template>
    </ui-header>

    <div class="mt-4">
        <ui-tabs v-model="activeTab" :unmount-on-hide="false">
            <ui-tab-list>
                <ui-tab-trigger name="connect" :text="__('statamic-mcp::cp.connect')" />
                <ui-tab-trigger name="tokens" :text="__('statamic-mcp::cp.my_tokens')" />
                <ui-tab-trigger name="activity" :text="__('statamic-mcp::cp.my_activity')" />
            </ui-tab-list>

            <!-- ==================== CONNECT TAB ==================== -->
            <ui-tab-content name="connect">
                <ConnectPanel
                    :clients="clients"
                    :web-enabled="webEnabled"
                    :oauth-enabled="oauthEnabled"
                    :mcp-endpoint="mcpEndpoint"
                    @switch-tab="activeTab = $event"
                />
            </ui-tab-content>

            <!-- ==================== MY TOKENS TAB ==================== -->
            <ui-tab-content name="tokens">
                <TokenList
                    ref="tokenListRef"
                    :tokens="tokens"
                    :clients="clients"
                    :web-enabled="webEnabled"
                    :available-scopes="availableScopes"
                    :http-profile="httpProfile"
                    :scope-restrictions="scopeRestrictions"
                    :capability-offer="capabilityOffer"
                    :max-token-lifetime-days="maxTokenLifetimeDays"
                />
            </ui-tab-content>

            <ui-tab-content name="activity">
                <ActivityPanel :active="activeTab === 'activity'" />
            </ui-tab-content>
        </ui-tabs>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import ConnectPanel from '../components/ConnectPanel.vue';
import TokenList from '../components/TokenList.vue';
import ActivityPanel from '../components/ActivityPanel.vue';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    pendingApprovals: { type: Number, default: 0 },
    approvalsUrl: { type: String, required: true },
    availableScopes: { type: Array, default: () => [] },
    httpProfile: { type: String, default: 'disabled' },
    scopeRestrictions: { type: Object, default: () => ({}) },
    capabilityOffer: { type: String, default: null },
    clients: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    oauthEnabled: { type: Boolean, default: false },
    mcpEndpoint: { type: String, default: '' },
    maxTokenLifetimeDays: { type: Number, default: null },
});

const activeTab = ref('connect');
const tokenListRef = ref(null);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('create') === '1') {
        activeTab.value = 'tokens';
        // Wait a tick for the tab content to render before triggering the form
        setTimeout(() => tokenListRef.value?.triggerCreate(), 0);
    }
    if (params.get('tab')) {
        activeTab.value = params.get('tab');
    }
});

watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    if (tab === 'connect') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', tab);
    }
    history.replaceState(null, '', url.toString());
});
</script>
