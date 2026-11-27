<template>
    <div class="space-y-4">
        <ui-description>{{ __('statamic-mcp::cp.profile_help', { profile }) }}</ui-description>
        <ui-description v-if="profile === 'editor'">{{ __('statamic-mcp::cp.editor_profile_help') }}</ui-description>
        <ui-card v-for="group in groups" :key="group.domain" variant="flat">
            <ui-field :label="domainLabel(group)">
                <ui-checkbox-group v-if="group.scopes.some(scope => scope.group === 'integrations')" :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)">
                    <ui-checkbox v-for="scope in group.scopes" :key="scope.value" :value="scope.value" :label="scope.label" :disabled="disabled || (!!restrictions[scope.value] && !modelValue.includes(scope.value))" />
                </ui-checkbox-group>
                <ui-select v-else :aria-label="__('statamic-mcp::cp.domain_access', { domain: domainLabel(group) })" :model-value="selection(group)" :options="levels(group)" :disabled="disabled" @update:model-value="select(group, $event)" />
                <ui-description v-for="scope in group.scopes.filter(scope => modelValue.includes(scope.value))" :key="scope.value" class="mt-2">{{ scope.description }}</ui-description>
                <ui-description v-for="reason in [...new Set(group.scopes.map(scope => restrictions[scope.value]).filter(Boolean))]" :key="reason" class="mt-2">{{ reason }}</ui-description>
            </ui-field>
        </ui-card>
        <ui-alert v-if="unavailable.length" variant="warning">
            <ui-description>{{ __('statamic-mcp::cp.unavailable_scopes', { scopes: unavailable.join(', ') }) }}</ui-description>
            <ui-button :text="__('statamic-mcp::cp.remove_unavailable')" size="sm" :disabled="disabled" @click="$emit('update:modelValue', modelValue.filter(scope => !unavailable.includes(scope)))" />
        </ui-alert>
    </div>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    scopes: { type: Array, default: () => [] },
    restrictions: { type: Object, default: () => ({}) },
    profile: { type: String, default: 'disabled' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const groups = computed(() => Object.entries(Object.groupBy(props.scopes.filter(scope => scope.value !== '*'), scope => scope.value.split(':')[0]))
    .map(([domain, scopes]) => ({ domain, scopes })));
const unavailable = computed(() => props.modelValue.filter(value => !props.scopes.some(scope => scope.value === value) || value === '*'));
// The native select treats an empty value as its placeholder, not an option.
const key = scopes => [...scopes].sort().join(' ') || 'none';
function selected(group) { return props.modelValue.filter(value => group.scopes.some(scope => scope.value === value)); }
function selection(group) { return key(selected(group)); }
function domainLabel(group) {
    return group.scopes.some(scope => scope.group === 'integrations') ? group.domain : __(`statamic-mcp::cp.domains.${group.domain}`);
}

function levels(group) {
    const names = new Set(group.scopes.map(scope => scope.value));
    const scope = action => `${group.domain}:${action}`;
    const read = names.has(scope('read')) ? [scope('read')] : [];
    const edit = [...read, scope('write')];
    const candidates = [
        ['none', []], ['read', read], ['edit', edit],
        ['edit_publish', [...edit, scope('publish')]],
        ['edit_delete', [...edit, scope('delete')]],
        ['edit_publish_delete', [...edit, scope('publish'), scope('delete')]],
        ['admin', [...read, scope('admin')]],
    ];
    // Submissions has an independent delete grant, without a write scope.
    if (!names.has(scope('write'))) candidates.push(['read_delete', [...read, scope('delete')]]);
    const seen = new Set();
    const options = candidates.filter(([label, values]) => {
        const id = key(values);
        if ((label !== 'none' && !values.length) || seen.has(id) || values.some(value => !names.has(value))) return false;
        seen.add(id);
        return true;
    }).filter(([, values]) => !values.some(value => props.restrictions[value]))
        .map(([label, values]) => ({ label: __(`statamic-mcp::cp.levels.${label}`), value: key(values) }));
    const current = selection(group);
    if (!options.some(option => option.value === current)) options.push({ label: __('statamic-mcp::cp.custom_scopes'), value: current });
    return options;
}

function select(group, value) {
    const choice = levels(group).find(option => option.value === value);
    if (!choice) return;
    const other = props.modelValue.filter(value => !group.scopes.some(scope => scope.value === value));
    emit('update:modelValue', [...other, ...(value === 'none' ? [] : value.split(' '))]);
}
</script>
