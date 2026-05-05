const { chromium } = require('@playwright/test');
const path = require('path');

const authFile = path.join(__dirname, '.auth', 'user.json');

module.exports = async function globalSetup(config) {
    const baseURL = config.projects[0]?.use?.baseURL || process.env.APP_URL || 'http://localhost:8787';
    const email = process.env.TEST_EMAIL || 'test@example.com';
    const password = process.env.TEST_PASSWORD || 'password';

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL, ignoreHTTPSErrors: true });

    await page.goto('/cp/auth/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.getByRole('button', { name: /continue|sign in|log in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/auth/'), { timeout: 30000 });
    await page.waitForLoadState('networkidle');

    await page.context().storageState({ path: authFile });
    await browser.close();
};
