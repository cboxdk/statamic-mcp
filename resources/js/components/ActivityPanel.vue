<template>
            <div class="mt-4 space-y-6">
                <div class="flex flex-wrap items-end gap-3">
                    <ui-field :label="__('statamic-mcp::cp.tool')" class="w-full sm:max-w-64">
                        <ui-combobox v-model="auditFilter.tool" :options="toolOptions" />
                    </ui-field>
                    <ui-field :label="__('statamic-mcp::cp.status')" class="w-full sm:max-w-48">
                        <ui-select v-model="auditFilter.status" :options="statusOptions" />
                    </ui-field>
                    <ui-field v-if="admin" :label="__('statamic-mcp::cp.user')" class="w-full sm:max-w-48">
                        <ui-combobox v-model="auditFilter.user" :options="userOptions" />
                    </ui-field>
                    <ui-button :text="__('statamic-mcp::cp.refresh')" icon="sync" :disabled="auditLoading" @click="loadAudit(auditMeta.current_page)" />
                </div>

                <ui-alert v-if="auditError" variant="error">
                    <ui-heading :text="__('statamic-mcp::cp.activity_failed_heading')" />
                    <ui-description>{{ auditError }}</ui-description>
                    <ui-button :text="__('statamic-mcp::cp.try_again')" size="sm" class="mt-3" @click="loadAudit()" />
                </ui-alert>
                <ui-card v-else-if="auditLoading" role="status" aria-live="polite">
                    <ui-description>{{ __('statamic-mcp::cp.loading_activity') }}</ui-description>
                    <ui-skeleton class="h-8" />
                    <ui-skeleton class="h-8" />
                    <ui-skeleton class="h-8" />
                </ui-card>
                <ui-card v-else-if="!auditEntries.length">
                    <ui-heading :text="hasAuditFilters ? __('statamic-mcp::cp.no_matching_activity') : __('statamic-mcp::cp.no_activity')" />
                    <ui-description>{{ hasAuditFilters ? __('statamic-mcp::cp.filter_help') : __('statamic-mcp::cp.no_activity_help') }}</ui-description>
                    <ui-button v-if="hasAuditFilters" :text="__('statamic-mcp::cp.clear_filters')" size="sm" @click="auditFilter = { tool: '', status: '', user: '' }" />
                </ui-card>
                <template v-else>
                    <ui-card>
                        <div class="overflow-x-auto">
                            <ui-table>
                                <ui-table-columns>
                                    <ui-table-column>{{ __('statamic-mcp::cp.tool_action') }}</ui-table-column>
                                    <ui-table-column>{{ __('statamic-mcp::cp.status') }}</ui-table-column>
                                    <ui-table-column>{{ __('statamic-mcp::cp.user') }}</ui-table-column>
                                    <ui-table-column>{{ __('statamic-mcp::cp.duration') }}</ui-table-column>
                                    <ui-table-column>{{ __('statamic-mcp::cp.time') }}</ui-table-column>
                                </ui-table-columns>
                                <ui-table-rows>
                                    <ui-table-row v-for="(entry, index) in auditEntries" :key="index">
                                        <ui-table-cell>
                                            <ui-button :text="entry.tool || __('statamic-mcp::cp.unknown_tool')" variant="ghost" size="sm" @click="openAuditDetail(entry)" />
                                            <ui-description>{{ entry.action || '—' }}</ui-description>
                                        </ui-table-cell>
                                        <ui-table-cell><ui-badge :text="statusLabel(entry.status)" :color="statusColor(entry.status)" size="sm" /></ui-table-cell>
                                        <ui-table-cell>{{ entry.user || entry.token_name || 'CLI' }}</ui-table-cell>
                                        <ui-table-cell class="whitespace-nowrap">{{ duration(entry.duration_ms) }}</ui-table-cell>
                                        <ui-table-cell class="whitespace-nowrap">{{ formatDateTime(entry.timestamp) }}</ui-table-cell>
                                    </ui-table-row>
                                </ui-table-rows>
                            </ui-table>
                        </div>
                    </ui-card>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <ui-description>{{ __('statamic-mcp::cp.activity_pagination', { page: auditMeta.current_page, last: auditMeta.last_page, total: auditMeta.total }) }}</ui-description>
                        <ui-button-group v-if="auditMeta.last_page > 1">
                            <ui-button :text="__('statamic-mcp::cp.previous')" size="sm" :disabled="auditMeta.current_page <= 1" @click="loadAudit(auditMeta.current_page - 1)" />
                            <ui-button :text="__('statamic-mcp::cp.next')" size="sm" :disabled="auditMeta.current_page >= auditMeta.last_page" @click="loadAudit(auditMeta.current_page + 1)" />
                        </ui-button-group>
                    </div>
                </template>
            </div>

    <ui-stack v-model:open="showAuditDetail" :title="__('statamic-mcp::cp.call_details')" size="half">
        <ui-stack-content v-if="selectedAuditEntry" class="space-y-6">
            <ui-heading :text="selectedAuditEntry.tool || __('statamic-mcp::cp.unknown_tool')" size="lg" />
            <ui-badge :text="statusLabel(selectedAuditEntry.status)" :color="statusColor(selectedAuditEntry.status)" />
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
                <ui-heading :text="__('statamic-mcp::cp.mutation')" />
                <ui-description>{{ selectedAuditEntry.mutation.type }} · {{ selectedAuditEntry.mutation.operation }}</ui-description>
                <ui-description v-if="selectedAuditEntry.mutation.resource_id">{{ selectedAuditEntry.mutation.resource_id }}</ui-description>
                <div class="flex flex-wrap gap-1 mt-2">
                    <ui-badge v-for="field in selectedAuditEntry.mutation.changed_fields" :key="field" :text="field" size="sm" />
                </div>
            </ui-alert>
            <ui-field v-if="selectedAuditEntry.arguments" :label="__('statamic-mcp::cp.arguments')">
                <pre class="overflow-auto text-xs">{{ JSON.stringify(selectedAuditEntry.arguments, null, 2) }}</pre>
            </ui-field>
            <ui-field v-if="selectedAuditEntry.response_summary" :label="__('statamic-mcp::cp.response')">
                <ui-text>{{ selectedAuditEntry.response_summary }}</ui-text>
            </ui-field>
            <ui-alert v-if="selectedAuditEntry.error" variant="error">
                <ui-heading :text="__('statamic-mcp::cp.error')" />
                <ui-description>{{ selectedAuditEntry.error.message || JSON.stringify(selectedAuditEntry.error) }}</ui-description>
            </ui-alert>
        </ui-stack-content>
    </ui-stack>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
    active: { type: Boolean, default: false },
    admin: { type: Boolean, default: false },
    availableUsers: { type: Array, default: () => [] },
    availableTools: { type: Array, default: () => [] },
});

