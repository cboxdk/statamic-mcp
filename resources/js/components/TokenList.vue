<template>
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
            <div class="flex items-center gap-2 border-t border-green-200 bg-white px-4 py-3 dark:border-green-800/40 dark:bg-gray-800">
                <input :value="newToken" readonly class="flex-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-850" @click="$event.target.select()" />
                <ui-button :text="tokenCopied ? 'Copied!' : 'Copy'" icon="clipboard" variant="primary" @click="copyToken" />
            </div>
        </div>

        <!-- Empty state -->
        <ui-card v-if="tokens.length === 0 && !newToken">
            <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
                <div class="mb-4 flex size-14 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20">
                    <svg class="size-7 text-blue-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <h3 class="mb-1.5 text-base font-semibold">No tokens yet</h3>
                <p class="mb-6 max-w-md text-sm text-gray-500 dark:text-gray-400">Create a token to authenticate AI assistants connecting to this MCP server.</p>
                <ui-button text="Create Token" variant="primary" icon="plus" @click="openCreate" />
            </div>
        </ui-card>

        <!-- Token table -->
        <template v-if="tokens.length > 0">
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ tokens.length }} token{{ tokens.length !== 1 ? 's' : '' }}</p>
                <ui-button text="Create Token" variant="primary" icon="plus" size="sm" @click="openCreate" />
            </div>

            <ui-card>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Scopes</th>
                            <th>Last Used</th>
                            <th>Expires</th>
                            <th>Created</th>
                            <th style="width: 120px" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="token in tokens" :key="token.id">
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ token.name }}</span>
                                    <ui-badge v-if="token.is_oauth" :text="'OAuth' + (token.oauth_client_name ? ' · ' + token.oauth_client_name : '')" color="green" size="sm" />
                                    <ui-badge v-if="token.is_expired" text="Expired" color="red" />
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
                            <td class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(token.last_used_at) }}</td>
                            <td class="text-sm text-gray-500 dark:text-gray-400">{{ token.expires_at ? formatDate(token.expires_at) : 'Never' }}</td>
                            <td class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(token.created_at) }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <ui-button text="Edit" size="sm" variant="ghost" icon="pencil" @click="openEdit(token)" />
                                    <ui-button v-if="!token.is_oauth" text="Regenerate" size="sm" variant="ghost" icon="sync" @click="handleRegenerate(token)" />
                                    <ui-button text="Delete" size="sm" variant="ghost" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" @click="confirmDelete(token)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </ui-card>
        </template>
    </div>

    <!-- ==================== CREATE / EDIT TOKEN STACK ==================== -->
    <ui-stack :open="showTokenForm" :title="editingToken ? 'Edit Token' : 'Create Token'" size="half" @closed="closeTokenForm">
        <ui-stack-content>
            <div class="flex flex-col gap-5 p-4">
                <div>
                    <ui-label text="Name" />
                    <ui-description text="A label to identify this token." />
                    <ui-input v-model="form.name" placeholder="e.g. Claude Desktop, Cursor IDE" class="mt-1" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>

                <div>
                    <ui-label text="Permissions" />
                    <ui-description text="What this token can access. Use a preset or select individual scopes." />

                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            v-for="preset in scopePresets"
                            :key="preset.name"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition"
                            :class="isPresetActive(preset) ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-gray-500'"
                            @click="applyPreset(preset)"
                        >
                            {{ preset.name }}
                        </button>
                    </div>

                    <div class="mt-3 flex flex-col gap-4">
                        <template v-for="(groupScopes, groupName) in groupedScopes" :key="groupName">
                            <div class="rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                                <div class="flex items-center justify-between px-4 py-2.5">
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        {{ groupName === 'access' ? 'Access Level' : groupName.charAt(0).toUpperCase() + groupName.slice(1).replace('-', ' ') }}
                                    </span>
                                    <button
                                        class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                        @click="toggleGroup(groupName)"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        {{ isGroupFullyChecked(groupName) ? 'Uncheck All' : 'Check All' }}
                                    </button>
                                </div>
                                <div class="rounded-b-lg border-t border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-850">
                                    <label
                                        v-for="scope in groupScopes"
                                        :key="scope.value"
                                        class="flex cursor-pointer items-start gap-3 border-b border-gray-100 px-4 py-3 transition last:border-b-0 hover:bg-gray-50 dark:border-gray-700/50 dark:hover:bg-gray-800"
                                    >
                                        <input v-model="form.scopes" type="checkbox" :value="scope.value" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800" />
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ scope.label }}</div>
                                            <div v-if="scope.description" class="text-xs text-gray-500 dark:text-gray-400">{{ scope.description }}</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p v-if="form.errors.scopes" class="mt-1 text-sm text-red-600">{{ form.errors.scopes }}</p>
                </div>

                <div>
                    <ui-label text="Expiration" />
                    <ui-description :text="maxTokenLifetimeDays ? `Defaults to ${maxTokenLifetimeDays} days. Clear to create a token that never expires.` : 'Leave blank for a token that never expires.'" />
                    <div class="mt-1 flex items-center gap-2">
                        <ui-input v-model="form.expires_at" type="date" class="flex-1" />
                        <ui-button v-if="form.expires_at" text="Never expire" size="sm" variant="ghost" @click="form.expires_at = ''" />
                    </div>
                    <p v-if="form.errors.expires_at" class="mt-1 text-sm text-red-600">{{ form.errors.expires_at }}</p>
                </div>
            </div>
        </ui-stack-content>

        <ui-stack-footer>
            <ui-button-group>
                <ui-button text="Cancel" @click="closeTokenForm" />
                <ui-button :text="editingToken ? 'Save Changes' : 'Create Token'" variant="primary" :loading="submitting" @click="editingToken ? handleUpdate() : handleCreate()" />
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
        @confirm="handleDelete"
        @cancel="tokenToDelete = null"
    />
