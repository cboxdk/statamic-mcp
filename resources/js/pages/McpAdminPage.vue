<template>
    <ui-header title="MCP Admin" icon="earth">
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
                <ui-tab-trigger name="tokens" text="All Tokens" />
                <ui-tab-trigger name="activity" text="Activity" />
                <ui-tab-trigger name="system" text="System" />
            </ui-tab-list>

            <!-- ==================== ALL TOKENS TAB ==================== -->
            <ui-tab-content name="tokens">
                <div class="mt-4 flex flex-col gap-4">
                    <!-- New token alert -->
                    <div v-if="newToken" class="overflow-hidden rounded-lg border border-green-200 bg-green-50 dark:border-green-800/60 dark:bg-green-950/30">
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <svg class="size-5 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span class="text-sm font-semibold text-green-900 dark:text-green-200">Token created — copy it now, it won't be shown again.</span>
                            </div>
                            <ui-button text="Dismiss" variant="ghost" size="sm" @click="newToken = null" />
                        </div>
                        <div class="flex items-center gap-2 border-t border-green-200 bg-white px-4 py-3 dark:border-green-800/40 dark:bg-dark-600">
                            <input :value="newToken" readonly class="flex-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm dark:border-dark-400 dark:bg-dark-700" @click="$event.target.select()" />
                            <ui-button :text="tokenCopied ? 'Copied!' : 'Copy'" icon="clipboard" variant="primary" @click="copyToken" />
                        </div>
                    </div>

                    <!-- Search filter -->
                    <div class="flex items-center justify-between">
                        <ui-input v-model="tokenSearch" placeholder="Filter by user name or email..." class="w-64" />
                        <ui-button text="Create Token" variant="primary" icon="plus" size="sm" @click="openCreate" />
                    </div>

                    <!-- Empty state -->
                    <ui-card v-if="filteredTokens.length === 0 && !newToken">
                        <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
                            <div class="mb-4 flex size-14 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20">
                                <svg class="size-7 text-blue-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </div>
                            <h3 class="mb-1.5 text-base font-semibold">{{ tokenSearch ? 'No matching tokens' : 'No tokens yet' }}</h3>
                            <p class="max-w-md text-sm text-gray-500 dark:text-dark-175">{{ tokenSearch ? 'Try a different search term.' : 'No tokens have been created in the system.' }}</p>
                        </div>
                    </ui-card>

                    <!-- Token table -->
                    <template v-if="filteredTokens.length > 0">
                        <p class="text-sm text-gray-500 dark:text-dark-175">{{ filteredTokens.length }} token{{ filteredTokens.length !== 1 ? 's' : '' }}{{ tokenSearch ? ' matching' : '' }}</p>

                        <ui-card>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>User</th>
                                        <th>Scopes</th>
                                        <th>Last Used</th>
                                        <th>Expires</th>
                                        <th>Created</th>
                                        <th style="width: 120px" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="token in filteredTokens" :key="token.id">
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium">{{ token.name }}</span>
                                                <ui-badge v-if="token.is_expired" text="Expired" color="red" />
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div class="text-sm font-medium">{{ token.user_name }}</div>
                                                <div v-if="token.user_email" class="text-xs text-gray-400 dark:text-dark-200">{{ token.user_email }}</div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="flex flex-wrap gap-1">
                                                <template v-if="token.scopes?.length">
                                                    <ui-badge v-if="token.scopes.includes('*')" text="Full Access" color="purple" size="sm" />
                                                    <template v-else>
                                                        <ui-badge v-for="scope in token.scopes.slice(0, 3)" :key="scope" :text="scopeLabel(scope)" color="blue" size="sm" />
                                                        <ui-badge v-if="token.scopes.length > 3" :text="'+' + (token.scopes.length - 3)" color="default" size="sm" />
                                                    </template>
                                                </template>
                                                <span v-else class="text-sm text-gray-400">None</span>
                                            </div>
                                        </td>
                                        <td class="text-sm text-gray-500 dark:text-dark-175">{{ formatDate(token.last_used_at) }}</td>
                                        <td class="text-sm text-gray-500 dark:text-dark-175">{{ token.expires_at ? formatDate(token.expires_at) : 'Never' }}</td>
                                        <td class="text-sm text-gray-500 dark:text-dark-175">{{ formatDate(token.created_at) }}</td>
                                        <td>
                                            <div class="flex items-center justify-end gap-1">
                                                <ui-button text="Edit" size="sm" variant="ghost" icon="pencil" @click="openEdit(token)" />
                                                <ui-button text="Regenerate" size="sm" variant="ghost" icon="sync" @click="regenerateToken(token)" />
                                                <ui-button text="Delete" size="sm" variant="ghost" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" @click="confirmDelete(token)" />
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </ui-card>
                    </template>
                </div>
            </ui-tab-content>

            <!-- ==================== ACTIVITY TAB ==================== -->
            <ui-tab-content name="activity">
                <div class="mt-4 flex flex-col gap-4">
                    <!-- Filter bar -->
                    <div class="flex items-center gap-3">
                        <select
                            v-model="auditFilter.tool"
                            class="h-[38px] rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm dark:border-dark-400 dark:bg-dark-600"
                            @change="loadAudit()"
                        >
                            <option value="">All tools</option>
                            <option v-for="tool in availableTools" :key="tool" :value="tool">{{ tool }}</option>
                        </select>
                        <select
                            v-model="auditFilter.status"
                            class="h-[38px] rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm dark:border-dark-400 dark:bg-dark-600"
                            @change="loadAudit()"
                        >
                            <option value="">All statuses</option>
                            <option value="success">Success</option>
                            <option value="error">Error</option>
                            <option value="validation_error">Validation Error</option>
                            <option value="timeout">Timeout</option>
                        </select>
                        <select
                            v-model="auditFilter.user"
                            class="h-[38px] rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm dark:border-dark-400 dark:bg-dark-600"
                            @change="filterAuditByUser()"
                        >
                            <option value="">All users</option>
                            <option v-for="u in availableUsers" :key="u.id" :value="u.email">{{ u.name || u.email }}</option>
                            <option value="cli">CLI</option>
                        </select>
                        <div class="flex-1" />
                        <ui-button text="Refresh" icon="sync" size="sm" variant="ghost" @click="loadAudit" />
                    </div>

                    <!-- Empty state -->
                    <ui-card v-if="filteredAuditEntries.length === 0 && !auditLoading">
                        <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
                            <div class="mb-4 flex size-14 items-center justify-center rounded-full bg-gray-100 dark:bg-dark-500">
                                <svg class="size-7 text-gray-400 dark:text-dark-200" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <h3 class="mb-1.5 text-base font-semibold">No activity yet</h3>
                            <p class="max-w-md text-sm text-gray-500 dark:text-dark-175">Tool calls will appear here once AI assistants start using the MCP server.</p>
                        </div>
                    </ui-card>

                    <!-- Activity table -->
                    <template v-if="filteredAuditEntries.length > 0 || auditLoading">
                        <ui-card>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tool</th>
                                        <th>Action</th>
                                        <th>Status</th>
                                        <th>User</th>
                                        <th>Duration</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(entry, i) in filteredAuditEntries" :key="i" class="cursor-pointer" @click="openAuditDetail(entry)">
                                        <td class="font-mono text-sm">{{ entry.tool || 'unknown' }}</td>
                                        <td class="text-sm">{{ entry.action || '-' }}</td>
                                        <td>
                                            <ui-badge
                                                :text="entry.status || 'unknown'"
                                                :color="statusColor(entry.status)"
                                                size="sm"
                                            />
                                        </td>
                                        <td class="text-sm text-gray-500 dark:text-dark-175">{{ entry.user || entry.token_name || 'cli' }}</td>
                                        <td class="text-sm text-gray-500 dark:text-dark-175">{{ entry.duration_ms ? Math.round(entry.duration_ms) + 'ms' : '-' }}</td>
                                        <td class="text-sm text-gray-500 dark:text-dark-175">{{ formatDateTime(entry.timestamp) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </ui-card>

                        <!-- Detail slide-in -->
                        <ui-stack v-model:open="showAuditDetail" :title="selectedAuditEntry ? (selectedAuditEntry.tool + (selectedAuditEntry.action ? '.' + selectedAuditEntry.action : '')) : 'Call Details'" size="narrow" @closed="selectedAuditEntry = null">
                            <template v-if="selectedAuditEntry">
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div><span class="text-gray-500 dark:text-dark-175">Tool:</span> <span class="font-mono">{{ selectedAuditEntry.tool }}</span></div>
                                    <div><span class="text-gray-500 dark:text-dark-175">Action:</span> {{ selectedAuditEntry.action || '-' }}</div>
                                    <div><span class="text-gray-500 dark:text-dark-175">Status:</span> <ui-badge :text="selectedAuditEntry.status" :color="statusColor(selectedAuditEntry.status)" size="sm" /></div>
                                    <div><span class="text-gray-500 dark:text-dark-175">Duration:</span> {{ selectedAuditEntry.duration_ms ? Math.round(selectedAuditEntry.duration_ms) + 'ms' : '-' }}</div>
                                    <div><span class="text-gray-500 dark:text-dark-175">User:</span> {{ selectedAuditEntry.user || '-' }}</div>
                                    <div><span class="text-gray-500 dark:text-dark-175">Token:</span> {{ selectedAuditEntry.token_name || '-' }}</div>
                                    <div><span class="text-gray-500 dark:text-dark-175">Context:</span> {{ selectedAuditEntry.context || '-' }}</div>
                                    <div><span class="text-gray-500 dark:text-dark-175">IP:</span> {{ selectedAuditEntry.ip || '-' }}</div>
                                    <div class="col-span-2"><span class="text-gray-500 dark:text-dark-175">Correlation ID:</span> <span class="font-mono text-xs">{{ selectedAuditEntry.correlation_id || '-' }}</span></div>
                                </div>
                                <div v-if="selectedAuditEntry.mutation" class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 dark:border-amber-800/40 dark:bg-amber-950/20">
                                    <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Mutation</span>
                                    <div class="mt-1.5 grid grid-cols-2 gap-2 text-sm">
                                        <div><span class="text-amber-600 dark:text-amber-400">Resource:</span> {{ selectedAuditEntry.mutation.type }}</div>
                                        <div><span class="text-amber-600 dark:text-amber-400">Operation:</span> {{ selectedAuditEntry.mutation.operation }}</div>
                                        <div v-if="selectedAuditEntry.mutation.resource_id" class="col-span-2">
                                            <span class="text-amber-600 dark:text-amber-400">ID:</span> <span class="font-mono">{{ selectedAuditEntry.mutation.resource_id }}</span>
                                        </div>
                                        <div v-if="selectedAuditEntry.mutation.changed_fields" class="col-span-2">
                                            <span class="text-amber-600 dark:text-amber-400">Changed fields:</span>
                                            <span class="ml-1">
                                                <ui-badge v-for="field in selectedAuditEntry.mutation.changed_fields" :key="field" :text="field" size="sm" class="mr-1" />
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="selectedAuditEntry.arguments" class="mt-4">
                                    <span class="text-sm font-medium text-gray-500 dark:text-dark-175">Arguments</span>
                                    <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-3 text-xs dark:bg-dark-600">{{ JSON.stringify(selectedAuditEntry.arguments, null, 2) }}</pre>
                                </div>
                                <div v-if="selectedAuditEntry.response_summary" class="mt-4">
                                    <span class="text-sm font-medium text-gray-500 dark:text-dark-175">Response</span>
                                    <p class="mt-1 text-sm">{{ selectedAuditEntry.response_summary }}</p>
                                </div>
                                <div v-if="selectedAuditEntry.error" class="mt-4">
                                    <span class="text-sm font-medium text-gray-500 dark:text-dark-175">Error</span>
                                    <pre class="mt-1 overflow-x-auto rounded bg-red-50 p-3 text-xs text-red-800 dark:bg-red-900/20 dark:text-red-300">{{ selectedAuditEntry.error.message || JSON.stringify(selectedAuditEntry.error) }}</pre>
                                </div>
                            </template>
                        </ui-stack>

                        <div v-if="auditMeta.last_page > 1" class="flex items-center justify-between">
                            <span class="text-sm text-gray-500 dark:text-dark-175">Page {{ auditMeta.current_page }} of {{ auditMeta.last_page }} ({{ auditMeta.total }} entries)</span>
                            <ui-button-group>
                                <ui-button text="Previous" size="sm" :disabled="auditMeta.current_page <= 1" @click="loadAudit(auditMeta.current_page - 1)" />
                                <ui-button text="Next" size="sm" :disabled="auditMeta.current_page >= auditMeta.last_page" @click="loadAudit(auditMeta.current_page + 1)" />
                            </ui-button-group>
                        </div>
                    </template>
                </div>
            </ui-tab-content>

            <!-- ==================== SYSTEM TAB ==================== -->
            <ui-tab-content name="system">
                <div class="mt-4 flex flex-col gap-4">
                    <div class="gap-4" style="display: grid; grid-template-columns: repeat(4, 1fr);">
                        <ui-card>
                            <div class="p-4">
                                <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-dark-200">Web Endpoint</p>
                                <div class="mt-2"><ui-badge :text="webEnabled ? 'Active' : 'Disabled'" :color="webEnabled ? 'green' : 'default'" /></div>
                            </div>
                        </ui-card>
                        <ui-card>
                            <div class="p-4">
                                <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-dark-200">MCP Tools</p>
                                <p class="mt-1 text-2xl font-bold">{{ systemStats.tool_count ?? 0 }}</p>
                            </div>
                        </ui-card>
                        <ui-card>
                            <div class="p-4">
                                <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-dark-200">Statamic</p>
                                <p class="mt-1 text-2xl font-bold">{{ systemStats.statamic_version ?? 'N/A' }}</p>
                            </div>
                        </ui-card>
                        <ui-card>
                            <div class="p-4">
                                <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-dark-200">Laravel</p>
                                <p class="mt-1 text-2xl font-bold">{{ systemStats.laravel_version ?? 'N/A' }}</p>
                            </div>
                        </ui-card>
                    </div>

                    <ui-card v-if="webEnabled">
                        <div class="p-4">
                            <p class="mb-2 text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-dark-200">Endpoint URL</p>
                            <div class="flex items-center gap-2">
                                <input :value="mcpEndpoint" readonly class="flex-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm dark:border-dark-400 dark:bg-dark-600" @click="$event.target.select()" />
                                <ui-button :text="endpointCopied ? 'Copied!' : 'Copy'" icon="clipboard" size="sm" @click="copyEndpoint" />
                            </div>
                        </div>
                    </ui-card>

                    <ui-card>
                        <div class="p-4">
                            <p class="mb-1 text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-dark-200">Rate Limiting</p>
                            <p class="text-sm">{{ systemStats.rate_limit_max ?? 60 }} requests per {{ systemStats.rate_limit_decay ?? 1 }} minute{{ (systemStats.rate_limit_decay ?? 1) !== 1 ? 's' : '' }}</p>
                        </div>
                    </ui-card>
                </div>
            </ui-tab-content>
        </ui-tabs>
    </div>

    <!-- ==================== CREATE / EDIT TOKEN STACK ==================== -->
    <ui-stack :open="showTokenForm" :title="editingToken ? 'Edit Token' : 'Create Token'" @closed="closeTokenForm">
        <ui-stack-content>
            <div class="flex flex-col gap-5 p-4">
                <div>
                    <ui-label text="Name" />
                    <ui-description text="A label to identify this token." />
                    <ui-input v-model="form.name" placeholder="e.g. Claude Desktop, Cursor IDE" class="mt-1" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <div>
                            <ui-label text="Permissions" />
                            <ui-description text="What this token can access." />
                        </div>
                        <button class="text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400" @click="toggleAllScopes">
                            {{ form.scopes.length === availableScopes.length ? 'Deselect All' : 'Select All' }}
                        </button>
                    </div>

                    <div class="max-h-72 overflow-y-auto rounded-lg border border-gray-200 dark:border-dark-400">
                        <template v-for="(groupScopes, groupName) in groupedScopes" :key="groupName">
                            <div class="border-b border-gray-100 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-gray-400 first:rounded-t-lg dark:border-dark-450 dark:text-dark-200" :class="groupName === 'access' ? 'bg-purple-50/50 dark:bg-purple-900/10' : 'bg-gray-50 dark:bg-dark-575'">
                                {{ groupName === 'access' ? 'Access Level' : groupName }}
                            </div>
                            <label
                                v-for="scope in groupScopes"
                                :key="scope.value"
                                class="flex items-center gap-3 border-b border-gray-100 px-3 py-2 transition last:border-b-0 hover:bg-gray-50 dark:border-dark-450 dark:hover:bg-dark-575"
                                :class="{ 'bg-blue-50/30 dark:bg-blue-900/5': form.scopes.includes(scope.value) }"
                            >
                                <input v-model="form.scopes" type="checkbox" :value="scope.value" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-dark-300" />
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium">{{ scope.label }}</span>
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-400 dark:bg-dark-500 dark:text-dark-250">{{ scope.value }}</span>
                                </div>
                            </label>
                        </template>
                    </div>
                    <p v-if="form.errors.scopes" class="mt-1 text-sm text-red-600">{{ form.errors.scopes }}</p>
                </div>

                <div>
                    <ui-label text="Expiration" />
                    <ui-description text="Leave blank for a token that never expires." />
                    <div class="mt-1 flex items-center gap-2">
                        <ui-input v-model="form.expires_at" type="date" class="flex-1" />
                        <ui-button v-if="form.expires_at" text="Clear" size="sm" variant="ghost" @click="form.expires_at = ''" />
                    </div>
                    <p v-if="form.errors.expires_at" class="mt-1 text-sm text-red-600">{{ form.errors.expires_at }}</p>
                </div>
            </div>
        </ui-stack-content>

        <ui-stack-footer>
            <ui-button-group>
                <ui-button text="Cancel" @click="closeTokenForm" />
                <ui-button :text="editingToken ? 'Save Changes' : 'Create Token'" variant="primary" :loading="submitting" @click="editingToken ? updateToken() : createToken()" />
            </ui-button-group>
        </ui-stack-footer>
    </ui-stack>

    <!-- ==================== DELETE CONFIRMATION ==================== -->
    <ui-confirmation-modal
        :open="!!tokenToDelete"
        title="Delete Token"
        :body-text="`Are you sure you want to delete '${tokenToDelete?.name}'? Any clients using this token will lose access immediately.`"
        button-text="Delete"
        danger
        @confirm="deleteToken"
        @cancel="tokenToDelete = null"
    />
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
const { router } = __STATAMIC__.inertia;

const props = defineProps({
    allTokens: { type: Array, default: () => [] },
    availableScopes: { type: Array, default: () => [] },
    availableUsers: { type: Array, default: () => [] },
    availableTools: { type: Array, default: () => [] },
    systemStats: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    mcpEndpoint: { type: String, default: '' },
});

const activeTab = ref('tokens');

// Token state
const showTokenForm = ref(false);
const editingToken = ref(null);
const submitting = ref(false);
const newToken = ref(null);
const tokenCopied = ref(false);
const endpointCopied = ref(false);
const tokenToDelete = ref(null);
const tokenSearch = ref('');

const form = ref({ name: '', scopes: [], expires_at: '', errors: {} });

// Activity state
const auditEntries = ref([]);
const auditLoading = ref(false);
const auditFilter = ref({ tool: '', status: '', user: '' });
const auditMeta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const selectedAuditEntry = ref(null);
const showAuditDetail = ref(false);

function openAuditDetail(entry) {
    selectedAuditEntry.value = entry;
    showAuditDetail.value = true;
}
let debounceTimer = null;

function statusColor(status) {
    const map = {
        success: 'green',
        error: 'red',
        failed: 'red',
        validation_error: 'orange',
        timeout: 'yellow',
        warning: 'yellow',
        started: 'default',
    };
    return map[status] || 'default';
}

// Computed
const filteredTokens = computed(() => {
    if (!tokenSearch.value) return props.allTokens;
    const q = tokenSearch.value.toLowerCase();
    return props.allTokens.filter(t =>
        (t.user_name && t.user_name.toLowerCase().includes(q)) ||
        (t.user_email && t.user_email.toLowerCase().includes(q)) ||
        (t.name && t.name.toLowerCase().includes(q))
    );
});

const filteredAuditEntries = computed(() => {
    if (!auditFilter.value.user) return auditEntries.value;
    const filter = auditFilter.value.user;
    if (filter === 'cli') {
        return auditEntries.value.filter(e => !e.user && e.context === 'cli');
    }
    return auditEntries.value.filter(e => e.user === filter);
});

const groupedScopes = computed(() => {
    const groups = {};
    for (const scope of props.availableScopes) {
        const group = scope.group || 'other';
        if (!groups[group]) groups[group] = [];
        groups[group].push(scope);
    }
    return groups;
});

const scopeLabelMap = computed(() => {
    const map = {};
    for (const s of props.availableScopes) {
        map[s.value] = s.label;
    }
    return map;
});

function scopeLabel(value) {
    return scopeLabelMap.value[value] || value;
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab')) {
        activeTab.value = params.get('tab');
    }
});

