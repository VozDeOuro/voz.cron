import { defineConfig } from '@playwright/test';

// One worker: every test resets the single harness server's fixture folder.
export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.mjs$/,
  outputDir: '/tmp/pw-results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: { baseURL: process.env.BASE_URL || 'http://us-ui-php:8080', timezoneId: 'Asia/Tokyo', locale: 'en-US' },
  projects: [
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
    { name: 'phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, hasTouch: true } },
  ],
});
