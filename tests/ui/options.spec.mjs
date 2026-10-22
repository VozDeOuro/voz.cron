import { test, expect, openList } from './fixtures.mjs';

async function openOptions(page, id) {
  const row = page.locator(`.us-row[data-id="${id}"]`);
  await row.locator('[data-act="menu"]').click();
  await row.locator('[data-act="options"]').click();
  await expect(page.locator('#us-drawer-title')).toHaveText(new RegExp(`^Run options: `));
}

test('exit-code editor validates, previews and saves', async ({ page }) => {
  await openList(page);
  await openOptions(page, 'refresh-ddns');
  await expect(page.locator('#us-xc-rows .us-xc-row')).toHaveCount(2); // the default 0=ok *=fail
  await page.click('#us-xc-add');
  const added = page.locator('#us-xc-rows .us-xc-row').nth(1); // inserted before the "*" rule
  await added.locator('.us-xc-codes').fill('1,3-5');
  await added.locator('.us-xc-out').selectOption('warn');
  await page.fill('#us-xc-try', '4');
  await expect(page.locator('#us-xc-preview')).toHaveText('exit 4 → warn');
  await page.click('#us-xc-mode');
  await expect(page.locator('#us-xc-text')).toHaveValue('0=ok 1,3-5=warn *=fail');
  await page.fill('#us-xc-text', '0=ok 300=warn');
  await expect(page.locator('#us-xc-err')).toContainText('300');
  await page.fill('#us-xc-text', '0=ok 1,3-5=warn *=fail');
  await expect(page.locator('#us-xc-err')).toBeHidden();
  await page.click('#us-save');
  await expect(page.locator('#us-toast')).toContainText('Run options saved');
  await openOptions(page, 'refresh-ddns');
  await page.click('#us-xc-mode');
  await expect(page.locator('#us-xc-text')).toHaveValue('0=ok 1,3-5=warn *=fail');
});

test('send test notification', async ({ page, request }) => {
  await openList(page);
  await openOptions(page, 'refresh-ddns');
  await page.click('#us-test-notify');
  await expect(page.locator('#us-toast')).toContainText('Test notification sent');
  expect(await (await request.get('/__notified')).text()).toContain('refresh-ddns: test notification from VOZ_CRON');
});

test('a cron expression Unraid would misread is refused with the reason', async ({ page }) => {
  await openList(page);
  const row = page.locator('.us-row[data-id="ping-hosts"]');
  await row.locator('.us-cron').fill('0 0 * * 0-7');
  await expect(row.locator('.us-meta.err')).toContainText("weekday ranges can't end in 7");
  await expect(page.locator('#us-apply')).toBeDisabled();
});
