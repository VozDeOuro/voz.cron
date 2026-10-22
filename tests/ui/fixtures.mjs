import { test as base, expect } from '@playwright/test';

// Every test starts from fresh fixtures and fails on any console error or uncaught exception, unless the
// test declares the messages it expects with test.use({ allowedErrors: [/regex/] }).
export const test = base.extend({
  allowedErrors: [[], { option: true }],
  page: async ({ page, request, allowedErrors }, use) => {
    expect((await request.post('/__reset')).ok()).toBeTruthy();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await use(page);
    expect(errors.filter((t) => !allowedErrors.some((re) => re.test(t))), 'console errors').toEqual([]);
  },
});
export { expect };

export async function openList(page, theme = 'white') {
  await page.goto(`/Settings/VozCron?theme=${theme}`);
  await page.locator('.us-row').first().waitFor();
}

export const order = (page) => page.locator('#us-list > [data-key]').evaluateAll((els) => els.map((e) => e.dataset.key));
