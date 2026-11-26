<template>
    <div class="mt-4 space-y-6">
        <ui-alert v-if="newToken" variant="success">
            <ui-heading text="Copy your token now" />
            <ui-description text="This is the only time the secret is shown. Store it somewhere safe before dismissing it." />
            <ui-field label="New token">
                <ui-input :model-value="newToken" read-only copyable class="font-mono" />
            </ui-field>
            <ui-button text="Dismiss" variant="ghost" size="sm" class="mt-3" @click="newToken = null" />
        </ui-alert>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <ui-field label="Search tokens" class="w-full sm:max-w-64">
                <ui-input v-model="search" :placeholder="admin ? 'Name, user or email' : 'Token name'" icon="magnifying-glass" clearable />
            </ui-field>
            <ui-button text="Create Token" variant="primary" icon="plus" @click="openCreate" />
        </div>

        <ui-card v-if="!filteredTokens.length">
            <ui-heading :text="search ? 'No matching tokens' : 'No tokens yet'" />
            <ui-description :text="search ? 'Try another name or clear your search.' : 'Create a token for a client that uses Bearer authentication. OAuth connections appear here after you approve access.'" />
            <ui-button v-if="search" text="Clear search" size="sm" @click="search = ''" />
        </ui-card>

        <ui-card v-else>
            <div class="overflow-x-auto">
                <ui-table>
                    <ui-table-columns>
                        <ui-table-column>Name</ui-table-column>
                        <ui-table-column v-if="admin">User</ui-table-column>
                        <ui-table-column>Permissions</ui-table-column>
                        <ui-table-column>Last used</ui-table-column>
                        <ui-table-column>Expires</ui-table-column>
                        <ui-table-column><span class="sr-only">Actions</span></ui-table-column>
                    </ui-table-columns>
                    <ui-table-rows>
                        <ui-table-row v-for="token in filteredTokens" :key="token.id">
                            <ui-table-cell>
                                <ui-button :text="token.name" variant="ghost" size="sm" @click="openEdit(token)" />
                                <div class="flex flex-wrap gap-1">
                                    <ui-badge v-if="token.is_oauth" :text="token.oauth_client_name || 'OAuth'" size="sm" />
                                    <ui-badge v-if="token.is_expired" text="Expired" color="red" size="sm" />
                                </div>
                            </ui-table-cell>
                            <ui-table-cell v-if="admin">
                                <ui-text :text="token.user_name || 'Unknown user'" />
                                <ui-description>{{ token.user_email }}</ui-description>
                            </ui-table-cell>
                            <ui-table-cell>
                                <ui-badge v-if="token.scopes?.includes('*')" text="Legacy access — approve again" color="red" size="sm" />
                                <ui-badge v-else :text="`${token.scopes?.length || 0} ${token.scopes?.length === 1 ? 'scope' : 'scopes'}`" size="sm" />
                            </ui-table-cell>
                            <ui-table-cell class="whitespace-nowrap">{{ formatDate(token.last_used_at) }}</ui-table-cell>
                            <ui-table-cell class="whitespace-nowrap">{{ formatDate(token.expires_at) }}</ui-table-cell>
                            <ui-table-cell>
                                <ui-dropdown align="end">
                                    <template #trigger>
                                        <ui-button icon="dots" variant="ghost" size="sm" :aria-label="`Actions for ${token.name}`" />
                                    </template>
                                    <ui-dropdown-menu>
                                        <ui-dropdown-item text="Edit" icon="pencil" @click="openEdit(token)" />
                                        <ui-dropdown-item v-if="!token.is_oauth && (!admin || token.is_own)" text="Regenerate" icon="sync" @click="pendingAction = { token, action: 'regenerate' }" />
                                        <ui-dropdown-item text="Delete" icon="trash" variant="destructive" @click="pendingAction = { token, action: 'delete' }" />
                                    </ui-dropdown-menu>
                                </ui-dropdown>
                            </ui-table-cell>
                        </ui-table-row>
                    </ui-table-rows>
                </ui-table>
            </div>
        </ui-card>
        <ui-description v-if="filteredTokens.length" :text="`${filteredTokens.length} of ${tokens.length} tokens`" />
    </div>

    <ui-stack v-model:open="showTokenForm" :title="editingToken ? 'Edit Token' : 'Create Token'" size="half" :before-close="() => !submitting">
        <ui-stack-content>
            <form id="mcp-token-form" class="space-y-6" @submit.prevent="saveToken">
                <ui-alert v-if="admin && !editingToken" text="New tokens belong to you. You cannot create a credential for another user." />
                <ui-description v-if="editingToken" :text="`Created ${formatDate(editingToken.created_at)}. Changes apply immediately to connected clients.`" />
                <ui-field label="Name" instructions="A label to identify the client using this token." :error="fieldErrors.name" required>
                    <ui-input v-model="form.name" placeholder="e.g. Cursor IDE" focus :disabled="submitting" />
                </ui-field>

                <ui-field label="Permissions" instructions="Start with a preset, then choose only the access this client needs." :errors="scopeErrors">
                    <div class="flex flex-wrap gap-2">
                        <ui-button v-for="preset in scopePresets" :key="preset.name" :text="preset.name" size="sm" :variant="isPresetActive(preset) ? 'primary' : 'default'" :aria-pressed="isPresetActive(preset)" :disabled="submitting" @click="applyPreset(preset)" />
                    </div>
                    <ui-card v-for="(scopes, group) in groupedScopes" :key="group" variant="flat">
                        <ui-field :label="group.charAt(0).toUpperCase() + group.slice(1).replaceAll('-', ' ')">
                            <template #actions>
                                <ui-button :text="isGroupFullyChecked(group) ? 'Clear group' : 'Select group'" size="sm" variant="ghost" :disabled="submitting" @click="toggleGroup(group)" />
                            </template>
                            <ui-checkbox-group v-model="form.scopes" :aria-label="`${group} permissions`">
                                <ui-checkbox v-for="scope in scopes" :key="scope.value" :value="scope.value" :label="scope.label" :description="scope.description" :disabled="submitting" />
                            </ui-checkbox-group>
                        </ui-field>
                    </ui-card>
                </ui-field>

                <ui-field label="Expiration" :instructions="maxTokenLifetimeDays ? `Tokens expire within ${maxTokenLifetimeDays} days. Leave blank for the longest allowed.` : 'Leave blank for a token that never expires.'" :error="fieldErrors.expires_at">
                    <ui-input v-model="form.expires_at" type="date" :max="defaultExpiryDate || undefined" :disabled="submitting" />
                </ui-field>
            </form>
        </ui-stack-content>
        <ui-stack-footer class="flex justify-end gap-2">
            <ui-button text="Cancel" :disabled="submitting" @click="showTokenForm = false" />
            <ui-button :text="editingToken ? 'Save Changes' : 'Create Token'" variant="primary" :loading="submitting" :disabled="submitting" type="submit" form="mcp-token-form" />
        </ui-stack-footer>
    </ui-stack>

    <ui-stack :open="!!pendingAction" :title="pendingAction?.action === 'delete' ? 'Delete Token' : 'Regenerate Token'" size="narrow" :before-close="() => !actionBusy" @closed="pendingAction = null">
        <ui-stack-content>
            <ui-alert variant="warning" :heading="pendingAction?.token.name" :text="pendingAction?.action === 'delete' ? 'Clients using this token will lose access immediately. This cannot be undone.' : 'The old secret will stop working immediately. You will need to update every client using this token.'" />
            <ui-alert v-if="actionError" variant="error" class="mt-4">{{ actionError }}</ui-alert>
        </ui-stack-content>
        <ui-stack-footer class="flex justify-end gap-2">
            <ui-button text="Cancel" :disabled="actionBusy" @click="pendingAction = null" />
            <ui-button :text="pendingAction?.action === 'delete' ? 'Delete Token' : 'Regenerate Token'" variant="danger" :loading="actionBusy" :disabled="actionBusy" @click="confirmAction" />
        </ui-stack-footer>
    </ui-stack>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@statamic/cms/inertia';
