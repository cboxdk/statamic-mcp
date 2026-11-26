<template>
    <div class="mt-4 space-y-6">
        <ui-alert v-if="!webEnabled && mode !== 'stdio'" variant="warning">
            <ui-heading :text="__('statamic-mcp::cp.web_not_enabled')" />
            <ui-description>{{ __('statamic-mcp::cp.enable_web_help') }}</ui-description>
        </ui-alert>
        <ui-card-panel v-if="!token && webEnabled" :heading="__('statamic-mcp::cp.server_address')">
            <ui-field :label="__('statamic-mcp::cp.endpoint')" :instructions="__('statamic-mcp::cp.endpoint_help')">
                <ui-input :model-value="mcpEndpoint" read-only copyable class="font-mono" />
            </ui-field>
        </ui-card-panel>

        <ui-card-panel :heading="token ? __('statamic-mcp::cp.connect_new_token') : __('statamic-mcp::cp.connect_assistant')" :subheading="__('statamic-mcp::cp.choose_client_help')">
            <div class="flex flex-wrap gap-2" role="group" :aria-label="__('statamic-mcp::cp.choose_client')">
                <ui-button v-for="(client, id) in offeredClients" :key="id" :text="client.name" :variant="selectedClient === id ? 'primary' : 'default'" :aria-pressed="selectedClient === id" @click="selectClient(id)" />
            </div>
            <ui-field v-if="!token && selectedClient" :label="__('statamic-mcp::cp.connection_method')" class="mt-4">
                <ui-select v-model="mode" :options="modeOptions" />
            </ui-field>
        </ui-card-panel>

        <template v-if="selectedClient">
            <ui-card-panel :heading="__('statamic-mcp::cp.client_setup', { client: clients[selectedClient].name })">
                <ol class="list-decimal space-y-3 pl-5">
                    <li v-for="step in steps" :key="step" class="pl-1 text-sm">{{ step }}</li>
                </ol>
            </ui-card-panel>
            <ui-alert v-if="mode === 'stdio'" variant="warning" :text="__('statamic-mcp::cp.stdio_warning')" />
            <ui-alert v-if="mode === 'oauth' && !oauthEnabled" variant="warning" :text="__('statamic-mcp::cp.oauth_disabled')" />
            <ui-alert v-if="mode === 'bearer' && selectedClient === 'claude-desktop'" :text="__('statamic-mcp::cp.bridge_warning')" />
            <ui-alert v-if="configError" variant="error">
                <ui-heading :text="__('statamic-mcp::cp.config_failed_heading')" />
                <ui-description>{{ configError }}</ui-description>
                <ui-button :text="__('statamic-mcp::cp.try_again')" class="mt-3" size="sm" @click="loadConfig" />
            </ui-alert>
            <ui-card v-else-if="loading" role="status" aria-live="polite">
                <ui-description>{{ __('statamic-mcp::cp.loading_config') }}</ui-description>
                <ui-skeleton class="h-8" />
            </ui-card>
            <ui-card-panel v-else-if="activeConfig?.snippet" :heading="__('statamic-mcp::cp.client_configuration')">
                <ui-tabs v-model="configMode">
                    <ui-tab-list v-if="activeConfig.cli">
                        <ui-tab-trigger name="cli" :text="__('statamic-mcp::cp.cli_command')" />
                        <ui-tab-trigger name="config" :text="__('statamic-mcp::cp.format_config', { format: activeConfig.format })" />
                    </ui-tab-list>
                    <ui-tab-content v-if="activeConfig.cli" name="cli" class="mt-4">
                        <ui-field :label="__('statamic-mcp::cp.terminal_command')">
                            <template #actions><ui-button :text="__('statamic-mcp::cp.copy_command')" icon="clipboard" size="sm" @click="copy(activeCli)" /></template>
                            <pre class="overflow-x-auto text-xs leading-relaxed">{{ activeCli }}</pre>
                        </ui-field>
                    </ui-tab-content>
                    <ui-tab-content name="config" class="mt-4">
                        <ui-field :label="__('statamic-mcp::cp.format_configuration', { format: activeConfig.format })">
                            <template #actions><ui-button :text="__('statamic-mcp::cp.copy_format', { format: activeConfig.format })" icon="clipboard" size="sm" @click="copy(activeSnippet)" /></template>
                            <pre class="overflow-x-auto text-xs leading-relaxed">{{ activeSnippet }}</pre>
                        </ui-field>
                        <ui-description v-if="activeConfig.config_file" class="mt-3">{{ __('statamic-mcp::cp.save_config', { file: activeConfig.config_file }) }}</ui-description>
                    </ui-tab-content>
                </ui-tabs>
                <ui-description v-if="mode === 'bearer'" class="mt-4">
                    <template v-if="!token">{{ __('statamic-mcp::cp.replace_token') }} </template>
                    {{ __('statamic-mcp::cp.secret_warning') }}
                </ui-description>
                <ui-button v-if="mode === 'bearer' && !token" :text="__('statamic-mcp::cp.go_to_tokens')" size="sm" @click="$emit('switch-tab', 'tokens')" />
            </ui-card-panel>
            <ui-card-panel v-if="!token && ['claude-code', 'cursor'].includes(selectedClient)" :heading="__('statamic-mcp::cp.allow_reading')">
                <ui-description>{{ __('statamic-mcp::cp.reading_help') }}</ui-description>
                <ui-field :label="__('statamic-mcp::cp.read_pattern')" class="mt-3">
                    <ui-input :model-value="selectedClient === 'claude-code' ? 'mcp__statamic__*-read' : 'statamic:*-read'" read-only copyable class="font-mono" />
                </ui-field>
            </ui-card-panel>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useTokenApi } from '../composables/useTokenApi.js';

