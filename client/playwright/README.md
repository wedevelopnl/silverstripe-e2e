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

test.afterEach(async ({ request }) => {
  await fixtures.reset(request);
});

test('...', async ({ page }) => {
  // `request` defaults to `page.request` (shares the page's auth cookies); pass
  // an explicit APIRequestContext as the fourth argument to override.
  const ids = await fixtures.loadAndNavigate(
    page,
    { files: ['app/tests/E2E/Fixture/pages.yml'], dirs: ['app/tests/E2E/Fixture/blocks'] },
    'Page.home',
  );
  // ...
});
```

| Function | Does |
|---|---|
| `load(request, paths)` | Clears what earlier loads put in, loads the files and directories in `paths` (`FixturePaths`: `{ files?, dirs? }`, absolute or relative to the project root) and returns the id of every record (`FixtureIds`: `{ 'Page.home': 12 }`) |
| `reset(request)` | Clears what the loads put in, plus the classes in silverstripe-fixtures' `reset_classes` |
| `loadAndNavigate(page, paths, record, request?)` | Loads `paths` and opens the CMS pages edit screen of `record`, such as `'Page.home'`; throws when `record` was not loaded |

Every failure throws an `Error` reading `Fixture load failed (<status>): <error>`
(or `Fixture reset failed …`), followed by one `- <problem>` line per problem,
such as `- ../secrets.yml: outside the project`.

`loadAndNavigate` is a convenience wrapper for the common case of editing the
loaded record on the CMS pages screen (`/admin/pages/edit/show/{id}`). To
navigate elsewhere, compose the pieces directly:

```ts
const ids = await fixtures.load(request, { files: ['app/tests/E2E/Fixture/products.yml'] });
await page.goto(`/admin/products/${ids['App\\Model\\Product.shirt']}`);
```

The `/dev/e2e-fixtures` endpoint and the `strict_user_agent_check` relaxation are
provided automatically by the module's dev-only config when installed. See the
module README for the fixture format, the path rules, what a reset clears and the
upgrade notes from 0.2.x.
