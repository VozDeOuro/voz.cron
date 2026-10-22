import { test, expect, openList } from './fixtures.mjs';

for (const theme of ['white', 'black']) {
  test(`renders without horizontal scroll (${theme})`, async ({ page }) => {
    await openList(page, theme);
    const m = await page.evaluate(() => ({
      sw: document.documentElement.scrollWidth, w: innerWidth,
      off: [...document.querySelectorAll('#us-app button, #us-app select, #us-app input')]
        .filter((el) => { const b = el.getBoundingClientRect(); return b.width > 0 && b.right > innerWidth + 1; }).length,
    }));
    expect(m.sw).toBeLessThanOrEqual(m.w);
    expect(m.off).toBe(0);
    const bg = await page.locator('.us-row').first().evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(bg).toBe(theme === 'white' ? 'rgb(255, 255, 255)' : 'rgb(33, 33, 33)');
  });
}

test('schedule and action columns line up across rows', async ({ page }, info) => {
  test.skip(info.project.name === 'phone', 'phone stacks the cells');
  await openList(page);
  for (const cell of ['.us-sched', '.us-last', '.us-c-bg', '.us-c-log', '.us-c-menu']) {
    const xs = await page.locator(`.us-row ${cell}`).evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().left)));
    expect(new Set(xs).size, cell).toBe(1);
  }
});

test('times use the Tower timezone, not the browser one', async ({ page }) => {
  await openList(page); // browser: Asia/Tokyo; Tower fixture: America/Sao_Paulo (03:00 there is 15:00 in Tokyo)
  const meta = page.locator('.us-row[data-id="nightly-backup"] .us-sched .us-meta');
  await expect(meta).toContainText('Mon at 03:00');
  await expect(meta).toContainText(/next (today|\w{3} \d{1,2} \w{3}) 03:00/);
});

test('sparkline shows one colored tick per run, oldest first', async ({ page }) => {
  await openList(page);
  const ticks = page.locator('.us-row[data-id="nightly-backup"] .us-tick');
  await expect(ticks).toHaveCount(8);
  expect(await ticks.evaluateAll((els) => els.map((e) => e.className.replace('us-tick ', '')))).toEqual(['ok', 'ok', 'ok', 'ok', 'fail', 'warn', 'aborted', 'ignore']);
  const colors = await ticks.evaluateAll((els) => els.map((e) => getComputedStyle(e).backgroundColor));
  expect(colors[0]).toBe('rgb(46, 157, 88)');
  expect(colors[4]).toBe('rgb(226, 40, 40)');
  expect(colors[5]).toBe('rgb(217, 154, 30)');
  await page.locator('.us-row[data-id="nightly-backup"] .us-spark').focus();
  await expect(page.locator('#us-tip')).toBeVisible();
  await expect(page.locator('#us-tip')).toContainText('ignored, exit 3');
  await page.keyboard.press('ArrowLeft');
  await expect(page.locator('#us-tip')).toContainText('aborted, exit 137');
});

test('density toggle is remembered', async ({ page }) => {
  await openList(page);
  const row = page.locator('.us-row[data-id="nightly-backup"]');
  const before = (await row.boundingBox()).height;
  await page.click('#us-density');
  await expect(page.locator('#us-app')).toHaveAttribute('data-density', 'compact');
  expect((await row.boundingBox()).height).toBeLessThan(before);
  await page.reload();
  await page.locator('.us-row').first().waitFor();
  await expect(page.locator('#us-app')).toHaveAttribute('data-density', 'compact');
});
