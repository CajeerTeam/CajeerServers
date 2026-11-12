import { defineConfig, devices } from '@playwright/test'
export default defineConfig({
  testDir: './tests/e2e', timeout: 90_000, retries: 0, workers: 1, forbidOnly: !!process.env.CI,
  use: { baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:3000', trace: 'off', screenshot: 'off', video: 'off', ...devices['Desktop Chrome'] },
})
