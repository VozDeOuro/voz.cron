import { test, expect, openList } from './fixtures.mjs';

test('search matches name and description, case-insensitive', async ({ page }) => {
  await openList(page);
  await page.fill('#us-search', 'WEEKLY BACKUP');
  await expect(page.locator('.us-row')).toHaveCount(1);
  await expect(page.locator('.us-row')).toHaveAttribute('data-id', 'nightly-backup');
});

test('a match inside a collapsed folder shows the folder open while searching', async ({ page }) => {
  await openList(page);
  await page.click('.us-folder[data-folder-id="f-backups"] .us-chev');
  await expect(page.locator('.us-row[data-id="db-dump"]')).toHaveCount(0);
  await page.fill('#us-search', 'db-dump');
  await expect(page.locator('.us-row[data-id="db-dump"]')).toBeVisible();
  await page.fill('#us-search', '');
  await expect(page.locator('.us-row[data-id="db-dump"]')).toHaveCount(0);
});

test('chips combine with the search text; an empty result can be cleared', async ({ page }) => {
  await openList(page);
  await page.click('.us-chip[data-filter="scheduled"]');
  await expect(page.locator('.us-row')).toHaveCount(4);
  await page.click('.us-chip[data-filter="failing"]');
  await expect(page.locator('.us-row')).toHaveCount(1);
  await page.fill('#us-search', 'refresh-dd');
  await expect(page.locator('.us-row')).toHaveCount(0);
  await expect(page.locator('#us-empty')).toBeVisible();
  await page.click('#us-clear-filters');
  await expect(page.locator('#us-search')).toHaveValue('');
  await expect(page.locator('.us-chip[data-filter="all"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('.us-row')).toHaveCount(7);
});

test('keyboard shortcuts', async ({ page }) => {
  await openList(page);
  await page.keyboard.press('/');
  await expect(page.locator('#us-search')).toBeFocused();
  await page.keyboard.type('refresh-dd');
  await page.keyboard.press('Escape');
  await expect(page.locator('#us-search')).toHaveValue('');
  await page.locator('#us-search').blur();
  const items = page.locator('#us-list [data-key]');
  await page.keyboard.press('j');
  await expect(items.nth(0)).toBeFocused();
  await page.keyboard.press('j');
  await expect(items.nth(1)).toBeFocused();
  await page.keyboard.press('k');
  await expect(items.nth(0)).toBeFocused();
  await page.keyboard.press('?');
  await expect(page.locator('#us-drawer-title')).toHaveText('Keyboard shortcuts');
  await page.keyboard.press('Escape');
  await expect(page.locator('#us-drawer')).toBeHidden();
  await page.locator('.us-row[data-id="refresh-ddns"]').focus();
  await page.keyboard.press('e');
  await expect(page.locator('#us-drawer-title')).toHaveText('Edit: refresh-ddns');
});
