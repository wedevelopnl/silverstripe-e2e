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

test('navigates to the loaded page in the CMS', async ({ page, request }) => {
  const result = await fixtures.loadAndNavigate(page, request, 'demo-home');

  await expect(page).toHaveURL(new RegExp(`/admin/pages/edit/show/${result.pageId}`));
  await expect(page.getByText('E2E Home').first()).toBeVisible();
});
