<template>
    <div class="mt-4 flex flex-col gap-4">
        <!-- Web endpoint disabled notice -->
        <div v-if="!webEnabled" class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/60 dark:bg-amber-950/30">
            <div class="flex items-start gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/50">
                    <svg class="size-5 text-amber-600 dark:text-amber-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">Web endpoint is not enabled</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300/80">
                        Add <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs dark:bg-amber-900/60">STATAMIC_MCP_WEB_ENABLED=true</code>
                        to your <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs dark:bg-amber-900/60">.env</code> file.
                        Stdio transport via <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs dark:bg-amber-900/60">php artisan mcp:start</code> works without this.
                    </p>
                </div>
            </div>
        </div>

            <!-- Endpoint URL -->
            <ui-card v-if="webEnabled">
                <div class="flex items-center gap-3 p-3">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-green-50 dark:bg-green-900/20">
                        <svg class="size-4 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-400">MCP Endpoint</p>
                        <p class="truncate font-mono text-sm">{{ mcpEndpoint }}</p>
                    </div>
                    <ui-button :text="endpointCopied ? 'Copied!' : 'Copy'" icon="clipboard" size="sm" variant="ghost" @click="copyEndpoint" />
                </div>
            </ui-card>

            <!-- Client selector -->
            <ui-card>
                <div class="p-4 pb-3">
                    <h3 class="text-sm font-semibold">Choose your client</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Select an AI assistant to see setup instructions.</p>
                </div>
                <div class="grid border-t border-gray-100 dark:border-gray-600" :style="{ gridTemplateColumns: `repeat(${Object.keys(clients).length}, 1fr)` }">
                    <button
                        v-for="(client, clientId) in clients"
                        :key="clientId"
                        class="group flex cursor-pointer flex-col items-center gap-2 border-r border-gray-100 px-2 py-4 text-center transition-all last:border-r-0 dark:border-gray-600"
                        :class="selectedClient === clientId ? 'bg-gray-50 dark:bg-gray-800' : 'hover:bg-gray-50/50 dark:hover:bg-gray-800'"
                        @click="selectClient(clientId)"
                    >
                        <div
                            class="flex size-10 items-center justify-center rounded-xl transition-all"
                            :class="selectedClient === clientId
                                ? clientColors[clientId]?.activeBg || 'bg-blue-100 dark:bg-blue-800'
                                : 'bg-gray-100 group-hover:bg-gray-150 dark:bg-gray-700 dark:group-hover:bg-gray-700'"
                        >
                            <span
                                :class="selectedClient === clientId
                                    ? clientColors[clientId]?.activeText || 'text-blue-600'
                                    : 'text-gray-400 group-hover:text-gray-500 dark:text-gray-400'"
                                v-html="clientIcons[clientId]"
                            />
                        </div>
                        <span class="text-xs font-medium" :class="selectedClient === clientId ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'">{{ client.name }}</span>
                    </button>
                </div>
            </ui-card>

            <!-- Setup instructions -->
            <template v-if="selectedClient && clientInstructions[selectedClient]">
                <div class="flex flex-col gap-4">
                    <!-- Steps -->
                    <ui-card>
                        <div class="p-4">
                            <h3 class="mb-3 text-sm font-semibold">Setup</h3>
                            <ol class="flex flex-col gap-2.5">
                                <li v-for="(step, i) in clientInstructions[selectedClient].steps" :key="i" class="flex gap-3">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-400">{{ i + 1 }}</span>
                                    <span class="text-sm leading-relaxed" v-html="step" />
                                </li>
                            </ol>
                        </div>
                        <!-- Warnings -->
                        <div v-if="clientInstructions[selectedClient].warnings?.length" class="border-t border-gray-100 px-4 py-3 dark:border-gray-600">
                            <div v-for="(warning, i) in clientInstructions[selectedClient].warnings" :key="i" class="flex items-start gap-2">
                                <svg class="mt-0.5 size-4 shrink-0 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <span class="text-xs text-amber-700 dark:text-amber-400" v-html="warning" />
                            </div>
                        </div>
                    </ui-card>

                    <!-- CLI command toggle + blocks -->
                    <template v-if="activeConfig">
                        <div v-if="activeConfig.cli" class="flex items-center gap-1.5">
                            <button
                                class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all"
                                :class="configMode === 'cli' ? 'bg-gray-900 text-white dark:bg-gray-400 dark:text-gray-900' : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400 dark:hover:bg-gray-700'"
                                @click="configMode = 'cli'"
                            >CLI Command</button>
                            <button
                                class="rounded-md px-3 py-1.5 text-xs font-semibold transition-all"
                                :class="configMode === 'json' ? 'bg-gray-900 text-white dark:bg-gray-400 dark:text-gray-900' : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400 dark:hover:bg-gray-700'"
                                @click="configMode = 'json'"
                            >JSON Config</button>
                        </div>

                        <!-- CLI block -->
                        <ui-card v-if="configMode === 'cli' && activeConfig.cli" class="overflow-hidden">
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Terminal</span>
                                <ui-button :text="configCopied ? 'Copied!' : 'Copy'" icon="clipboard" size="sm" variant="ghost" @click="copyCli" />
                            </div>
                            <div class="bg-[#1e1e2e] px-4 py-3">
                                <pre class="overflow-x-auto whitespace-pre-wrap break-all font-mono text-[13px] leading-relaxed text-[#a6e3a1]">{{ activeConfig.cli }}</pre>
                            </div>
                            <div class="bg-gray-100 px-4 py-2 dark:bg-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Replace <code class="font-mono font-semibold text-gray-700 dark:text-gray-200">&lt;YOUR_TOKEN&gt;</code> with a token from the <button class="font-semibold text-blue-600 hover:underline dark:text-blue-400" @click="$emit('switch-tab', 'tokens')">Tokens tab</button>.
                                </p>
                            </div>
                        </ui-card>

                        <!-- JSON block -->
                        <ui-card v-if="configMode === 'json' || !activeConfig.cli" class="overflow-hidden">
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ activeConfig.name }}</span>
                                    <span v-if="activeConfig.config_file" class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-500 dark:bg-gray-700 dark:text-gray-400">{{ activeConfig.config_file }}</span>
                                </div>
                                <ui-button :text="configCopied ? 'Copied!' : 'Copy'" icon="clipboard" size="sm" variant="ghost" @click="copyConfig" />
                            </div>
                            <div class="bg-[#1e1e2e] px-4 py-3">
                                <pre class="overflow-x-auto font-mono text-[13px] leading-relaxed text-[#cdd6f4]">{{ JSON.stringify(activeConfig.config, null, 2) }}</pre>
                            </div>
                            <div class="bg-gray-100 px-4 py-2 dark:bg-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <template v-if="activeConfig.config_file">Paste into <code class="font-mono font-semibold text-gray-700 dark:text-gray-200">{{ activeConfig.config_file }}</code>. </template>
                                    Replace <code class="font-mono font-semibold text-gray-700 dark:text-gray-200">&lt;YOUR_TOKEN&gt;</code> with a token from the <button class="font-semibold text-blue-600 hover:underline dark:text-blue-400" @click="$emit('switch-tab', 'tokens')">Tokens tab</button>.
                                </p>
                            </div>
                        </ui-card>
                    </template>

                    <!-- ChatGPT: no config block, just instructions -->
                    <ui-card v-if="selectedClient === 'chatgpt' && !activeConfig" class="overflow-hidden">
                        <div class="bg-gray-100 px-4 py-3 dark:bg-gray-800">
                            <p class="text-sm text-gray-600 dark:text-gray-300">ChatGPT uses a web-based UI to manage connectors — no config file needed. Follow the steps above.</p>
                        </div>
                    </ui-card>
                </div>
            </template>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useTokenApi } from '../composables/useTokenApi.js';

