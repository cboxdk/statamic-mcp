<template>
    <div class="mt-4 space-y-6">
        <ui-alert v-if="!webEnabled" variant="warning">
            <ui-heading text="Web endpoint is not enabled" />
            <ui-description>
                Add <code>STATAMIC_MCP_WEB_ENABLED=true</code> to your <code>.env</code> file.
                Local stdio connections via <code>php artisan mcp:start</code> work without it.
            </ui-description>
        </ui-alert>
        <ui-card-panel v-if="webEnabled" heading="Server address">
            <ui-field label="MCP endpoint" instructions="Use this address when your client asks for the server URL.">
                <ui-input :model-value="mcpEndpoint" read-only copyable class="font-mono" />
            </ui-field>
        </ui-card-panel>

        <ui-card-panel heading="Connect an assistant" subheading="Choose your client to see its setup instructions.">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Choose your client">
                <ui-button v-for="(client, id) in clients" :key="id" :text="client.name" :variant="selectedClient === id ? 'primary' : 'default'" :aria-pressed="selectedClient === id" @click="selectClient(id)" />
            </div>
        </ui-card-panel>

        <template v-if="instructions">
            <ui-card-panel :heading="`${clients[selectedClient].name} setup`">
                <ol class="list-decimal space-y-3 pl-5">
                    <li v-for="step in instructions.steps" :key="step" class="pl-1 text-sm">{{ step }}</li>
                </ol>
            </ui-card-panel>
            <ui-alert v-if="instructions.note" :text="instructions.note" />
            <ui-alert v-if="configError" variant="error">
                <ui-heading text="Configuration could not be loaded" />
                <ui-description>{{ configError }}</ui-description>
                <ui-button text="Try again" class="mt-3" size="sm" @click="selectClient(selectedClient)" />
            </ui-alert>
            <ui-card v-else-if="loading" role="status" aria-live="polite">
                <ui-description text="Loading configuration…" />
                <ui-skeleton class="h-8" />
            </ui-card>
            <ui-card-panel v-else-if="activeConfig" heading="Client configuration">
                <ui-tabs v-model="configMode">
                    <ui-tab-list v-if="activeConfig.cli">
                        <ui-tab-trigger name="cli" text="CLI Command" />
                        <ui-tab-trigger name="json" text="JSON Config" />
                    </ui-tab-list>
                    <ui-tab-content v-if="activeConfig.cli" name="cli" class="mt-4">
                        <ui-field label="Terminal command">
                            <ui-input :model-value="activeConfig.cli" read-only copyable class="font-mono" />
                        </ui-field>
                    </ui-tab-content>
                    <ui-tab-content name="json" class="mt-4">
                        <ui-field label="JSON configuration">
                            <template #actions>
                                <ui-button :text="configCopied ? 'Copied' : 'Copy JSON'" icon="clipboard" size="sm" @click="copyConfig" />
                            </template>
                            <pre class="overflow-x-auto text-xs leading-relaxed">{{ JSON.stringify(activeConfig.config, null, 2) }}</pre>
                        </ui-field>
                        <ui-description v-if="activeConfig.config_file" class="mt-3">Save in <code>{{ activeConfig.config_file }}</code>.</ui-description>
                    </ui-tab-content>
                </ui-tabs>
                <ui-description class="mt-4">Replace <code>&lt;YOUR_TOKEN&gt;</code> with your token. Keep it out of version control.</ui-description>
                <ui-button text="Go to My Tokens" size="sm" @click="$emit('switch-tab', 'tokens')" />
            </ui-card-panel>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { useTokenApi } from '../composables/useTokenApi.js';

const props = defineProps({
    clients: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    mcpEndpoint: { type: String, default: '' },
});
defineEmits(['switch-tab']);

const { fetchClientConfig } = useTokenApi();
const selectedClient = ref(null);
const activeConfig = ref(null);
const configMode = ref('cli');
const configCopied = ref(false);
const configError = ref(null);
const loading = ref(false);
let selection = 0;
let copiedTimer;

const guides = {
    'claude-desktop': {
        steps: [
            'Open Claude Desktop and go to Settings → Connectors.',
            'Choose Add custom connector. Name it Statamic and enter the MCP endpoint above.',
            'Choose Add to start OAuth sign-in in your browser.',
            'Sign in to the Statamic Control Panel, review the permissions and approve access.',
        ],
        note: 'Requires Claude Desktop with Connectors support and a publicly accessible HTTPS endpoint. OAuth must be enabled on this site; no manual token is needed.',
    },
    'claude-code': {
        steps: [
            'Create a token in My Tokens with only the permissions this client needs.',
            'Open a terminal in your project directory.',
            'Replace <YOUR_TOKEN> in the command below and run it to register the server.',
        ],
        note: 'Add -s user to the command to make this connection available across your projects.',
    },
    cursor: {
        steps: [
            'Create a token in My Tokens with only the permissions this client needs.',
            'Open .cursor/mcp.json in your project, or use Cursor’s MCP settings.',
            'Add the JSON configuration below, replace <YOUR_TOKEN> and enable the server.',
        ],
        note: 'Cursor supports streamable HTTP and Bearer headers directly. You may need to restart it after changing the configuration.',
    },
    chatgpt: {
        steps: [
            'Open ChatGPT settings and enable Developer Mode if it is available on your plan.',
            'Under Apps, create a new MCP app using the endpoint above.',
            'Follow the OAuth sign-in to your Statamic Control Panel.',
            'Review the requested permissions and approve access.',
        ],
        note: 'Requires a plan with custom MCP apps and a publicly accessible HTTPS endpoint. OAuth must be enabled on this site; no configuration file is needed.',
    },
    windsurf: {
        steps: [
            'Create a token in My Tokens with only the permissions this client needs.',
            'Open ~/.codeium/windsurf/mcp_config.json.',
            'Add the JSON configuration below, replace <YOUR_TOKEN> and restart Windsurf.',
        ],
        note: 'Windsurf uses serverUrl instead of url. The generated configuration already uses that key.',
    },
    generic: {
        steps: [
            'Create a token in My Tokens with only the permissions this client needs.',
            'Add the JSON configuration below to your MCP client’s configuration.',
            'Use streamable HTTP transport and replace <YOUR_TOKEN> with your token.',
        ],
    },
};
const instructions = computed(() => guides[selectedClient.value]);

async function selectClient(clientId) {
    const request = ++selection;
    selectedClient.value = clientId;
    activeConfig.value = null;
    configError.value = null;
    configCopied.value = false;
    loading.value = false;
    if (clientId === 'chatgpt' || clientId === 'claude-desktop') return;

    loading.value = true;
    const result = await fetchClientConfig(clientId);
    // Switching clients must not show the previous client's late response.
    if (request !== selection) return;
    loading.value = false;
    if (!result.ok) {
        configError.value = result.message;
        return;
    }
    activeConfig.value = result.data;
    configMode.value = result.data.cli ? 'cli' : 'json';
}

async function copyConfig() {
    try {
        await navigator.clipboard.writeText(JSON.stringify(activeConfig.value.config, null, 2));
        configCopied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { configCopied.value = false; }, 2000);
    } catch {
        Statamic.$toast.error('Could not copy. Select the configuration and copy it manually.');
    }
}

onBeforeUnmount(() => { selection++; clearTimeout(copiedTimer); });
</script>
