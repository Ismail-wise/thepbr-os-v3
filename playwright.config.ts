import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const target = new URL(baseURL);

if (!['127.0.0.1', 'localhost'].includes(target.hostname)) {
    throw new Error(
        'F1-A13 E2E_BASE_URL must target localhost/127.0.0.1 only.',
    );
}

export default defineConfig({
    testDir: './tests/E2E',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 30_000,
    expect: {
        timeout: 5_000,
    },
    reporter: process.env.CI
        ? [
              ['list'],
              [
                  'html',
                  {
                      open: 'never',
                      outputFolder: 'playwright-report',
                  },
              ],
          ]
        : [['list']],
    outputDir: 'test-results',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'chromium-mobile',
            use: {
                ...devices['Pixel 5'],
            },
        },
    ],
});
