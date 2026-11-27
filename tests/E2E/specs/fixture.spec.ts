import { expect, test } from '@playwright/test';
import { createFixtureClient } from '@wedevelop/e2e';

// Relative to the harness project root, where this module is installed under vendor/.
const FIXTURES = 'vendor/wedevelopnl/silverstripe-e2e/tests/E2E/Fixture';

const fixtures = createFixtureClient();

test.afterEach(async ({ request }) => {
  await fixtures.reset(request);
});

test('loads a fixture file by path and returns the id of each record', async ({ request }) => {
  const ids = await fixtures.load(request, { files: [`${FIXTURES}/demo.yml`] });

  expect(Object.keys(ids)).toEqual(['Page.home']);
  expect(ids['Page.home']).toBeGreaterThan(0);
});

test('loads a folder of fixture files in name order', async ({ request }) => {
  const ids = await fixtures.load(request, { dirs: [FIXTURES] });

  expect(Object.keys(ids)).toEqual(['Page.about', 'Page.home']);
});

test('navigates to a loaded page in the CMS', async ({ page }) => {
  const ids = await fixtures.loadAndNavigate(page, { files: [`${FIXTURES}/demo.yml`] }, 'Page.home');

  await expect(page).toHaveURL(new RegExp(`/admin/pages/edit/show/${ids['Page.home']}`));
  await expect(page.getByText('E2E Home').first()).toBeVisible();
});

test('refuses to navigate to a record that was not loaded', async ({ page }) => {
  await expect(
    fixtures.loadAndNavigate(page, { files: [`${FIXTURES}/demo.yml`] }, 'Page.missing'),
  ).rejects.toThrow('Page.missing');
});

test('reports the status and raw body when the endpoint answers no JSON', async ({ request }) => {
  const misconfigured = createFixtureClient({ endpoint: '/dev/e2e-fixtures-typo' });

  await expect(misconfigured.load(request, { files: [`${FIXTURES}/demo.yml`] })).rejects.toThrow(
    /^Fixture load failed \(404\): <!DOCTYPE/i,
  );
  await expect(misconfigured.reset(request)).rejects.toThrow(/^Fixture reset failed \(404\): <!DOCTYPE/i);
});

test('refuses a path outside the project and names it', async ({ request }) => {
  await expect(fixtures.load(request, { files: ['/tmp/outside.yml', '../outside.yml'] })).rejects.toThrow(
    '- /tmp/outside.yml: outside the project\n- ../outside.yml: must not contain .. segments',
  );
});
