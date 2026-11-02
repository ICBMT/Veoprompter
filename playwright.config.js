import { defineConfig } from 'playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    workers: 1,
    timeout: 90_000,
    reporter: 'list',
    use: {
        baseURL: process.env.BROWSER_BASE_URL || 'http://127.0.0.1:8000',
        viewport: { width: 1280, height: 720 },
        launchOptions: {
            executablePath: process.env.BROWSER_EXECUTABLE || undefined,
            args: JSON.parse(process.env.BROWSER_ARGS || '[]'),
        },
    },
});