const props = defineProps({
    clients: { type: Object, default: () => ({}) },
    webEnabled: { type: Boolean, default: false },
    mcpEndpoint: { type: String, default: '' },
});

const emit = defineEmits(['switch-tab']);

const { fetchClientConfig } = useTokenApi();

const selectedClient = ref(null);
const activeConfig = ref(null);
const configMode = ref('cli');
const configCopied = ref(false);
const endpointCopied = ref(false);

// Client setup instructions (verified March 2026)
const clientInstructions = computed(() => {
    const endpoint = props.mcpEndpoint || 'https://your-site.test/mcp/statamic';
    const codeEl = (text) => `<code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs dark:bg-gray-700">${text}</code>`;
    return {
        'claude-desktop': {
            steps: [
                'Open <strong>Claude Desktop</strong> and go to <strong>Settings → Connectors</strong>.',
                'Click <strong>Add custom connector</strong>.',
                `Give it a name (e.g. "Statamic") and enter the MCP server URL: ${codeEl(endpoint)}`,
                'Click <strong>Add</strong> — Claude will start the OAuth flow automatically.',
                'You will be redirected to your <strong>Statamic Control Panel</strong> to approve access.',
                'Select the permissions you want to grant and click <strong>Approve</strong>.',
            ],
            warnings: [
                'Requires <strong>Claude Desktop</strong> with Connectors support.',
                'Your MCP server must be <strong>publicly accessible</strong> (use ngrok for local development).',
                'OAuth is enabled by default — no additional server configuration needed.',
            ],
        },
        'claude-code': {
            steps: [
                'Open your <strong>terminal</strong> in the project directory.',
                'Run the <strong>CLI command</strong> below to register the MCP server.',
                'Claude Code connects automatically — no restart needed.',
            ],
            warnings: [
                `Add ${codeEl('-s user')} to make the server available across all projects.`,
            ],
        },
        cursor: {
            steps: [
                'Open <strong>Cursor</strong> and go to <strong>Settings → MCP</strong>.',
                'Click <strong>Add new MCP server</strong>.',
                `Choose <strong>Type: streamable-http</strong> and paste the server URL.`,
                `Or paste the JSON config below into ${codeEl('.cursor/mcp.json')} in your project root.`,
            ],
            warnings: [
                'Cursor natively supports streamable HTTP with Bearer token headers. No bridge or extra tools needed.',
                'You may need to restart Cursor after adding the server.',
            ],
        },
        chatgpt: {
            steps: [
                'Open <strong>chatgpt.com</strong> in your browser.',
                'Go to <strong>Settings → Apps</strong> (requires Developer Mode enabled under <strong>Settings → Profile</strong>).',
                'Click <strong>Create</strong> to add a new MCP app.',
                `Enter the server URL: ${codeEl(endpoint)}`,
                'ChatGPT handles the OAuth flow automatically — you will be redirected to approve access in your Statamic control panel.',
                'Select the permissions you want to grant and click <strong>Approve</strong>.',
            ],
            warnings: [
                'Requires a <strong>Plus, Team, Enterprise, or Edu</strong> plan.',
                'Your MCP server must be <strong>publicly accessible</strong> (use ngrok for local development).',
                'OAuth is enabled by default — no additional server configuration needed.',
            ],
        },
        windsurf: {
            steps: [
                `Open the config file at ${codeEl('~/.codeium/windsurf/mcp_config.json')}`,
                'Paste the JSON config below.',
                '<strong>Restart Windsurf</strong> for changes to take effect.',
            ],
            warnings: [
                `Windsurf uses ${codeEl('serverUrl')} instead of ${codeEl('url')} — this is already set correctly in the config below.`,
            ],
        },
        generic: {
            steps: [
                'Add the JSON config below to your MCP client\'s configuration file.',
                'Set the transport type to <strong>streamable-http</strong>.',
                `Replace ${codeEl('&lt;YOUR_TOKEN&gt;')} with a token from the Tokens tab.`,
            ],
            warnings: [],
        },
    };
});

