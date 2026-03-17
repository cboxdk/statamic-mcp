<template>
    <ui-header title="MCP Server" icon="earth">
        <template v-if="webEnabled">
            <ui-badge text="Endpoint Active" color="green" />
        </template>
        <template v-else>
            <ui-badge text="Endpoint Disabled" color="default" />
        </template>
    </ui-header>

    <div class="mt-4">
        <ui-tabs v-model="activeTab" :unmount-on-hide="false">
            <ui-tab-list>
                <ui-tab-trigger name="connect" text="Connect" />
                <ui-tab-trigger name="tokens" text="My Tokens" />
            </ui-tab-list>

            <!-- ==================== CONNECT TAB ==================== -->
            <ui-tab-content name="connect">
                <ConnectPanel
                    :clients="clients"
                    :web-enabled="webEnabled"
                    :mcp-endpoint="mcpEndpoint"
                    @switch-tab="activeTab = $event"
                />
            </ui-tab-content>

            <!-- ==================== MY TOKENS TAB ==================== -->
            <ui-tab-content name="tokens">
                <TokenList
                    ref="tokenListRef"
                    :tokens="tokens"
                    :available-scopes="availableScopes"
                    :max-token-lifetime-days="maxTokenLifetimeDays"
                />
            </ui-tab-content>

        </ui-tabs>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import ConnectPanel from '../components/ConnectPanel.vue';
import TokenList from '../components/TokenList.vue';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    availableScopes: { type: Array, default: () => [] },
    clients: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
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
