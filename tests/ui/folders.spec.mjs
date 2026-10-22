import { test, expect, openList, order } from './fixtures.mjs';

test('create, rename, collapse and delete a folder', async ({ page }) => {
  await openList(page);
  page.once('dialog', (d) => d.accept('Media'));
  await page.click('#us-newfolder');
  const media = page.locator('.us-folder', { hasText: 'Media' });
  await expect(media).toBeVisible();
  await media.locator('[data-fact="menu"]').click();
  page.once('dialog', (d) => d.accept('Media stuff'));
  await media.locator('[data-fact="rename"]').click();
  await expect(page.locator('.us-folder .us-fname', { hasText: 'Media stuff' })).toBeVisible();

  const backups = page.locator('.us-folder[data-folder-id="f-backups"]');
  await backups.locator('.us-chev').click();
  await expect(page.locator('.us-row[data-id="nightly-backup"]')).toHaveCount(0);
  await page.reload();
  await page.locator('#us-list [data-key]').first().waitFor();
  await expect(page.locator('.us-row[data-id="nightly-backup"]')).toHaveCount(0); // collapsed state is remembered
  await backups.locator('.us-chev').click();

  await backups.locator('[data-fact="menu"]').click();
  page.once('dialog', (d) => d.accept());
  await backups.locator('[data-fact="delete"]').click();
  await expect(page.locator('.us-folder[data-folder-id="f-backups"]')).toHaveCount(0);
  await expect(page.locator('.us-row[data-id="nightly-backup"]')).toHaveAttribute('data-folder', '');
  await expect.poll(async () => (await order(page)).slice(0, 3)).toEqual([expect.stringMatching(/^f:/), 's:nightly-backup', 's:db-dump']);
  await page.reload();
  await page.locator('.us-row').first().waitFor();
  await expect(page.locator('.us-folder[data-folder-id="f-backups"]')).toHaveCount(0);
});

test('drag a script into a folder, reorder it, drag it out; the order survives a reload', async ({ page }, info) => {
  test.skip(info.project.name === 'phone', 'HTML5 drag needs a mouse; Move to… covers touch');
  await openList(page);
  const web = () => page.locator('.us-row[data-id="ping-hosts"]');
  const grip = () => web().locator('.us-grip');
  const backups = page.locator('.us-folder[data-folder-id="f-backups"]');
  const fb = await backups.boundingBox();
  await grip().dragTo(backups, { targetPosition: { x: 200, y: fb.height / 2 } });
  await expect(web()).toHaveAttribute('data-folder', 'f-backups');
  await grip().dragTo(page.locator('.us-row[data-id="nightly-backup"]'), { targetPosition: { x: 200, y: 3 } });
  const want = ['f:f-backups', 's:ping-hosts', 's:nightly-backup', 's:db-dump'];
  await expect.poll(async () => (await order(page)).slice(0, 4)).toEqual(want);
  await page.reload();
  await page.locator('.us-row').first().waitFor();
  expect((await order(page)).slice(0, 4)).toEqual(want);
  const last = page.locator('#us-list > [data-key]').last();
  const lb = await last.boundingBox();
  await grip().dragTo(last, { targetPosition: { x: 200, y: lb.height - 3 } });
  await expect(web()).toHaveAttribute('data-folder', '');
  await expect.poll(async () => (await order(page)).at(-1)).toBe('s:ping-hosts');
});

test('Move to… and Alt+arrows reorder without a mouse drag', async ({ page }) => {
  await openList(page);
  const cf = () => page.locator('.us-row[data-id="refresh-ddns"]');
  await cf().locator('[data-act="menu"]').click();
  await cf().locator('[data-act="moveto"]').click();
  await cf().locator('[data-act="move"][data-to="f-backups"]').click();
  await expect(cf()).toHaveAttribute('data-folder', 'f-backups');
  await expect.poll(async () => (await order(page)).slice(0, 4)).toEqual(['f:f-backups', 's:nightly-backup', 's:db-dump', 's:refresh-ddns']);
  await cf().focus();
  await page.keyboard.press('Alt+ArrowUp');
  await expect.poll(async () => (await order(page)).slice(0, 4)).toEqual(['f:f-backups', 's:nightly-backup', 's:refresh-ddns', 's:db-dump']);
  await expect(cf()).toBeFocused();
  await page.keyboard.press('Alt+ArrowDown');
  await page.keyboard.press('Alt+ArrowDown'); // past the folder's last script: steps out of the folder
  await expect(cf()).toHaveAttribute('data-folder', '');
  await page.reload();
  await page.locator('.us-row').first().waitFor();
  await expect(cf()).toHaveAttribute('data-folder', '');
});

test.describe('two tabs', () => {
  test.use({ allowedErrors: [/status of 409/] }); // the browser logs the refused save
  test('a stale folder change is refused and the stored layout is reloaded', async ({ page, request }) => {
    await openList(page);
    const other = await request.post('/plugins/voz.cron/api.php', {
      headers: { 'X-CSRF-Token': 'test-token' },
      data: { action: 'layout.save', version: 1, layout: { items: [{ type: 'folder', id: 'f-other', name: 'Other tab', icon: '', scripts: [] }] } },
    });
    expect((await other.json()).ok).toBe(true);
    page.once('dialog', (d) => d.accept('Mine'));
    await page.click('#us-newfolder');
    await expect(page.locator('#us-toast')).toContainText('changed elsewhere');
    await expect(page.locator('.us-folder .us-fname', { hasText: 'Other tab' })).toBeVisible();
    await expect(page.locator('.us-folder .us-fname', { hasText: 'Mine' })).toHaveCount(0);
  });
});

test('click-and-drag on a script name selects its text instead of moving the row', async ({ page }, info) => {
  test.skip(info.project.name === 'phone', 'text selection by mouse drag');
  await openList(page);
  const before = await order(page);
  const h = await page.locator('.us-row[data-id="ping-hosts"] h3').boundingBox();
  await page.mouse.move(h.x + 1, h.y + h.height / 2);
  await page.mouse.down();
  await page.mouse.move(h.x + h.width - 1, h.y + h.height / 2, { steps: 8 });
  await page.mouse.up();
  expect(await page.evaluate(() => String(getSelection()))).toBe('ping-hosts');
  expect(await page.locator('.us-dragging').count()).toBe(0);
  expect(await order(page)).toEqual(before);
});
