# Playwright fixture client

Reusable Playwright helpers for the `wedevelopnl/silverstripe-e2e` fixture endpoint.

## Requirements

The client ships only as a source file inside the Composer package
(`vendor/wedevelopnl/silverstripe-e2e/client/playwright/index.ts`) — it is not
published to npm. The machine that runs Playwright must therefore have the
module's Composer dependencies installed (i.e. `vendor/` present), even when the
application's PHP otherwise runs entirely inside Docker. In CI, run `composer
install` on the Playwright runner (or mount the container's `vendor/`) before
`playwright test`.

## Usage in a consuming project

Add a path alias in the project's `tsconfig.json` (or `tests/E2E/tsconfig.json`):

```jsonc
{
  "compilerOptions": {
    "paths": {
      "@wedevelop/e2e": ["vendor/wedevelopnl/silverstripe-e2e/client/playwright/index.ts"]
    }
  }
}
```

In `global.setup.ts`:

```ts
import { test as setup } from '@playwright/test';
import { authenticateAdmin } from '@wedevelop/e2e';

setup('authenticate as admin', async ({ page }) => {
  await authenticateAdmin(page, { storageStatePath: 'tests/E2E/.auth/admin.json' });
});
```

In specs:

```ts
import { createFixtureClient } from '@wedevelop/e2e';

const fixtures = createFixtureClient(); // defaults to /dev/e2e-fixtures

test('...', async ({ page }) => {
  // `request` defaults to `page.request` (shares the page's auth cookies); pass
  // an explicit APIRequestContext as the third argument to override.
  const { pageId } = await fixtures.loadAndNavigate(page, 'my-fixture');
  // ...
});
```

`loadAndNavigate` is a convenience wrapper for the common case of editing the
loaded record on the CMS pages screen (`/admin/pages/edit/show/{pageId}`). To
navigate elsewhere, compose the pieces directly:

```ts
const { pageId } = await fixtures.load(request, 'my-fixture');
await page.goto(`/admin/some-other-section/${pageId}`);
```

The `/dev/e2e-fixtures` endpoint and the `strict_user_agent_check` relaxation are
provided automatically by the module's dev-only config when installed. Register your
fixtures and `fixture_page_classes` in your project's own dev config:

```yaml
---
Name: app-e2e-fixtures
Only:
  environment: dev
---
WeDevelop\E2e\Fixtures\FixtureLoader:
  fixture_page_classes:
    - Page
  fixtures:
    my-fixture: 'my-vendor/my-module:tests/E2E/Fixture/my-fixture.yml'
```

To inject behavior around a load (e.g. suppressing model auto-scaffolding), add an
Extension implementing `onBeforeLoad(string $name, FixtureFactory $factory)` /
`onAfterLoad(FixtureResult $result)` and wire it via
`WeDevelop\E2e\Fixtures\FixtureLoader.extensions`.