function cp_url(path) {
    return Statamic.$config.get('cpUrl') + '/' + path;
}

function copyEndpoint() {
    navigator.clipboard.writeText(props.mcpEndpoint).then(() => {
        endpointCopied.value = true;
        setTimeout(() => { endpointCopied.value = false; }, 2000);
    });
}

// Token form
function openCreate() {
    editingToken.value = null;
    form.value = { name: '', scopes: [], expires_at: '', errors: {} };
    showTokenForm.value = true;
}

function openEdit(token) {
    editingToken.value = token;
    form.value = {
        name: token.name,
        scopes: [...(token.scopes || [])],
        expires_at: token.expires_at ? token.expires_at.split('T')[0] : '',
        errors: {},
    };
    showTokenForm.value = true;
}

function closeTokenForm() {
    showTokenForm.value = false;
    editingToken.value = null;
}

function toggleAllScopes() {
    if (form.value.scopes.length === props.availableScopes.length) {
        form.value.scopes = [];
    } else {
        form.value.scopes = props.availableScopes.map(s => s.value);
    }
}

async function createToken() {
    submitting.value = true;
    form.value.errors = {};
    try {
        const response = await fetch(cp_url('mcp/tokens'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': Statamic.$config.get('csrfToken') },
            body: JSON.stringify({ name: form.value.name, scopes: form.value.scopes, expires_at: form.value.expires_at || null }),
        });
        const data = await response.json();
        if (!response.ok) {
            if (response.status === 422 && data.errors) {
                const errors = {};
                for (const [key, value] of Object.entries(data.errors)) errors[key] = Array.isArray(value) ? value[0] : value;
                form.value.errors = errors;
            }
            return;
        }
        newToken.value = data.token;
        showTokenForm.value = false;
        editingToken.value = null;
        activeTab.value = 'tokens';
        router.reload({ only: ['allTokens'] });
    } catch (e) {
        form.value.errors = { name: 'An error occurred. Please try again.' };
    } finally {
        submitting.value = false;
    }
}

