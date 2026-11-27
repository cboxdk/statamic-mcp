<template>
    <div class="mt-4 space-y-6">
        <ui-alert v-if="newToken" variant="success">
            <ui-heading :text="__('statamic-mcp::cp.copy_token_now')" />
            <ui-description>{{ __('statamic-mcp::cp.token_once') }}</ui-description>
            <ui-field :label="__('statamic-mcp::cp.new_token')">
                <ui-input :model-value="newToken" read-only copyable class="font-mono" />
            </ui-field>
            <ui-button :text="__('statamic-mcp::cp.dismiss')" variant="ghost" size="sm" class="mt-3" @click="newToken = null" />
        </ui-alert>
        <ConnectPanel v-if="newToken" :token="newToken" :clients="clients" :web-enabled="webEnabled" />

        <div class="flex flex-wrap items-end justify-between gap-3">
            <ui-field :label="__('statamic-mcp::cp.search_tokens')" class="w-full sm:max-w-64">
                <ui-input v-model="search" :placeholder="admin ? __('statamic-mcp::cp.search_admin_placeholder') : __('statamic-mcp::cp.token_name')" icon="magnifying-glass" clearable />
            </ui-field>
            <ui-button :text="__('statamic-mcp::cp.create_token')" variant="primary" icon="plus" @click="openCreate" />
        </div>

        <ui-card v-if="!filteredTokens.length">
            <ui-heading :text="search ? __('statamic-mcp::cp.no_matching_tokens') : __('statamic-mcp::cp.no_tokens')" />
            <ui-description>{{ search ? __('statamic-mcp::cp.token_search_help') : __('statamic-mcp::cp.no_tokens_help') }}</ui-description>
            <ui-button v-if="search" :text="__('statamic-mcp::cp.clear_search')" size="sm" @click="search = ''" />
        </ui-card>

        <ui-card v-else>
            <div class="overflow-x-auto">
                <ui-table>
                    <ui-table-columns>
                        <ui-table-column>{{ __('statamic-mcp::cp.name') }}</ui-table-column>
                        <ui-table-column v-if="admin">{{ __('statamic-mcp::cp.user') }}</ui-table-column>
                        <ui-table-column>{{ __('statamic-mcp::cp.permissions') }}</ui-table-column>
                        <ui-table-column>{{ __('statamic-mcp::cp.last_used') }}</ui-table-column>
                        <ui-table-column>{{ __('statamic-mcp::cp.expires') }}</ui-table-column>
                        <ui-table-column><span class="sr-only">{{ __('statamic-mcp::cp.actions') }}</span></ui-table-column>
                    </ui-table-columns>
                    <ui-table-rows>
                        <ui-table-row v-for="token in filteredTokens" :key="token.id">
                            <ui-table-cell>
                                <ui-button :text="token.name" variant="ghost" size="sm" @click="openEdit(token)" />
                                <div class="flex flex-wrap gap-1">
                                    <ui-badge v-if="token.is_oauth" :text="token.oauth_client_name || 'OAuth'" size="sm" />
                                    <ui-badge v-if="token.is_expired" :text="__('statamic-mcp::cp.expired')" color="red" size="sm" />
                                </div>
                            </ui-table-cell>
                            <ui-table-cell v-if="admin">
                                <ui-text :text="token.user_name || __('statamic-mcp::cp.unknown_user')" />
                                <ui-description>{{ token.user_email }}</ui-description>
                            </ui-table-cell>
                            <ui-table-cell>
                                <ui-badge v-if="token.scopes?.includes('*')" :text="__('statamic-mcp::cp.legacy_access')" color="red" size="sm" />
                                <ui-badge v-else :text="token.scopes?.length === 1 ? __('statamic-mcp::cp.one_scope') : __('statamic-mcp::cp.scope_count', { count: token.scopes?.length || 0 })" size="sm" />
                            </ui-table-cell>
                            <ui-table-cell class="whitespace-nowrap">{{ formatDate(token.last_used_at) }}</ui-table-cell>
                            <ui-table-cell class="whitespace-nowrap">
                                <ui-badge v-if="token.expires_at" :text="formatDate(token.expires_at)" :color="token.is_expired ? 'red' : expiresSoon(token) ? 'orange' : 'default'" size="sm" />
                                <ui-description v-else>{{ __('statamic-mcp::cp.never_expires') }}</ui-description>
                                <ui-description v-if="!token.is_expired && expiresSoon(token)">{{ __('statamic-mcp::cp.expires_soon') }}</ui-description>
                            </ui-table-cell>
                            <ui-table-cell>
                                <ui-dropdown align="end">
                                    <template #trigger>
                                        <ui-button icon="dots" variant="ghost" size="sm" :aria-label="__('statamic-mcp::cp.actions_for', { name: token.name })" />
                                    </template>
                                    <ui-dropdown-menu>
                                        <ui-dropdown-item :text="__('statamic-mcp::cp.edit')" icon="pencil" @click="openEdit(token)" />
                                        <ui-dropdown-item v-if="!token.is_oauth && (!admin || token.is_own)" :text="__('statamic-mcp::cp.regenerate')" icon="sync" @click="pendingAction = { token, action: 'regenerate' }" />
                                        <ui-dropdown-item :text="__('statamic-mcp::cp.delete')" icon="trash" variant="destructive" @click="pendingAction = { token, action: 'delete' }" />
                                    </ui-dropdown-menu>
                                </ui-dropdown>
                            </ui-table-cell>
                        </ui-table-row>
                    </ui-table-rows>
                </ui-table>
            </div>
        </ui-card>
        <ui-description v-if="filteredTokens.length">{{ __('statamic-mcp::cp.token_count', { count: filteredTokens.length, total: tokens.length }) }}</ui-description>
    </div>

    <ui-stack v-model:open="showTokenForm" :title="editingToken ? __('statamic-mcp::cp.edit_token') : __('statamic-mcp::cp.create_token')" size="half" :before-close="() => !submitting">
        <ui-stack-content>
            <form id="mcp-token-form" class="space-y-6" @submit.prevent="saveToken">
                <ui-alert v-if="admin && !editingToken" :text="__('statamic-mcp::cp.own_token_only')" />
                <ui-description v-if="editingToken">{{ __('statamic-mcp::cp.token_created_at', { date: formatDate(editingToken.created_at) }) }}</ui-description>
                <ui-field :label="__('statamic-mcp::cp.name')" :instructions="__('statamic-mcp::cp.token_name_help')" :error="fieldErrors.name" required>
                    <ui-input v-model="form.name" :placeholder="__('statamic-mcp::cp.token_name_example')" focus :disabled="submitting" />
                </ui-field>

                <ui-field :label="__('statamic-mcp::cp.permissions')" :instructions="__('statamic-mcp::cp.permissions_help')" :errors="scopeErrors">
                    <div class="flex flex-wrap gap-2">
                        <ui-button v-for="preset in scopePresets" :key="preset.name" :text="preset.name" size="sm" :variant="isPresetActive(preset) ? 'primary' : 'default'" :aria-pressed="isPresetActive(preset)" :disabled="submitting" @click="applyPreset(preset)" />
                    </div>
                    <ScopePicker v-model="form.scopes" :scopes="availableScopes" :profile="httpProfile" :restrictions="scopeRestrictions" :disabled="submitting" />
                </ui-field>

                <ui-field :label="__('statamic-mcp::cp.expiration')" :instructions="maxTokenLifetimeDays ? __('statamic-mcp::cp.expiration_limit', { days: maxTokenLifetimeDays }) : __('statamic-mcp::cp.expiration_help')" :error="fieldErrors.expires_at">
                    <ui-input v-model="form.expires_at" type="date" :max="defaultExpiryDate || undefined" :disabled="submitting" />
                </ui-field>
            </form>
        </ui-stack-content>
        <ui-stack-footer class="flex justify-end gap-2">
            <ui-button :text="__('statamic-mcp::cp.cancel')" :disabled="submitting" @click="showTokenForm = false" />
            <ui-button :text="editingToken ? __('statamic-mcp::cp.save_changes') : __('statamic-mcp::cp.create_token')" variant="primary" :loading="submitting" :disabled="submitting" type="submit" form="mcp-token-form" />
        </ui-stack-footer>
    </ui-stack>

    <ui-stack :open="!!pendingAction" :title="pendingAction?.action === 'delete' ? __('statamic-mcp::cp.delete_token') : __('statamic-mcp::cp.regenerate_token')" size="narrow" :before-close="() => !actionBusy" @closed="pendingAction = null">
        <ui-stack-content>
            <ui-alert variant="warning" :heading="pendingAction?.token.name" :text="pendingAction?.action === 'delete' ? __('statamic-mcp::cp.delete_token_warning') : __('statamic-mcp::cp.regenerate_token_warning')" />
            <ui-alert v-if="actionError" variant="error" class="mt-4">{{ actionError }}</ui-alert>
        </ui-stack-content>
        <ui-stack-footer class="flex justify-end gap-2">
            <ui-button :text="__('statamic-mcp::cp.cancel')" :disabled="actionBusy" @click="pendingAction = null" />
            <ui-button :text="pendingAction?.action === 'delete' ? __('statamic-mcp::cp.delete_token') : __('statamic-mcp::cp.regenerate_token')" variant="danger" :loading="actionBusy" :disabled="actionBusy" @click="confirmAction" />
        </ui-stack-footer>
    </ui-stack>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@statamic/cms/inertia';
