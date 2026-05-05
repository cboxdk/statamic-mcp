const path = require('path');

module.exports = {
    testDir: './tests',
    timeout: 30000,
    retries: 1,
    globalSetup: require.resolve('./global-setup'),
    use: {
        baseURL: process.env.APP_URL || 'http://localhost:8787',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        storageState: path.join(__dirname, '.auth', 'user.json'),
    },
};
