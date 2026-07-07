import { expect, test } from '@playwright/test';
import { createFixtureClient } from '@wedevelop/e2e';

const fixtures = createFixtureClient();

test.afterEach(async ({ request }) => {
  await fixtures.reset(request);
});

test('loads a fixture over HTTP and returns the created page id', async ({ request }) => {
  const result = await fixtures.load(request, 'demo-home');

  expect(result.pageId).toBeGreaterThan(0);
  expect(result.pageUrl).toContain('e2e-home');
  expect(result.fixtureMap.Page).toBeDefined();
});

test('navigates to the loaded page in the CMS', async ({ page }) => {
  const result = await fixtures.loadAndNavigate(page, 'demo-home');

  await expect(page).toHaveURL(new RegExp(`/admin/pages/edit/show/${result.pageId}`));
  await expect(page.getByText('E2E Home').first()).toBeVisible();
});

test('loads all configured fixtures over HTTP', async ({ request }) => {
  const results = await fixtures.loadAll(request);

  expect(results['demo-home']).toBeDefined();
  expect(results['demo-home'].pageId).toBeGreaterThan(0);
  expect(results['demo-home'].pageUrl).toContain('e2e-home');
});
