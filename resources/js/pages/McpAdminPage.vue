<template>
    <ui-header title="MCP Admin" icon="earth">
        <ui-badge :text="webEnabled ? 'Endpoint Active' : 'Endpoint Disabled'" :color="webEnabled ? 'green' : 'default'" />
    </ui-header>

    <ui-tabs v-model="activeTab" :unmount-on-hide="false" class="mt-4">
        <ui-tab-list>
            <ui-tab-trigger name="tokens" text="All Tokens" />
            <ui-tab-trigger name="activity" text="Activity" />
            <ui-tab-trigger name="system" text="System" />
        </ui-tab-list>
        <ui-tab-content name="tokens">
            <TokenList admin :tokens="allTokens" :available-scopes="availableScopes" :capability-offer="capabilityOffer" :max-token-lifetime-days="maxTokenLifetimeDays" />
        </ui-tab-content>
        <ui-tab-content name="activity">
            <div class="mt-4 space-y-6">
                <div class="flex flex-wrap items-end gap-3">
                    <ui-field label="Tool" class="w-full sm:max-w-64">
                        <ui-combobox v-model="auditFilter.tool" :options="toolOptions" />
                    </ui-field>
                    <ui-field label="Status" class="w-full sm:max-w-48">
                        <ui-select v-model="auditFilter.status" :options="statusOptions" />
                    </ui-field>
                    <ui-field label="User" class="w-full sm:max-w-48">
                        <ui-combobox v-model="auditFilter.user" :options="userOptions" />
                    </ui-field>
                    <ui-button text="Refresh" icon="sync" :disabled="auditLoading" @click="loadAudit(auditMeta.current_page)" />
                </div>

                <ui-alert v-if="auditError" variant="error">
                    <ui-heading text="Activity could not be loaded" />
                    <ui-description>{{ auditError }}</ui-description>
                    <ui-button text="Try again" size="sm" class="mt-3" @click="loadAudit()" />
                </ui-alert>
                <ui-card v-else-if="auditLoading" role="status" aria-live="polite">
                    <ui-description text="Loading activity…" />
                    <ui-skeleton class="h-8" />
                    <ui-skeleton class="h-8" />
                    <ui-skeleton class="h-8" />
                </ui-card>
                <ui-card v-else-if="!auditEntries.length">
                    <ui-heading :text="hasAuditFilters ? 'No matching activity' : 'No activity yet'" />
                    <ui-description :text="hasAuditFilters ? 'Try another filter or clear the filters to see all calls.' : 'Calls from connected assistants will appear here, including refused and failed calls.'" />
                    <ui-button v-if="hasAuditFilters" text="Clear filters" size="sm" @click="auditFilter = { tool: '', status: '', user: '' }" />
                </ui-card>
                <template v-else>
                    <ui-card>
                        <div class="overflow-x-auto">
                            <ui-table>
                                <ui-table-columns>
                                    <ui-table-column>Tool / action</ui-table-column>
                                    <ui-table-column>Status</ui-table-column>
                                    <ui-table-column>User</ui-table-column>
                                    <ui-table-column>Duration</ui-table-column>
                                    <ui-table-column>Time</ui-table-column>
                                </ui-table-columns>
                                <ui-table-rows>
                                    <ui-table-row v-for="(entry, index) in auditEntries" :key="index">
                                        <ui-table-cell>
                                            <ui-button :text="entry.tool || 'Unknown tool'" variant="ghost" size="sm" @click="openAuditDetail(entry)" />
                                            <ui-description>{{ entry.action || '—' }}</ui-description>
                                        </ui-table-cell>
                                        <ui-table-cell><ui-badge :text="entry.status || 'unknown'" :color="statusColor(entry.status)" size="sm" /></ui-table-cell>
                                        <ui-table-cell>{{ entry.user || entry.token_name || 'CLI' }}</ui-table-cell>
                                        <ui-table-cell class="whitespace-nowrap">{{ duration(entry.duration_ms) }}</ui-table-cell>
                                        <ui-table-cell class="whitespace-nowrap">{{ formatDateTime(entry.timestamp) }}</ui-table-cell>
                                    </ui-table-row>
                                </ui-table-rows>
                            </ui-table>
                        </div>
                    </ui-card>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <ui-description :text="`Page ${auditMeta.current_page} of ${auditMeta.last_page} · ${auditMeta.total} calls`" />
                        <ui-button-group v-if="auditMeta.last_page > 1">
                            <ui-button text="Previous" size="sm" :disabled="auditMeta.current_page <= 1" @click="loadAudit(auditMeta.current_page - 1)" />
                            <ui-button text="Next" size="sm" :disabled="auditMeta.current_page >= auditMeta.last_page" @click="loadAudit(auditMeta.current_page + 1)" />
                        </ui-button-group>
                    </div>
                </template>
            </div>
        </ui-tab-content>
        <ui-tab-content name="system">
            <div class="mt-4 space-y-6">
                <ui-card-panel heading="Connection">
                    <ui-field v-if="webEnabled" label="MCP endpoint" instructions="Use this address in clients that support streamable HTTP.">
                        <ui-input :model-value="mcpEndpoint" read-only copyable class="font-mono" />
                    </ui-field>
                    <ui-alert v-else variant="warning" text="The web endpoint is disabled. Local stdio connections are still available." />
                </ui-card-panel>
                <ui-card-panel heading="Installation">
                    <ui-table>
                        <ui-table-rows>
                            <ui-table-row><ui-table-cell>Statamic</ui-table-cell><ui-table-cell>{{ systemStats.statamic_version ?? 'Unavailable' }}</ui-table-cell></ui-table-row>
                            <ui-table-row><ui-table-cell>Laravel</ui-table-cell><ui-table-cell>{{ systemStats.laravel_version ?? 'Unavailable' }}</ui-table-cell></ui-table-row>
                            <ui-table-row><ui-table-cell>MCP tools</ui-table-cell><ui-table-cell>{{ systemStats.tool_count ?? 'Unavailable' }}</ui-table-cell></ui-table-row>
                            <ui-table-row><ui-table-cell>Rate limit</ui-table-cell><ui-table-cell>{{ systemStats.rate_limit_max ?? 60 }} requests per minute</ui-table-cell></ui-table-row>
                        </ui-table-rows>
                    </ui-table>
                </ui-card-panel>
            </div>
        </ui-tab-content>
    </ui-tabs>

    <ui-stack v-model:open="showAuditDetail" title="Call details" size="half">
        <ui-stack-content v-if="selectedAuditEntry" class="space-y-6">
            <ui-heading :text="selectedAuditEntry.tool || 'Unknown tool'" size="lg" />
            <ui-badge :text="selectedAuditEntry.status || 'unknown'" :color="statusColor(selectedAuditEntry.status)" />
            <ui-card>
                <ui-table>
                    <ui-table-rows>
                        <ui-table-row v-for="(value, label) in auditDetails" :key="label">
                            <ui-table-cell>{{ label }}</ui-table-cell><ui-table-cell class="break-all">{{ value ?? '—' }}</ui-table-cell>
                        </ui-table-row>
                    </ui-table-rows>
                </ui-table>
            </ui-card>
            <ui-alert v-if="selectedAuditEntry.mutation" variant="warning">
                <ui-heading text="Mutation" />
                <ui-description>{{ selectedAuditEntry.mutation.type }} · {{ selectedAuditEntry.mutation.operation }}</ui-description>
                <ui-description v-if="selectedAuditEntry.mutation.resource_id">{{ selectedAuditEntry.mutation.resource_id }}</ui-description>
                <div class="flex flex-wrap gap-1 mt-2">
                    <ui-badge v-for="field in selectedAuditEntry.mutation.changed_fields" :key="field" :text="field" size="sm" />
                </div>
            </ui-alert>
            <ui-field v-if="selectedAuditEntry.arguments" label="Arguments">
                <pre class="overflow-auto text-xs">{{ JSON.stringify(selectedAuditEntry.arguments, null, 2) }}</pre>
            </ui-field>
            <ui-field v-if="selectedAuditEntry.response_summary" label="Response">
                <ui-text>{{ selectedAuditEntry.response_summary }}</ui-text>
            </ui-field>
            <ui-alert v-if="selectedAuditEntry.error" variant="error">
                <ui-heading text="Error" />
                <ui-description>{{ selectedAuditEntry.error.message || JSON.stringify(selectedAuditEntry.error) }}</ui-description>
            </ui-alert>
        </ui-stack-content>
    </ui-stack>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import TokenList from '../components/TokenList.vue';