import { useTokenApi } from '../composables/useTokenApi.js';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    availableScopes: { type: Array, default: () => [] },
    capabilityOffer: { type: String, default: null },
    maxTokenLifetimeDays: { type: Number, default: null },
    admin: { type: Boolean, default: false },
});

const { submitting, createToken, updateToken, deleteToken, regenerateToken } = useTokenApi();
const showTokenForm = ref(false);
const editingToken = ref(null);
const newToken = ref(null);
const search = ref('');
const pendingAction = ref(null);
const actionBusy = ref(false);
const actionError = ref(null);
const form = ref({ name: '', scopes: [], expires_at: '', errors: {} });

watch(pendingAction, () => { actionError.value = null; });

const filteredTokens = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.tokens.filter(token => [token.name, ...(props.admin ? [token.user_name, token.user_email] : [])]
        .some(value => value?.toLowerCase().includes(query)));
});

const defaultExpiryDate = computed(() => {
    if (!props.maxTokenLifetimeDays) return '';
    const date = new Date();
    date.setDate(date.getDate() + props.maxTokenLifetimeDays);
    return date.toISOString().split('T')[0];
});

// Presets are narrowed to the user's offer, never an independent grant.
const presetDefinitions = [
    { name: 'Read Only', scopes: ['blueprints:read', 'entries:read', 'terms:read', 'globals:read', 'structures:read', 'assets:read', 'system:read'] },
    { name: 'Content Editor', scopes: ['entries:read', 'entries:write', 'terms:read', 'terms:write', 'globals:read', 'globals:write', 'blueprints:read', 'structures:read', 'assets:read', 'assets:write'] },
    { name: 'Content Publisher', scopes: ['entries:read', 'entries:write', 'entries:publish', 'entries:delete', 'terms:read', 'terms:write', 'terms:delete', 'globals:read', 'globals:write', 'blueprints:read', 'structures:read', 'assets:read', 'assets:write', 'assets:delete'] },
    { name: 'Full Access', scopes: ['*'] },
];
const scopePresets = computed(() => {
    const available = new Set(props.availableScopes.map(scope => scope.value));
    return presetDefinitions.map(preset => ({ ...preset, scopes: preset.scopes.filter(scope => available.has(scope)) }))
        .filter(preset => preset.scopes.length);
});
const groupedScopes = computed(() => Object.groupBy(props.availableScopes, scope => scope.group || 'other'));
// Statamic Field renders error strings as HTML. Validation can echo user input.
const fieldErrors = computed(() => Object.fromEntries(Object.entries(form.value.errors)
    .map(([key, value]) => [key, String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')])));
const scopeErrors = computed(() => Object.entries(fieldErrors.value)
    .filter(([key]) => key === 'capability_offer' || key === 'scopes' || key.startsWith('scopes.'))
    .map(([, value]) => value));

function isPresetActive(preset) {
    return preset.scopes.length === form.value.scopes.length && preset.scopes.every(scope => form.value.scopes.includes(scope));
}

function applyPreset(preset) {
    form.value.scopes = isPresetActive(preset) ? [] : [...preset.scopes];
}

function isGroupFullyChecked(group) {
    return groupedScopes.value[group].every(scope => form.value.scopes.includes(scope.value));
}

function toggleGroup(group) {
    const values = groupedScopes.value[group].map(scope => scope.value);
    form.value.scopes = isGroupFullyChecked(group)
        ? form.value.scopes.filter(scope => !values.includes(scope))
        : [...new Set([...form.value.scopes, ...values])];
}

function openCreate() {
    editingToken.value = null;
    form.value = { name: '', scopes: [], expires_at: defaultExpiryDate.value, errors: {} };
    showTokenForm.value = true;
}

function openEdit(token) {
    editingToken.value = token;
    form.value = { name: token.name, scopes: [...(token.scopes || [])], expires_at: token.expires_at?.split('T')[0] || '', errors: {} };
    showTokenForm.value = true;
}

function reloadTokens() {
    router.reload({ only: [props.admin ? 'allTokens' : 'tokens'] });
}

async function saveToken() {
    if (submitting.value) return;
    form.value.errors = {};
    const token = editingToken.value;
    const result = token
        ? await updateToken(token.id, {
            name: form.value.name,
            scopes: form.value.scopes,
            expires_at: form.value.expires_at || (props.maxTokenLifetimeDays && token.expires_at ? defaultExpiryDate.value : null),
            clear_expiry: !props.maxTokenLifetimeDays && !form.value.expires_at && !!token.expires_at,
        })
        : await createToken({ ...form.value, capability_offer: props.capabilityOffer });
    if (!result.ok) {
        form.value.errors = result.errors;
        return;
    }
    if (result.token) newToken.value = result.token;
    showTokenForm.value = false;
    Statamic.$toast.success(token ? 'Token updated.' : 'Token created.');
    reloadTokens();
}

async function confirmAction() {
    if (actionBusy.value || !pendingAction.value) return;
    actionBusy.value = true;
    actionError.value = null;
    const { token, action } = pendingAction.value;
    const result = await (action === 'delete' ? deleteToken(token.id) : regenerateToken(token.id));
    actionBusy.value = false;
    if (!result.ok) {
        actionError.value = result.message;
        return;
    }
    if (result.token) newToken.value = result.token;
    pendingAction.value = null;
    Statamic.$toast.success(action === 'delete' ? 'Token deleted.' : 'Token regenerated.');
    reloadTokens();
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : 'Never';
}

defineExpose({ triggerCreate: openCreate });
</script>
