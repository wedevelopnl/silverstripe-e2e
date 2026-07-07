import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';

function resolveBaseUrl(): string {
  if (process.env.E2E_BASE_URL) {
    return process.env.E2E_BASE_URL;
  }
  const env = readFileSync('.docker/.env', 'utf-8');
  const match = env.match(/^WEB_PORT=(\d+)$/m);
  if (!match) {
    throw new Error('WEB_PORT not found in .docker/.env; run `task docker-env` first');
  }
  return `https://localhost:${match[1]}`;
}

export default defineConfig({
  testDir: './tests/E2E/specs',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: 'html',
  use: {
    baseURL: resolveBaseUrl(),
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'setup-chromium',
      testDir: './tests/E2E',
      testMatch: /global\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: 'tests/E2E/.auth/admin.json' },
      dependencies: ['setup-chromium'],
    },
    ...(process.env.CI
      ? [
          {
            name: 'setup-firefox',
            testDir: './tests/E2E',
            testMatch: /global\.setup\.ts/,
            use: { ...devices['Desktop Firefox'] },
          },
          {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'], storageState: 'tests/E2E/.auth/admin.json' },
            dependencies: ['setup-firefox'],
          },
        ]
      : []),
  ],
});