async function updateToken() {
    if (!editingToken.value) return;
    submitting.value = true;
    form.value.errors = {};
    try {
        const body = { name: form.value.name, scopes: form.value.scopes };
        if (form.value.expires_at) body.expires_at = form.value.expires_at;
        else if (editingToken.value.expires_at) body.clear_expiry = true;

        const response = await fetch(cp_url('mcp/tokens/' + editingToken.value.id), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': Statamic.$config.get('csrfToken') },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok) {
            if (response.status === 422 && data.errors) {
                const errors = {};
                for (const [key, value] of Object.entries(data.errors)) errors[key] = Array.isArray(value) ? value[0] : value;
                form.value.errors = errors;
            } else if (data.message) form.value.errors = { name: data.message };
            return;
        }
        showTokenForm.value = false;
        editingToken.value = null;
        router.reload({ only: ['allTokens'] });
    } catch (e) {
        form.value.errors = { name: 'An error occurred. Please try again.' };
    } finally {
        submitting.value = false;
    }
}

async function deleteToken() {
    if (!tokenToDelete.value) return;
    try {
        await fetch(cp_url('mcp/tokens/' + tokenToDelete.value.id), {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': Statamic.$config.get('csrfToken') },
        });
        tokenToDelete.value = null;
        router.reload({ only: ['allTokens'] });
    } catch (e) { tokenToDelete.value = null; }
}

