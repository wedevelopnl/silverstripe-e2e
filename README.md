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

## Runtime fixtures for E2E tests
The module ships a dev-gated HTTP endpoint that lets a Playwright suite seed the
database from a named YAML fixture before each test. On request it loads the
fixture through SilverStripe's `FixtureFactory`, applies any versioned
post-actions declared in the fixture, and returns the created record IDs as JSON
so specs can navigate straight to the records they need. State is reset between
requests, so every test starts from a known baseline.

The endpoint, the `FixtureLoader`, and the Playwright client are all provided by
this module. A consuming project only supplies its own fixtures and a small
dev-only configuration block.

### Registering fixtures
Register your fixtures, the classes E2E owns, and the page classes the loader
prefers when resolving where a load should navigate, in a dev-only config
fragment (`Only: environment: dev`) so the endpoint is never active outside
development/CI:

```yaml
---
Name: app-e2e-fixtures
Only:
  environment: dev
---
WeDevelop\E2e\Fixtures\FixtureLoader:
  purge_classes:
    - Page
    - My\Module\Model\Element
    - My\Module\Model\SharedBlock
  fixture_page_classes:
    - Page
  fixtures:
    my-fixture: 'my-vendor/my-module:tests/E2E/Fixture/my-fixture.yml'
```

`fixtures` maps the name used by the Playwright client to a module-relative path
of the fixture YAML. `fixture_page_classes` is a preference list: the loader
resolves the page to navigate to by walking these classes in order, falling back
to the first `SiteTree` subclass the fixture created. `purge_classes` declares
what a reset removes, and is explained next.

### What a reset deletes
`reset()` — run before every load, and exposed on its own endpoint — deletes
**every record of the configured `purge_classes`, and of their subclasses, on
both stages**. Ownership is declared, not inferred: nothing keys off a URL
segment, a naming convention, or which records the fixture happened to write.

That is what makes the reset complete. A run leaves behind more than the fixture
created — a library record with no page and no URL, a record a spec produced by
clicking through the CMS, debris from a run that crashed halfway. None of those
are reachable from the fixture's own records, and all of them are records of a
purged class.

The consequence is the constraint: **the E2E database must be one nobody minds
losing.** Point the suite at a database you also develop in and a reset takes
your own content in those classes with it.

Two things to get right when declaring the scope:

- **List base classes.** Subclasses come along, so one entry usually covers a
  family — and `Page` also purges an `ErrorPage`, which `dev/build` restores.
- **Never list identity or configuration classes.** Purging `Member` takes the
  admin account the Playwright session logs in with, and every later run fails
  to authenticate. The module does not police the list; it deletes what you
  declare.

`purge_classes` is empty by default and `reset()` throws while it is, so the
loader can never wipe data nobody put in its charge.

#### Upgrading from 0.1.x
Earlier versions archived pages whose `URLSegment` started with `e2e-` and whose
ClassName was listed in `fixture_page_classes`. Both `url_segment_prefix` and
that role of `fixture_page_classes` are gone: declare `purge_classes` instead.
`fixture_page_classes` keeps only its navigation role, and fixture YAML no longer
needs the `e2e-` URLSegment convention.

### Config overrides during load
The most common write-time customisation is suppressing a side effect whose
trigger is a config static — e.g. a container model that auto-scaffolds children
in `onAfterWrite()` and would duplicate children the fixture declares itself.
For that case declare the statics to force per class; each is applied only while
the record is written and reverts immediately afterwards (it never leaks into
normal app code):

```yaml
WeDevelop\E2e\Fixtures\FixtureLoader:
  config_overrides:
    My\Module\Model\Section:
      auto_scaffold: false
    My\Module\Model\Row:
      auto_scaffold: false
```

Reach for an `onBeforeLoad` extension (below) only when a static value can't
express it — dynamic values or non-config side effects.

### Extension hooks
To inject domain behavior around a load — for example computing dynamic values or
publishing extra records — add an `Extension` implementing either hook and wire
it via `WeDevelop\E2e\Fixtures\FixtureLoader.extensions`:

```php
public function onBeforeLoad(string $name, FixtureFactory $factory): void
{
    // Prepare state or the factory before the fixture is loaded.
}

public function onAfterLoad(FixtureResult $result): void
{
    // React to the loaded records (IDs, page id) after the fixture is applied.
}
```

```yaml
WeDevelop\E2e\Fixtures\FixtureLoader:
  extensions:
    - My\Module\E2e\MyFixtureExtension
```

### The endpoint
When installed, the module registers the `/dev/e2e-fixtures` endpoint
automatically (dev-only, via `SilverStripe\Dev\DevelopmentAdmin`) and relaxes
`SilverStripe\Control\Session.strict_user_agent_check` so the Playwright browser
context can drive it. Both are scoped to `environment: dev`.

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

Specs then `import { createFixtureClient, authenticateAdmin } from '@wedevelop/e2e'`.
See [`client/playwright/README.md`](client/playwright/README.md) for the full
client API and setup examples.

### Running the E2E suite
With the module's Docker services running, run the Playwright suite with:

`task test-e2e`

The task ensures the services are up first, then runs `npx playwright test`
against the served app.
