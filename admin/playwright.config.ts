import { defineConfig, devices } from '@playwright/test'
export default defineConfig({
  testDir: './tests/e2e', timeout: 45_000, retries: 0, workers: 1, forbidOnly: !!process.env.CI,
  use: { baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:3000', trace: 'retain-on-failure', screenshot: 'only-on-failure', ...devices['Desktop Chrome'] },
})
