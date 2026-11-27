import type { APIRequestContext, APIResponse, Page } from '@playwright/test';

/** The id of every record a load made, keyed `<Class as written in the fixture>.<identifier>`, in load order. */
export type FixtureIds = Record<string, number>;

/**
 * Fixture files and directories, absolute or relative to the project root. Files load first, then each
 * directory's `.yml`/`.yaml` files in name order, without those of its subdirectories.
 */
export interface FixturePaths {
  files?: string[];
  dirs?: string[];
}

interface Envelope {
  success: boolean;
  error?: string;
  problems?: string[];
  fixtures?: FixtureIds;
}

export interface FixtureClientOptions {
  /** Dev endpoint the controller is registered at. Defaults to `/dev/e2e-fixtures`. */
  endpoint?: string;
  /**
   * Optional locator that is visible while a CMS editor is loading. When set,
   * `loadAndNavigate` waits for it to become hidden after navigation.
   */
  editorReadySelector?: string;
  /**
   * Timeout (ms) for the `editorReadySelector` wait in `loadAndNavigate`. When
   * omitted, Playwright's default action timeout applies.
   */
  editorReadyTimeout?: number;
}

/**
 * Reads the response envelope, or throws `<action> failed (<status>): <error>` followed by one `- <problem>`
 * line per problem.
 */
async function readEnvelope(response: APIResponse, action: string): Promise<Envelope> {
  const text = await response.text();
  let body: Envelope | null = null;
  try {
    body = JSON.parse(text) as Envelope;
  } catch {
    // Not JSON, such as the framework's HTML 404 page when the route is missing: report the raw body below
  }

  if (response.ok() && body?.success === true) {
    return body;
  }

  // The error of an invalid fixture repeats its problems after the first line; they follow as a list of their own
  const error = body?.error?.split('\n')[0] ?? text;
  const problems = (body?.problems ?? []).map((problem) => `\n- ${problem}`).join('');
  throw new Error(`${action} failed (${response.status()}): ${error}${problems}`);
}

export function createFixtureClient(options: FixtureClientOptions = {}) {
  const endpoint = options.endpoint ?? '/dev/e2e-fixtures';
  const editorReadySelector = options.editorReadySelector ?? null;
  const editorReadyTimeout = options.editorReadyTimeout;

  /** Clears what earlier loads put in, then loads `paths` together: every file or none. */
  async function load(request: APIRequestContext, paths: FixturePaths): Promise<FixtureIds> {
    const form = new FormData();
    for (const file of paths.files ?? []) {
      form.append('file[]', file);
    }
    for (const dir of paths.dirs ?? []) {
      form.append('dir[]', dir);
    }

    const response = await request.post(`${endpoint}/load`, { form });
    const body = await readEnvelope(response, 'Fixture load');

    return body.fixtures ?? {};
  }

  async function reset(request: APIRequestContext): Promise<void> {
    const response = await request.post(`${endpoint}/reset?confirm=1`);
    await readEnvelope(response, 'Fixture reset');
  }

  /** Loads `paths` and opens the CMS edit screen of `record`, such as `Page.home`. */
  async function loadAndNavigate(
    page: Page,
    paths: FixturePaths,
    record: string,
    request: APIRequestContext = page.request,
  ): Promise<FixtureIds> {
    const ids = await load(request, paths);
    if (!Object.hasOwn(ids, record)) {
      throw new Error(`Record "${record}" was not loaded; loaded: ${Object.keys(ids).join(', ')}`);
    }

    await page.goto(`/admin/pages/edit/show/${ids[record]}`);
    if (editorReadySelector !== null) {
      await page.locator(editorReadySelector).waitFor({
        state: 'hidden',
        timeout: editorReadyTimeout,
      });
    }

    return ids;
  }

  return { load, reset, loadAndNavigate };
}

export interface AdminAuthOptions {
  storageStatePath: string;
  username?: string;
  password?: string;
  adminPath?: string;
}

/**
 * Log in as a CMS admin and persist the authenticated session to
 * `storageStatePath` for reuse across specs (call from a Playwright global
 * setup). Uses the SilverStripe login form's stable labels/roles. Navigation is
 * relative to the Playwright `baseURL`.
 */
export async function authenticateAdmin(page: Page, options: AdminAuthOptions): Promise<void> {
  const username = options.username ?? 'admin';
  const password = options.password ?? 'admin';
  const adminPath = options.adminPath ?? '/admin/';

  await page.goto(adminPath);
  await page.getByLabel('Email').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL(/\/admin\//);

  await page.context().storageState({ path: options.storageStatePath });
}
