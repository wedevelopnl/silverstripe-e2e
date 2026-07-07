# Playwright fixture client

Reusable Playwright helpers for the `wedevelopnl/silverstripe-e2e` fixture endpoint.

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

test('...', async ({ page, request }) => {
  const { pageId } = await fixtures.loadAndNavigate(page, request, 'my-fixture');
  // ...
});
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