// Client visual config
const clientIcons = {
    'claude-desktop': '<svg class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M4.709 15.955l4.397-2.834-.997-1.286L2.51 15.267a.87.87 0 0 0-.51.795c0 .726.796 1.17 1.417.77l1.292-.877zm8.837-12.96a.87.87 0 0 0-.87 0L5.318 7.544l1.2 1.09 6.158-3.755a.87.87 0 0 1 1.305.752v8.49l1.568.803V4.435a1.74 1.74 0 0 0-.87-1.506l-1.133-.934zM19.291 8.045l-4.397 2.834.997 1.286 5.599-3.432a.87.87 0 0 0 .51-.795c0-.726-.796-1.17-1.417-.77l-1.292.877zm-2.933 8.411l-1.2-1.09-6.158 3.755a.87.87 0 0 1-1.305-.752v-8.49l-1.568-.803v10.489a1.74 1.74 0 0 0 .87 1.506l7.361 4.549a.87.87 0 0 0 .87 0l1.13-.62z"/></svg>',
    'claude-code': '<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>',
    cursor: '<svg class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
    chatgpt: '<svg class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.896zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/></svg>',
    windsurf: '<svg class="size-5" viewBox="0 0 24 24" fill="currentColor"><path d="M3 17h4V7H3v10zm6 0h4V3H9v14zm6 0h4v-6h-4v6z"/></svg>',
    generic: '<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
};