function confirmDelete(token) { tokenToDelete.value = token; }

async function regenerateToken(token) {
    if (!confirm(`Regenerate token "${token.name}"? The old token will stop working immediately.`)) return;
    try {
        const response = await fetch(cp_url('mcp/tokens/' + token.id + '/regenerate'), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': Statamic.$config.get('csrfToken') },
        });
        if (response.ok) {
            const result = await response.json();
            newToken.value = result.token;
        }
    } catch (e) { /* silently fail */ }
}

function copyToken() {
    if (!newToken.value) return;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(newToken.value).then(() => {
            tokenCopied.value = true;
            setTimeout(() => { tokenCopied.value = false; }, 2000);
        }).catch(() => {
            fallbackCopy(newToken.value);
        });
    } else {
        fallbackCopy(newToken.value);
    }
}

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    tokenCopied.value = true;
    setTimeout(() => { tokenCopied.value = false; }, 2000);
}

// Activity
async function loadAudit(page = 1) {
    auditLoading.value = true;
    try {
        const params = new URLSearchParams({ page: String(page), per_page: '25' });
        if (auditFilter.value.tool) params.set('tool', auditFilter.value.tool);
        if (auditFilter.value.status) params.set('status', auditFilter.value.status);
        const response = await fetch(cp_url('mcp/audit?' + params.toString()), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': Statamic.$config.get('csrfToken') },
        });
        if (response.ok) {
            const result = await response.json();
            auditEntries.value = result.data || [];
            auditMeta.value = result.meta || { current_page: 1, last_page: 1, per_page: 25, total: 0 };
        }
    } catch (e) { /* endpoint unavailable */ }
    finally { auditLoading.value = false; }
}

function filterAuditByUser() {
    // User filter is client-side via computed property — no debounce needed for select
}

watch(activeTab, (tab) => {
    if (tab === 'activity' && auditEntries.value.length === 0) loadAudit();

    const url = new URL(window.location.href);
    if (tab === 'tokens') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', tab);
    }
    history.replaceState(null, '', url.toString());
});

// Formatting
function formatDate(dateString) {
    if (!dateString) return 'Never';
    return new Date(dateString).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>