const props = defineProps({
    clients: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    oauthEnabled: { type: Boolean, default: false },
    mcpEndpoint: { type: String, default: '' },
    token: { type: String, default: null },
});
defineEmits(['switch-tab']);

const { fetchClientConfig } = useTokenApi();
const selectedClient = ref(props.token ? 'claude-code' : null);
const mode = ref('bearer');
const activeConfig = ref(null);
const configMode = ref('cli');
const configError = ref(null);
const loading = ref(false);
let selection = 0;

const offeredClients = computed(() => Object.fromEntries(Object.entries(props.clients)
    .filter(([, client]) => !props.token || client.modes.includes('bearer'))));
const modeOptions = computed(() => (props.clients[selectedClient.value]?.modes || []).map(value => ({
    value, label: __(`statamic-mcp::cp.connection_modes.${value}`),
})));
// Secrets stay in component memory: never send them to the config endpoint or persist them.
const activeSnippet = computed(() => activeConfig.value?.snippet?.replaceAll('<YOUR_TOKEN>', () => props.token || '<YOUR_TOKEN>'));
const activeCli = computed(() => activeConfig.value?.cli?.replaceAll('<YOUR_TOKEN>', () => props.token || '<YOUR_TOKEN>'));
const steps = computed(() => {
    const client = selectedClient.value;
    if (mode.value === 'stdio') return [
        __('statamic-mcp::cp.steps.stdio_install'),
        __('statamic-mcp::cp.steps.stdio_profile'),
        __('statamic-mcp::cp.steps.stdio_path'),
    ];
    if (mode.value === 'oauth') {
        if (client === 'claude-desktop') return [
            __('statamic-mcp::cp.steps.desktop_open'),
            __('statamic-mcp::cp.steps.desktop_endpoint'),
            __('statamic-mcp::cp.steps.consent'),
        ];
        if (client === 'chatgpt') return [
            __('statamic-mcp::cp.steps.chatgpt_open'),
            __('statamic-mcp::cp.steps.chatgpt_endpoint'),
            __('statamic-mcp::cp.steps.consent'),
        ];
        return [
            __('statamic-mcp::cp.steps.oauth_config'),
            client === 'claude-code' ? __('statamic-mcp::cp.steps.claude_authenticate') : client === 'codex' ? __('statamic-mcp::cp.steps.codex_authenticate') : __('statamic-mcp::cp.steps.browser_authenticate'),
            __('statamic-mcp::cp.steps.consent'),
        ];
    }
    return [
        props.token ? __('statamic-mcp::cp.steps.new_token') : __('statamic-mcp::cp.steps.create_token'),
        __('statamic-mcp::cp.steps.bearer_config'),
        __('statamic-mcp::cp.steps.check_connection'),
    ];
});

function selectClient(id) {
    selectedClient.value = id;
    mode.value = props.token ? 'bearer' : props.clients[id].modes[0];
}

async function loadConfig() {
    const request = ++selection;
    activeConfig.value = null;
    configError.value = null;
    loading.value = !!selectedClient.value;
    if (!selectedClient.value) return;
    const result = await fetchClientConfig(selectedClient.value, mode.value);
    if (request !== selection) return;
    loading.value = false;
    if (!result.ok) { configError.value = result.message; return; }
    activeConfig.value = result.data;
    configMode.value = result.data.cli ? 'cli' : 'config';
}
watch([selectedClient, mode], loadConfig, { immediate: true });

async function copy(value) {
    try {
        await navigator.clipboard.writeText(value);
        Statamic.$toast.success(__('statamic-mcp::cp.copied'));
    } catch {
        Statamic.$toast.error(__('statamic-mcp::cp.copy_failed'));
    }
}
onBeforeUnmount(() => { selection++; });
</script>