import { useTokenApi } from '../composables/useTokenApi.js';
import ConnectPanel from './ConnectPanel.vue';
import ScopePicker from './ScopePicker.vue';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    clients: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    availableScopes: { type: Array, default: () => [] },
    httpProfile: { type: String, default: 'disabled' },
    scopeRestrictions: { type: Object, default: () => ({}) },
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

function expiresSoon(token) {
    return token.expires_at && new Date(token.expires_at).getTime() - Date.now() <= 7 * 86400000;
}

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
    { name: __('statamic-mcp::cp.preset_read_only'), scopes: ['blueprints:read', 'entries:read', 'terms:read', 'globals:read', 'structures:read', 'assets:read', 'system:read'] },
    { name: __('statamic-mcp::cp.preset_editor'), scopes: ['entries:read', 'entries:write', 'terms:read', 'terms:write', 'globals:read', 'globals:write', 'blueprints:read', 'structures:read', 'assets:read', 'assets:write'] },
    { name: __('statamic-mcp::cp.preset_publisher'), scopes: ['entries:read', 'entries:write', 'entries:publish', 'terms:read', 'terms:write', 'globals:read', 'globals:write', 'blueprints:read', 'structures:read', 'assets:read', 'assets:write'] },
];
const scopePresets = computed(() => {
    const available = new Set(props.availableScopes.map(scope => scope.value).filter(value => value !== '*' && !props.scopeRestrictions[value]));
    return [...presetDefinitions, { name: __('statamic-mcp::cp.preset_full'), scopes: [...available] }]
        .map(preset => ({ ...preset, scopes: preset.scopes.filter(scope => available.has(scope)) }))
        .filter(preset => preset.scopes.length);
});
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
    Statamic.$toast.success(token ? __('statamic-mcp::cp.token_updated') : __('statamic-mcp::cp.token_created'));
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
    Statamic.$toast.success(action === 'delete' ? __('statamic-mcp::cp.token_deleted') : __('statamic-mcp::cp.token_regenerated'));
    reloadTokens();
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString(Statamic.$config.get('locale')?.replaceAll('_', '-'), { year: 'numeric', month: 'short', day: 'numeric' }) : __('statamic-mcp::cp.never');
}

defineExpose({ triggerCreate: openCreate });
</script>
