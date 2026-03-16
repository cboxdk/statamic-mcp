<template>
    <div class="min-h-screen bg-gray-50 dark:bg-dark-800 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div class="bg-white dark:bg-dark-content-bg rounded-xl shadow-lg p-8">
                <div class="text-center mb-6">
                    <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                        <svg class="size-7 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Authorize {{ client.name }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-dark-175">This application wants access to your MCP server.</p>
                </div>

                <form method="POST" :action="approveUrl">
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <input type="hidden" name="client_id" :value="oauthParams.client_id" />
                    <input type="hidden" name="redirect_uri" :value="oauthParams.redirect_uri" />
                    <input type="hidden" name="state" :value="oauthParams.state" />
                    <input type="hidden" name="code_challenge" :value="oauthParams.code_challenge" />
                    <input type="hidden" name="code_challenge_method" :value="oauthParams.code_challenge_method" />

                    <div class="mb-6">
                        <p class="text-sm font-medium text-gray-700 dark:text-dark-100 mb-3">Permissions requested:</p>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            <label v-for="scope in scopes" :key="scope.value" class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="scopes[]" :value="scope.value" :checked="isScopeChecked(scope.value)" class="rounded" />
                                {{ scope.label }}
                            </label>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" name="decision" value="deny" class="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-dark-400 dark:text-dark-100 dark:hover:bg-dark-500">
                            Deny
                        </button>
                        <button type="submit" name="decision" value="approve" class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700">
                            Approve
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    client: { type: Object, required: true },
    scopes: { type: Array, required: true },
    defaultScopes: { type: Array, default: () => ['*'] },
    oauthParams: { type: Object, required: true },
});

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || Statamic.$config.get('csrfToken');
const approveUrl = '/mcp/oauth/authorize';

function isScopeChecked(scopeValue) {
    return props.defaultScopes.includes('*') || props.defaultScopes.includes(scopeValue);
}
</script>
