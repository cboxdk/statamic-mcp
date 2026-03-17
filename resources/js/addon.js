import McpPage from './pages/McpPage.vue';
import McpAdminPage from './pages/McpAdminPage.vue';

// Use Statamic.booting() to ensure $inertia is available.
// Direct registration can fail if the addon script loads before
// Statamic's CP bundle has finished initializing.
Statamic.booting((Statamic) => {
    Statamic.$inertia.register('statamic-mcp::McpPage', McpPage);
    Statamic.$inertia.register('statamic-mcp::McpAdminPage', McpAdminPage);
});