const props = defineProps({
    allTokens: { type: Array, default: () => [] },
    availableScopes: { type: Array, default: () => [] },
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
const auditEntries = ref([]);
const auditLoading = ref(false);
const auditError = ref(null);
const auditFilter = ref({ tool: '', status: '', user: '' });
const auditMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const selectedAuditEntry = ref(null);
const showAuditDetail = ref(false);
let auditRequest;

const toolOptions = computed(() => [{ value: '', label: 'All tools' }, ...props.availableTools.map(tool => ({ value: tool, label: tool }))]);
const userOptions = computed(() => [{ value: '', label: 'All users' }, { value: 'cli', label: 'CLI' }, ...props.availableUsers.map(user => ({ value: 'user:' + user.id, label: user.name || user.email }))]);
const statusOptions = [
    { value: '', label: 'All statuses' }, { value: 'success', label: 'Success' },
    { value: 'error', label: 'Error' }, { value: 'validation_error', label: 'Validation error' }, { value: 'timeout', label: 'Timeout' },
];
const hasAuditFilters = computed(() => Object.values(auditFilter.value).some(Boolean));
const auditDetails = computed(() => {
    const entry = selectedAuditEntry.value;
    return entry ? { Action: entry.action, Duration: duration(entry.duration_ms), Time: formatDateTime(entry.timestamp), User: entry.user, Token: entry.token_name, Context: entry.context, IP: entry.ip, 'Correlation ID': entry.correlation_id } : {};
});

function openAuditDetail(entry) {
    selectedAuditEntry.value = entry;
    showAuditDetail.value = true;
}

function statusColor(status) {
    return { success: 'green', error: 'red', failed: 'red', validation_error: 'orange', timeout: 'yellow', warning: 'yellow' }[status] || 'default';
}

async function loadAudit(page = 1) {
    auditRequest?.abort();
    const request = new AbortController();
    auditRequest = request;
    auditLoading.value = true;
    auditError.value = null;
    try {
        const params = new URLSearchParams({ page: String(page), per_page: '25' });
        if (auditFilter.value.tool) params.set('tool', auditFilter.value.tool);
        if (auditFilter.value.status) params.set('status', auditFilter.value.status);
        if (auditFilter.value.user) params.set('actor', auditFilter.value.user);
        const response = await fetch(Statamic.$config.get('cpUrl') + '/mcp/audit?' + params, {
            signal: request.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'The activity log could not be loaded.');
        auditEntries.value = result.data;
        auditMeta.value = result.meta;
    } catch (error) {
        if (!request.signal.aborted) auditError.value = error.message || 'Check your connection and try again.';
    } finally {
        if (!request.signal.aborted) auditLoading.value = false;
    }
}

watch(auditFilter, () => loadAudit(), { deep: true });
watch(activeTab, tab => {
    if (tab === 'activity') loadAudit();
    const url = new URL(window.location.href);
    if (tab === 'tokens') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
}, { immediate: true });
onBeforeUnmount(() => auditRequest?.abort());

function duration(value) {
    return value == null ? '—' : `${Math.round(value)} ms`;
}

function formatDateTime(value) {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>
