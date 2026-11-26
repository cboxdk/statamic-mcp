const { test, expect } = require('@playwright/test');

test('CP login works (session is established via globalSetup)', async ({ page }) => {
    // The storageState from globalSetup gives us an authenticated session.
    // Verify we can access the CP without being redirected to login.
    await page.goto('/cp');
    await expect(page).not.toHaveURL(/\/auth\//);
});

test('MCP user page loads', async ({ page }) => {
    await page.goto('/cp/mcp');
    await expect(page.locator('h1')).toContainText('MCP');
    await expect(page.getByRole('tab', { name: 'Connect' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'My Tokens' })).toBeVisible();
});

test('MCP admin page loads', async ({ page }) => {
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
    await page.goto('/cp/mcp');
    // Client buttons should be visible
    await expect(page.getByRole('button', { name: 'Claude Desktop' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'ChatGPT' })).toBeVisible();
});

test('Claude Desktop guide shows OAuth steps', async ({ page }) => {
    await page.goto('/cp/mcp');
    await page.getByRole('button', { name: 'Claude Desktop' }).click();
    // Should mention Settings → Connectors
    await expect(page.locator('text=Connectors').first()).toBeVisible();
    // Should NOT show config file editing
    await expect(page.locator('text=claude_desktop_config.json')).not.toBeVisible();
});

for (const path of ['/cp/mcp?tab=tokens', '/cp/mcp/admin']) {
    test(`token lifecycle through the shared form on ${path}`, async ({ page, request }) => {
        const name = `Browser test ${Date.now()}`;
        await page.goto(path);
        await page.getByRole('button', { name: 'Create Token', exact: true }).click();
        const stack = page.locator('.stack-content');
        await stack.getByRole('textbox', { name: /^Name/ }).fill(name);
        await stack.getByRole('button', { name: 'Create Token', exact: true }).click();
        await expect(stack.locator('[data-ui-error-message]')).toContainText(/required/i);
        await stack.getByRole('button', { name: 'Read Only', exact: true }).click();
        await expect(stack.getByRole('checkbox', { name: 'Read Entries', exact: true })).toBeChecked();
        await stack.getByRole('button', { name: 'Create Token', exact: true }).click();
        await expect(stack).not.toBeVisible();
        await expect(page.getByText('Copy your token now', { exact: true })).toBeVisible();
        const original = await page.getByLabel('New token', { exact: true }).inputValue();
        await page.getByRole('button', { name: 'Dismiss', exact: true }).click();
        await expect(page.getByLabel('New token', { exact: true })).not.toBeVisible();

        await page.getByRole('button', { name, exact: true }).click();
        await expect(stack.getByRole('checkbox', { name: 'Read Entries', exact: true })).toBeChecked();
        await stack.getByRole('textbox', { name: /^Name/ }).fill(name + ' edited');
        await stack.getByRole('button', { name: 'Save Changes' }).click();
        await expect(stack).not.toBeVisible();
        await page.getByRole('button', { name: `Actions for ${name} edited`, exact: true }).click();
        await page.getByRole('menuitem', { name: 'Regenerate' }).click();
        await stack.getByRole('button', { name: 'Regenerate Token', exact: true }).click();
        await expect(stack).not.toBeVisible();
        const regenerated = await page.getByLabel('New token', { exact: true }).inputValue();
        expect(regenerated).not.toBe(original);
        const oldResponse = await request.post('/mcp/statamic', {
            headers: { Authorization: `Bearer ${original}` },
            data: { jsonrpc: '2.0', method: 'initialize', id: 1, params: { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'UI test', version: '1' } } },
        });
        expect(oldResponse.status()).toBe(401);
        await page.reload();
        await expect(page.getByLabel('New token', { exact: true })).not.toBeVisible();
        await page.getByRole('button', { name: `Actions for ${name} edited`, exact: true }).click();
        await page.getByRole('menuitem', { name: 'Delete', exact: true }).click();
        await stack.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(stack).not.toBeVisible();
        await expect(page.getByRole('button', { name: name + ' edited', exact: true })).toBeVisible();
        await page.getByRole('button', { name: `Actions for ${name} edited`, exact: true }).click();
        await page.getByRole('menuitem', { name: 'Delete', exact: true }).click();
        await stack.getByRole('button', { name: 'Delete Token', exact: true }).click();
        await expect(stack).not.toBeVisible();
        await expect(page.getByRole('button', { name: name + ' edited', exact: true })).not.toBeVisible();
    });
}

