module.exports = {
    testDir: './tests',
    timeout: 30000,
    retries: 1,
    use: {
        baseURL: process.env.APP_URL || 'http://localhost:8787',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
    },
};