const clientColors = {
    'claude-desktop': { activeBg: 'bg-orange-100 dark:bg-orange-900/40', activeText: 'text-orange-600 dark:text-orange-400' },
    'claude-code': { activeBg: 'bg-orange-100 dark:bg-orange-900/40', activeText: 'text-orange-600 dark:text-orange-400' },
    cursor: { activeBg: 'bg-blue-100 dark:bg-blue-900/40', activeText: 'text-blue-600 dark:text-blue-400' },
    chatgpt: { activeBg: 'bg-emerald-100 dark:bg-emerald-900/40', activeText: 'text-emerald-600 dark:text-emerald-400' },
    windsurf: { activeBg: 'bg-teal-100 dark:bg-teal-900/40', activeText: 'text-teal-600 dark:text-teal-400' },
    generic: { activeBg: 'bg-gray-200 dark:bg-gray-600', activeText: 'text-gray-600 dark:text-gray-200' },
};

async function selectClient(clientId) {
    selectedClient.value = clientId;
    configCopied.value = false;
    activeConfig.value = null;

    // OAuth-based clients don't need config files — just the setup steps
    if (clientId === 'chatgpt' || clientId === 'claude-desktop') {
        configMode.value = 'json';
        return;
    }

    const result = await fetchClientConfig(clientId);
    if (result.ok) {
        activeConfig.value = result.data;
        configMode.value = activeConfig.value.cli ? 'cli' : 'json';
    }
}

function copyConfig() {
    if (!activeConfig.value) return;
    navigator.clipboard.writeText(JSON.stringify(activeConfig.value.config, null, 2)).then(() => {
        configCopied.value = true;
        setTimeout(() => { configCopied.value = false; }, 2000);
    });
}

function copyCli() {
    if (!activeConfig.value?.cli) return;
    navigator.clipboard.writeText(activeConfig.value.cli).then(() => {
        configCopied.value = true;
        setTimeout(() => { configCopied.value = false; }, 2000);
    });
}

function copyEndpoint() {
    navigator.clipboard.writeText(props.mcpEndpoint).then(() => {
        endpointCopied.value = true;
        setTimeout(() => { endpointCopied.value = false; }, 2000);
    });
}
</script>