test('switching clients ignores a late response and a failed config can be retried', async ({ page }) => {
    await page.goto('/cp/mcp');
    let release;
    const blocked = new Promise(resolve => { release = resolve; });
    await page.route('**/mcp/config/claude-code', async route => {
        await blocked;
        await route.fulfill({ json: { cli: 'wrong-client-command', config: {} } });
    });
    await page.getByRole('button', { name: 'Claude Code', exact: true }).click();
    await expect(page.getByText('Loading configuration…')).toBeVisible();
    await page.getByRole('button', { name: 'Claude Desktop', exact: true }).click();
    release();
    await expect(page.getByText('Loading configuration…')).not.toBeVisible();
    await expect(page.getByLabel('Terminal command')).not.toBeVisible();

    await page.route('**/mcp/config/cursor', route => route.fulfill({ status: 503, json: { message: 'Temporarily unavailable <b>plain text</b>' } }), { times: 1 });
    await page.getByRole('button', { name: 'Cursor', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Temporarily unavailable <b>plain text</b>');
    await page.getByRole('button', { name: 'Try again', exact: true }).click();
    await expect(page.getByRole('alert')).not.toBeVisible();
    await expect(page.getByText('Copy JSON', { exact: true })).toBeVisible();
    await expect(page.locator('pre')).toContainText('mcpServers');
});

test('activity distinguishes failures from empty results and uses server-side filters', async ({ page }) => {
    await page.route('**/mcp/audit?*', route => route.fulfill({ status: 503, json: { message: 'Activity unavailable' } }), { times: 1 });
    await page.goto('/cp/mcp/admin?tab=activity');
    await expect(page.getByRole('alert')).toContainText('Activity unavailable');
    await expect(page.getByText('No activity yet', { exact: true })).not.toBeVisible();
    await page.getByRole('button', { name: 'Try again', exact: true }).click();
    await expect(page.getByRole('alert')).not.toBeVisible();
    await page.getByRole('combobox', { name: 'Status', exact: true }).click();
    const filtered = page.waitForRequest(request => request.url().includes('/mcp/audit?') && new URL(request.url()).searchParams.get('status') === 'error');
    await page.getByRole('option', { name: 'Error', exact: true }).click();
    const url = new URL((await filtered).url());
    expect(url.searchParams.get('page')).toBe('1');
    await expect(page.getByText('Loading activity…')).not.toBeVisible();
});

test('token forms render validation as text, never HTML', async ({ page }) => {
    await page.goto('/cp/mcp?tab=tokens');
    await page.route('**/mcp/tokens', route => route.fulfill({ status: 422, json: { errors: { name: ['Invalid <img src=x onerror="window.uiXss=true">'] } } }));
    await page.getByRole('button', { name: 'Create Token', exact: true }).click();
    const stack = page.locator('.stack-content');
    await stack.getByRole('textbox', { name: /^Name/ }).fill('Validation test');
    await stack.getByRole('button', { name: 'Create Token', exact: true }).click();
    await expect(stack.locator('[data-ui-error-message]')).toHaveText('Invalid <img src=x onerror="window.uiXss=true">');
    await expect(stack.locator('[data-ui-error-message] img')).toHaveCount(0);
    expect(await page.evaluate(() => window.uiXss)).toBeUndefined();
    await stack.getByRole('button', { name: 'Cancel', exact: true }).click();
});

test('admin actions never offer another user or OAuth token regeneration', async ({ page }) => {
    await page.goto('/cp/mcp');
    await page.route('**/mcp/admin', async route => {
        const response = await route.fetch();
        const data = await response.json();
        data.props.allTokens = [
            { id: 'other', name: 'Other user', scopes: ['entries:read'], is_own: false, user_email: '<b>literal@example.test</b>' },
            { id: 'oauth', name: 'OAuth client', scopes: ['entries:read'], is_own: true, is_oauth: true },
            { id: 'own', name: 'Own token', scopes: ['entries:read'], is_own: true },
        ];
        await route.fulfill({ response, json: data });
    });
    await page.locator('a[href$="/mcp/admin"]').first().click();
    await expect(page.getByText('<b>literal@example.test</b>', { exact: true })).toBeVisible();
    for (const name of ['Other user', 'OAuth client']) {
        await page.getByRole('button', { name: `Actions for ${name}`, exact: true }).click();
        await expect(page.getByRole('menuitem', { name: 'Regenerate' })).not.toBeVisible();
        await page.keyboard.press('Escape');
    }
    await page.getByRole('button', { name: 'Actions for Own token', exact: true }).click();
    await expect(page.getByRole('menuitem', { name: 'Regenerate' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Delete', exact: true }).click();
    await page.route('**/mcp/tokens/own', route => route.fulfill({ status: 503, json: { message: 'Deletion unavailable <b>try later</b>' } }));
    const stack = page.locator('.stack-content');
    await stack.getByRole('button', { name: 'Delete Token', exact: true }).click();
    await expect(stack.getByRole('alert').last()).toHaveText('Deletion unavailable <b>try later</b>');
    await expect(stack.getByRole('button', { name: 'Delete Token', exact: true })).toBeEnabled();
    await expect(page.getByRole('button', { name: 'Own token', exact: true })).toBeVisible();
});

test('approval cancellation sends no decision and network failure remains visible', async ({ page }) => {
    await page.goto('/cp/mcp');
    await page.route('**/mcp/approvals', async route => {
        const response = await route.fetch();
        const data = await response.json();
        data.props.approvals = [{
            id: 'browser-approval', requested_at: 1795698000, expires_at: 1795701600,
            preview: { description: 'Delete the draft page', tool: 'statamic-entries-write', site: 'default', arguments: { action: 'delete', collection: 'pages', id: 'draft' } },
        }];
        data.props.elevatedSessions = true;
        await route.fulfill({ response, json: data });
    });
    await page.route('**/elevated-session', route => route.fulfill({ json: { elevated: false, method: 'password_confirmation' } }));
    let decisions = 0;
    await page.route('**/mcp/approvals/browser-approval/*', route => {
        decisions++;
        return route.abort('failed');
    });
    await page.locator('a[href$="/mcp/approvals"]').first().click();
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    const elevation = page.getByRole('dialog', { name: 'Confirm Your Password' });
    await expect(elevation).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(elevation).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Reject', exact: true })).toBeEnabled();
    expect(decisions).toBe(0);
    await page.unroute('**/elevated-session');
    await page.route('**/elevated-session', route => route.abort('failed'));
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Could not confirm your identity');
    expect(decisions).toBe(0);
    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Reload to check its status');
    expect(decisions).toBe(1);
    await expect(page.getByText('Delete the draft page', { exact: true })).toBeVisible();
    await page.unroute('**/mcp/approvals/browser-approval/*');
    await page.route('**/mcp/approvals/browser-approval/*', route => route.fulfill({ status: 409, json: { message: 'This approval is stale.' } }));
    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('This approval is stale.');
    await expect(page.getByText('Nothing waiting for you', { exact: true })).toBeVisible();
});