const auditEntries = ref([]);
const auditLoading = ref(false);
const auditError = ref(null);
const auditFilter = ref({ tool: '', status: '', user: '' });
const auditMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const selectedAuditEntry = ref(null);
const showAuditDetail = ref(false);
let auditRequest;

const toolOptions = computed(() => [{ value: '', label: __('statamic-mcp::cp.all_tools') }, ...props.availableTools.map(tool => ({ value: tool, label: tool }))]);
const userOptions = computed(() => [{ value: '', label: __('statamic-mcp::cp.all_users') }, { value: 'cli', label: 'CLI' }, ...props.availableUsers.map(user => ({ value: 'user:' + user.id, label: user.name || user.email }))]);
const statusOptions = [
    { value: '', label: __('statamic-mcp::cp.all_statuses') }, { value: 'success', label: __('statamic-mcp::cp.success') },
    { value: 'error', label: __('statamic-mcp::cp.error') }, { value: 'validation_error', label: __('statamic-mcp::cp.validation_error') }, { value: 'timeout', label: __('statamic-mcp::cp.timeout') },
];
const hasAuditFilters = computed(() => Object.values(auditFilter.value).some(Boolean));
const auditDetails = computed(() => {
    const entry = selectedAuditEntry.value;
    return entry ? {
        [__('statamic-mcp::cp.action')]: entry.action,
        [__('statamic-mcp::cp.duration')]: duration(entry.duration_ms),
        [__('statamic-mcp::cp.time')]: formatDateTime(entry.timestamp),
        [__('statamic-mcp::cp.user')]: entry.user,
        [__('statamic-mcp::cp.token')]: entry.token_name,
        [__('statamic-mcp::cp.context')]: entry.context,
        IP: entry.ip,
        [__('statamic-mcp::cp.correlation_id')]: entry.correlation_id,
    } : {};
});

function openAuditDetail(entry) {
    selectedAuditEntry.value = entry;
    showAuditDetail.value = true;
}

function statusColor(status) {
    return { success: 'green', error: 'red', failed: 'red', validation_error: 'orange', timeout: 'yellow', warning: 'yellow' }[status] || 'default';
}

function statusLabel(status) {
    return ['success', 'error', 'failed', 'validation_error', 'timeout', 'warning'].includes(status)
        ? __(`statamic-mcp::cp.statuses.${status}`) : (status || __('statamic-mcp::cp.unknown'));
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
        const response = await fetch(Statamic.$config.get('cpUrl') + (props.admin ? '/mcp/audit?' : '/mcp/activity?') + params, {
            signal: request.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || __('statamic-mcp::cp.activity_failed'));
        auditEntries.value = result.data;
        auditMeta.value = result.meta;
    } catch (error) {
        if (!request.signal.aborted) auditError.value = error.message || __('statamic-mcp::cp.check_connection');
    } finally {
        if (!request.signal.aborted) auditLoading.value = false;
    }
}

watch(auditFilter, () => loadAudit(), { deep: true });
watch(() => props.active, active => { if (active) loadAudit(); }, { immediate: true });
onBeforeUnmount(() => auditRequest?.abort());

function duration(value) {
    return value == null ? '—' : `${Math.round(value)} ms`;
}

function formatDateTime(value) {
    return value ? new Date(value).toLocaleString(Statamic.$config.get('locale')?.replaceAll('_', '-')) : '—';
}
</script>
