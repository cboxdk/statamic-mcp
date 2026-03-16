import McpPage from './pages/McpPage.vue';
import McpAdminPage from './pages/McpAdminPage.vue';
import OAuthConsent from './pages/OAuthConsent.vue';

Statamic.$inertia.register('statamic-mcp::McpPage', McpPage);
Statamic.$inertia.register('statamic-mcp::McpAdminPage', McpAdminPage);
Statamic.$inertia.register('statamic-mcp::OAuthConsent', OAuthConsent);
