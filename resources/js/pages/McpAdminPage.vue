<template>
    <ui-header :title="__('statamic-mcp::cp.admin_title')" icon="earth">
        <ui-badge :text="webEnabled ? __('statamic-mcp::cp.endpoint_active') : __('statamic-mcp::cp.endpoint_disabled')" :color="webEnabled ? 'green' : 'default'" />
    </ui-header>
    <ui-tabs v-model="activeTab" :unmount-on-hide="false" class="mt-4">
        <ui-tab-list>
            <ui-tab-trigger name="tokens" :text="__('statamic-mcp::cp.all_tokens')" />
            <ui-tab-trigger name="activity" :text="__('statamic-mcp::cp.activity')" />
            <ui-tab-trigger name="system" :text="__('statamic-mcp::cp.system')" />
        </ui-tab-list>
        <ui-tab-content name="tokens">
            <TokenList admin :tokens="allTokens" :clients="clients" :web-enabled="webEnabled" :available-scopes="availableScopes" :http-profile="httpProfile" :scope-restrictions="scopeRestrictions" :capability-offer="capabilityOffer" :max-token-lifetime-days="maxTokenLifetimeDays" />
        </ui-tab-content>
        <ui-tab-content name="activity">
            <ActivityPanel admin :active="activeTab === 'activity'" :available-users="availableUsers" :available-tools="availableTools" />
        </ui-tab-content>
        <ui-tab-content name="system">
            <div class="mt-4 space-y-6">
                <ui-card-panel :heading="__('statamic-mcp::cp.connection')">
                    <ui-field v-if="webEnabled" :label="__('statamic-mcp::cp.endpoint')" :instructions="__('statamic-mcp::cp.endpoint_http_help')">
                        <ui-input :model-value="mcpEndpoint" read-only copyable class="font-mono" />
                    </ui-field>
                    <ui-alert v-else variant="warning" :text="__('statamic-mcp::cp.endpoint_disabled_help')" />
                </ui-card-panel>
                <ui-card-panel :heading="__('statamic-mcp::cp.installation')">
                    <ui-table>
                        <ui-table-rows>
                            <ui-table-row><ui-table-cell>Statamic</ui-table-cell><ui-table-cell>{{ systemStats.statamic_version ?? __('statamic-mcp::cp.unavailable') }}</ui-table-cell></ui-table-row>
                            <ui-table-row><ui-table-cell>Laravel</ui-table-cell><ui-table-cell>{{ systemStats.laravel_version ?? __('statamic-mcp::cp.unavailable') }}</ui-table-cell></ui-table-row>
                            <ui-table-row><ui-table-cell>{{ __('statamic-mcp::cp.mcp_tools') }}</ui-table-cell><ui-table-cell>{{ systemStats.tool_count ?? __('statamic-mcp::cp.unavailable') }}</ui-table-cell></ui-table-row>
                            <ui-table-row><ui-table-cell>{{ __('statamic-mcp::cp.rate_limit') }}</ui-table-cell><ui-table-cell>{{ __('statamic-mcp::cp.requests_per_minute', { count: systemStats.rate_limit_max ?? 60 }) }}</ui-table-cell></ui-table-row>
                        </ui-table-rows>
                    </ui-table>
                </ui-card-panel>
            </div>
        </ui-tab-content>
    </ui-tabs>
</template>

<script setup>
import { ref, watch } from 'vue';
import TokenList from '../components/TokenList.vue';
import ActivityPanel from '../components/ActivityPanel.vue';
defineProps({
    allTokens: { type: Array, default: () => [] },
    clients: { type: Object, default: () => ({}) },
    availableScopes: { type: Array, default: () => [] },
    httpProfile: { type: String, default: 'disabled' },
    scopeRestrictions: { type: Object, default: () => ({}) },
    capabilityOffer: { type: String, default: null },
    availableUsers: { type: Array, default: () => [] },
    availableTools: { type: Array, default: () => [] },
    systemStats: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    mcpEndpoint: { type: String, default: '' },
    maxTokenLifetimeDays: { type: Number, default: null },
});
const initialTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(['activity', 'system'].includes(initialTab) ? initialTab : 'tokens');
watch(activeTab, tab => {
    const url = new URL(window.location.href);
    if (tab === 'tokens') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
});
</script>
