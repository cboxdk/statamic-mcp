const { test, expect } = require('@playwright/test');

const email = process.env.TEST_EMAIL || 'test@example.com';
const password = process.env.TEST_PASSWORD || 'password';

async function login(page) {
    await page.goto('/cp/auth/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.getByRole('button', { name: /continue|sign in|log in/i }).click();
    // Statamic's Vue login does window.location.href after success — wait for full navigation
    await page.waitForURL((url) => !url.pathname.includes('/auth/'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');
}

test('CP login works', async ({ page }) => {
    await login(page);
    // After login, Statamic redirects away from the auth page
    await expect(page).not.toHaveURL(/\/auth\//);
});

test('MCP user page loads', async ({ page }) => {
    await login(page);
    await page.goto('/cp/mcp');
    await expect(page.locator('h1')).toContainText('MCP');
    await expect(page.getByRole('tab', { name: 'Connect' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'My Tokens' })).toBeVisible();
});

test('MCP admin page loads', async ({ page }) => {
    await login(page);
    await page.goto('/cp/mcp/admin');
    await expect(page.locator('h1')).toContainText('MCP Admin');
    await expect(page.getByRole('tab', { name: 'All Tokens' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Activity' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'System' })).toBeVisible();
});

test('OAuth discovery endpoint returns JSON', async ({ request }) => {
    const response = await request.get('/.well-known/oauth-protected-resource');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body).toHaveProperty('resource');
    expect(body).toHaveProperty('authorization_servers');
    expect(body).toHaveProperty('scopes_supported');
});

test('OAuth authorization server metadata returns JSON', async ({ request }) => {
    const response = await request.get('/.well-known/oauth-authorization-server');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body).toHaveProperty('authorization_endpoint');
    expect(body).toHaveProperty('token_endpoint');
    expect(body).toHaveProperty('registration_endpoint');
    expect(body.code_challenge_methods_supported).toContain('S256');
});

test('OAuth client registration works', async ({ request }) => {
    const response = await request.post('/mcp/oauth/register', {
        data: {
            client_name: 'Playwright Test',
            redirect_uris: ['https://example.com/callback'],
        },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body).toHaveProperty('client_id');
    expect(body.client_name).toBe('Playwright Test');
});

test('MCP endpoint returns 401 with resource_metadata', async ({ request }) => {
    const response = await request.post('/mcp/statamic', {
        data: { jsonrpc: '2.0', method: 'initialize', id: 1 },
    });
    expect(response.status()).toBe(401);
    const wwwAuth = response.headers()['www-authenticate'];
    expect(wwwAuth).toContain('resource_metadata');
});

test('Connect tab shows client selector', async ({ page }) => {
    await login(page);
    await page.goto('/cp/mcp');
    // Client buttons should be visible
    await expect(page.getByRole('button', { name: 'Claude Desktop' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'ChatGPT' })).toBeVisible();
});

test('Claude Desktop guide shows OAuth steps', async ({ page }) => {
    await login(page);
    await page.goto('/cp/mcp');
    await page.getByRole('button', { name: 'Claude Desktop' }).click();
    // Should mention Settings → Connectors
    await expect(page.locator('text=Connectors').first()).toBeVisible();
    // Should NOT show config file editing
    await expect(page.locator('text=claude_desktop_config.json')).not.toBeVisible();
});
