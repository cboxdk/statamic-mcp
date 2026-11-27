<template>
    <div class="mx-auto max-w-2xl space-y-6 px-4 py-6">
        <Head :title="__('statamic-mcp::cp.authorize_client', { client: client.name })" />
        <ui-header :title="__('statamic-mcp::cp.authorize_client', { client: client.name })" icon="lock" />
        <ui-card>
            <img v-if="client.logo" :src="client.logo" alt="" class="mb-4 size-12 rounded-full" referrerpolicy="no-referrer" />
            <ui-heading :text="siteName" />
            <ui-description>{{ __('statamic-mcp::cp.signed_in_as', { user: userName }) }}</ui-description>
            <ui-description v-if="client.verified">{{ __('statamic-mcp::cp.client_from', { host: client.host }) }}</ui-description>
            <ui-description v-else>{{ __('statamic-mcp::cp.unverified_client') }}</ui-description>
            <ui-description>{{ __('statamic-mcp::cp.redirect_disclosure', { host: redirectHost }) }}</ui-description>
            <ui-description v-if="localRedirects">{{ __('statamic-mcp::cp.local_redirects') }}</ui-description>
        </ui-card>
        <form :action="submitUrl" method="POST" class="space-y-6">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input type="hidden" name="consent_offer" :value="consentOffer" />
            <input v-for="(value, name) in oauthParams" :key="name" type="hidden" :name="name" :value="value" />
            <input v-for="value in selected" :key="value" type="hidden" name="scopes[]" :value="value" />
            <ui-heading :text="__('statamic-mcp::cp.permissions_selected', { count: selected.length })" />
            <ui-description>{{ __('statamic-mcp::cp.consent_permissions_help') }}</ui-description>
            <ui-card v-for="(items, group) in groups" :key="group">
                <ui-field :label="__(`statamic-mcp::cp.domains.${group}`)">
                    <ui-checkbox-group v-model="selected">
                        <div v-for="scope in items" :key="scope.value">
                            <ui-checkbox :value="scope.value" :label="scope.label" />
                            <ui-description>{{ scope.description }}</ui-description>
                        </div>
                    </ui-checkbox-group>
                </ui-field>
            </ui-card>
            <div class="flex justify-end gap-3">
                <ui-button type="submit" name="decision" value="deny" :text="__('statamic-mcp::cp.deny')" />
                <ui-button type="submit" name="decision" value="approve" :text="__('statamic-mcp::cp.approve')" variant="primary" :disabled="!selected.length" />
            </div>
        </form>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head } from '@statamic/cms/inertia';

// Consent also handles the login redirect; it has no authenticated CP layout props.
defineOptions({ layout: [] });
const props = defineProps({
    client: Object, siteName: String, userName: String, redirectHost: String,
    localRedirects: Boolean, submitUrl: String, csrfToken: String,
    consentOffer: String, oauthParams: Object, scopes: Array, defaultScopes: Array,
});
const selected = ref(props.defaultScopes.filter(value => props.scopes.some(scope => scope.value === value)));
const groups = computed(() => Object.groupBy(props.scopes, scope => scope.group));
</script>