</template>

<script setup>
import { ref, computed } from 'vue';
import { useTokenApi } from '../composables/useTokenApi.js';

const { router } = __STATAMIC__.inertia;

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    availableScopes: { type: Array, default: () => [] },
    maxTokenLifetimeDays: { type: Number, default: null },
});

const {
    submitting,
    createToken,
    updateToken,
    deleteToken,
    regenerateToken,
} = useTokenApi();

const showTokenForm = ref(false);
const editingToken = ref(null);
const newToken = ref(null);
const tokenCopied = ref(false);
const tokenToDelete = ref(null);

const form = ref({ name: '', scopes: [], expires_at: '', errors: {} });

const defaultExpiryDate = computed(() => {
    if (!props.maxTokenLifetimeDays) return '';
    const d = new Date();
    d.setDate(d.getDate() + props.maxTokenLifetimeDays);
    return d.toISOString().split('T')[0];
});

// Scope presets matching documented common combinations
const scopePresets = [
    {
        name: 'Read Only',
        scopes: [
            'content:read', 'blueprints:read', 'entries:read', 'terms:read',
            'globals:read', 'structures:read', 'assets:read', 'system:read',
        ],
    },
    {
        name: 'Content Editor',
        scopes: [
            'content:read', 'content:write', 'entries:read', 'entries:write',
            'terms:read', 'terms:write', 'globals:read', 'globals:write',
            'blueprints:read', 'structures:read', 'assets:read', 'assets:write',
        ],
    },
    {
        name: 'Full Access',
        scopes: ['*'],
    },
];

function isPresetActive(preset) {
    return preset.scopes.length === form.value.scopes.length
        && preset.scopes.every(s => form.value.scopes.includes(s));
}

function applyPreset(preset) {
    form.value.scopes = isPresetActive(preset) ? [] : [...preset.scopes];
}

// Computed
const groupedScopes = computed(() => {
    const groups = {};
    for (const scope of props.availableScopes) {
        const group = scope.group || 'other';
        if (!groups[group]) groups[group] = [];
        groups[group].push(scope);
    }
    return groups;
});

// Scope label lookup
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

// Token form
function openCreate() {
    editingToken.value = null;
    form.value = { name: '', scopes: [], expires_at: defaultExpiryDate.value, errors: {} };
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

function isGroupFullyChecked(groupName) {
    const scopes = groupedScopes.value[groupName] || [];
    return scopes.length > 0 && scopes.every(s => form.value.scopes.includes(s.value));
}

function toggleGroup(groupName) {
    const scopes = groupedScopes.value[groupName] || [];
    const values = scopes.map(s => s.value);
    if (isGroupFullyChecked(groupName)) {
        form.value.scopes = form.value.scopes.filter(s => !values.includes(s));
    } else {
        const toAdd = values.filter(v => !form.value.scopes.includes(v));
        form.value.scopes = [...form.value.scopes, ...toAdd];
    }
}

async function handleCreate() {
    form.value.errors = {};
    const result = await createToken({
        name: form.value.name,
        scopes: form.value.scopes,
        expires_at: form.value.expires_at || null,
    });
    if (!result.ok) {
        form.value.errors = result.errors;
        return;
    }
    newToken.value = result.token;
    showTokenForm.value = false;
    editingToken.value = null;
    router.reload({ only: ['tokens'] });
}

async function handleUpdate() {
    if (!editingToken.value) return;
    form.value.errors = {};
    const result = await updateToken(editingToken.value.id, {
        name: form.value.name,
        scopes: form.value.scopes,
        expires_at: form.value.expires_at || null,
        clear_expiry: !form.value.expires_at && !!editingToken.value.expires_at,
    });
    if (!result.ok) {
        form.value.errors = result.errors;
        return;
    }
    showTokenForm.value = false;
    editingToken.value = null;
    router.reload({ only: ['tokens'] });
}

async function handleDelete() {
    if (!tokenToDelete.value) return;
    await deleteToken(tokenToDelete.value.id);
    tokenToDelete.value = null;
    router.reload({ only: ['tokens'] });
}

function confirmDelete(token) {
    tokenToDelete.value = token;
}

async function handleRegenerate(token) {
    if (!confirm(`Regenerate token "${token.name}"? The old token will stop working immediately.`)) return;
    const result = await regenerateToken(token.id);
    if (result.ok) {
        newToken.value = result.token;
    }
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

// Formatting
function formatDate(dateString) {
    if (!dateString) return 'Never';
    return new Date(dateString).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * Called by parent to programmatically open the create form.
 */
function triggerCreate() {
    openCreate();
}

defineExpose({ triggerCreate });
</script>
