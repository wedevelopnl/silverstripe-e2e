# Silverstripe E2E
Helper module providing Silverstripe-side tooling to support end-to-end (E2E)
testing of a functional Silverstripe website inside a CI pipeline.

## Note!
This module may be useful across various CI configurations, so feel free to use
it where applicable. Note that it is designed specifically to run inside our
internal GitLab CI default configuration/setup.

We will **not** provide support or implement features that are not supported by
our configuration.

This is the initial Silverstripe 6 foundation of the module; further E2E helpers
will be added over time.

## Tools

### Generate TinyMCE configuration
Silverstripe generates its TinyMCE configuration on the fly when requested. When
running a separate webserver container as a service inside the GitLab CI, those
files are generated on the php-fpm service, preventing the webserver from
accessing them. This build task generates the TinyMCE bundle files up front so
they can be baked into the webserver container during the build stage.

Run the task without a database:

`vendor/bin/sake tasks:generate-tinymce-combined --no-database`

It is also available via the browser at `/dev/tasks/generate-tinymce-combined`.

## Test data for E2E tests
The module ships a dev-gated HTTP endpoint that lets a Playwright suite load
test data before each test and clear it afterwards. Loading and clearing are
done by [`wedevelopnl/silverstripe-fixtures`](https://github.com/wedevelopnl/silverstripe-fixtures),
which this module requires; the endpoint is the JSON layer the Playwright client
talks to.

A spec names fixture files and directories by their path in the project. Each
load first clears what earlier loads put in, then loads every named file in one
transaction: either all of them load, or none does and the earlier data stays.
The response holds the id of every record loaded, keyed by the class as written
in the fixture and the record's identifier, such as `Page.home`.

### Writing fixtures
Fixtures use the format of `wedevelopnl/silverstripe-fixtures`: the framework's
YAML, with `@published`, `@draft`, `@locales` and `@source` for published
records, draft changes, translations and files. Rows are inserted without
`write()`, so no write hook runs: **state every value a record needs**, such as a
page's `URLSegment` and `Sort`. See that module's README for the full format.

```yaml
Page:
  home:
    Title: Home
    URLSegment: home
    Sort: 1
    '@published': true
```

### Paths
- A path is absolute, or relative to the project root (`Director::baseFolder()`).
- Only paths inside the project root are loaded. The check works on the path's
  text: `.` and `..` are resolved, symlinks are not followed, so a module
  installed from a symlinked path repository counts as inside. A path that leads
  out of the root is refused with `<path>: outside the project`.
- A file must end in `.yml` or `.yaml`, in lower case.
- A directory contributes the `.yml` and `.yaml` files (lower-case extension)
  directly in it, in name order (byte order: digits before upper case before
  lower case); other files and subdirectories are skipped. A missing directory,
  or one without such files, refuses the load. These are the rules of
  silverstripe-fixtures' `fixtures` task (`--dir`), copied into this module.
- Files load first, in the order given, then each directory's files, in the
  order the directories were given. References resolve across all of them.
- Fixtures that ship inside a package are addressed through `vendor/`, such as
  `vendor/my-vendor/my-module/tests/E2E/Fixture/pages.yml`.

### Clearing test data
A reset removes every record loaded since the last reset, tracked in
silverstripe-fixtures' `FixtureLedger` table, plus every record of the classes in
its `reset_classes` (subclasses included). Records a spec creates by clicking
through the CMS are only removed when their class is listed:

```yaml
---
Name: app-e2e-fixtures
Only:
  environment: dev
---
WeDevelop\Fixtures\FixtureLoader:
  reset_classes:
    - Page
```

`reset_classes` is optional and empty by default. A listed class is emptied of
every record, not only test data, so the E2E database must then be one nobody
minds losing. Never list `Member`: the Playwright session logs in with it.

### The endpoint
When installed, the module registers the `/dev/e2e-fixtures` endpoint
automatically (dev-only, via `SilverStripe\Dev\DevelopmentAdmin`) and relaxes
`SilverStripe\Control\Session.strict_user_agent_check` so the Playwright browser
context can drive it. Both are scoped to `environment: dev`. The endpoint has no
authentication: the dev-only registration and the path rules above are its only
fence.

| Request | Success |
|---|---|
| `POST load` with form fields `file[]` and/or `dir[]` | 200 `{"success": true, "fixtures": {"Page.home": 12}}` |
| `POST reset?confirm=1` | 200 `{"success": true}` |

| Failure | Status | Body |
|---|---|---|
| no `file[]` and no `dir[]` | 400 | `{"success": false, "error": "Pass at least one file[] or dir[]"}` |
| a refused path, or a fixture with a mistake | 400 | `{"success": false, "error": "…", "problems": ["…"]}` |
| reset without `confirm=1` | 400 | `{"success": false, "error": "Missing confirm=1 query parameter"}` |
| any method but POST | 405 | `{"success": false, "error": "Use POST"}` |
| a database or other error | 500 | `{"success": false, "error": "…"}` |

Two loads at the same moment can collide on record ids. Run the suite with one
Playwright worker, as this module's own harness does, or make sure loads run one
after the other.

### TypeScript client
A reusable Playwright client lives at `client/playwright/index.ts`. Expose it to
your specs with a `paths` alias in the project's `tsconfig.json` (or
`tests/E2E/tsconfig.json`):

```jsonc
{
  "compilerOptions": {
    "paths": {
      "@wedevelop/e2e": ["vendor/wedevelopnl/silverstripe-e2e/client/playwright/index.ts"]
    }
  }
}
```

Specs then `import { createFixtureClient, authenticateAdmin } from '@wedevelop/e2e'`:

```ts
const fixtures = createFixtureClient();

test.afterEach(async ({ request }) => {
  await fixtures.reset(request);
});

test('edits the home page', async ({ page, request }) => {
  const ids = await fixtures.load(request, {
    files: ['app/tests/E2E/Fixture/pages.yml'],
    dirs: ['app/tests/E2E/Fixture/blocks'],
  });
  // ids['Page.home'] === 12
});
```

See [`client/playwright/README.md`](client/playwright/README.md) for the full
client API and setup examples.

### Running the E2E suite
With the module's Docker services running, run the Playwright suite with:

`task test-e2e`

The task ensures the services are up first, then runs `npx playwright test`
against the served app.

## Upgrading from 0.2.x
0.3.0 replaces the module's own fixture loader with
`wedevelopnl/silverstripe-fixtures`. It is a breaking release: a `^0.2` or
`~0.2.1` constraint does not pick it up, and pinning `~0.2.1` again rolls a
project back.

- Update with `composer update wedevelopnl/silverstripe-e2e --with-dependencies`
  and run `dev/build`, which adds silverstripe-fixtures' `FixtureLedger` table.
- A container that runs `composer install` against an old `composer.lock` keeps
  the old version: update the lock, or recreate the container (this module's own
  harness: `task destroy && task up`).
- Rewrite every fixture in the silverstripe-fixtures format, stating every value
  (see [Writing fixtures](#writing-fixtures)).
- Remove the `WeDevelop\E2e\Fixtures\FixtureLoader` config block.

Every feature of the old loader, and what replaces it:

| 0.2.x | 0.3.0 |
|---|---|
| `fixtures` registry of named fixtures | Gone. Specs name the files and directories: `fixtures.load(request, { files, dirs })` |
| `vendor/package:path.yml` notation | A path relative to the project root: `vendor/<vendor>/<package>/path.yml` |
| `fixture_page_classes` | Gone. `loadAndNavigate` takes the record to open, such as `'Page.home'` |
| `purge_classes` | silverstripe-fixtures' optional `reset_classes`, besides the ledger of loaded records. Records a spec creates in the CMS survive a reset unless their class is listed. An empty list no longer makes reset throw |
| `config_overrides` | Not needed: silverstripe-fixtures inserts rows without `write()`, so no write-time side effect fires |
| `onBeforeLoad` / `onAfterLoad` extension hooks | Nothing. State every value in the fixture |
| post-action `publish_recursive` | `'@published': true` on each record; owned records are not published along, so give each its own |
| post-action `unpublish` | A draft-only record (no `@published`, or `false`). The history "was published, then unpublished" cannot be expressed |
| post-action `modify` | `'@draft'` on a published record (silverstripe-fixtures 0.1.2) |
| post-actions `attach_image` / `attach_file` | A `File` or `Image` record with `@source` and a `Name`, linked with `=>Class.identifier`. No `Uploads/` default: declare a `Folder` as its `Parent`. No subclass chosen by extension: name the class in the fixture |
| `POST load` with `fixture=<name>` | `POST load` with `file[]` and/or `dir[]`; the flat `fixtures` map replaces `data` (`pageId`, `pageUrl`, `fixtureMap`) |
| `POST load-all` | Gone (404). Load a directory with `dir[]` |
| `GET reset` | POST only; any other method answers 405 |
| `load-e2e-fixtures` task | silverstripe-fixtures' `fixtures` task: `vendor/bin/sake tasks:fixtures --action=reset --dir=<dir>` |
| `WeDevelop\E2e\Fixtures\FixtureLoader` / `FixtureResult` in PHP tests | `WeDevelop\Fixtures\FixtureLoader` (`load()`, `reload()`, `reset()`) and `LoadedFixtures` (`idOf()`, `toArray()`) |
| Client `load(request, name)` / `FixtureLoadResponse` | `load(request, paths)` returning `FixtureIds`; the paths are a `FixturePaths` |
| Client `loadAndNavigate(page, name)` | `loadAndNavigate(page, paths, record)` |
| Client `loadAll` / `FixtureLoadAllResponse` | `load(request, { dirs: [...] })` |
| Client `FixtureMap` | `FixtureIds`: flat `Class.identifier → id` |
