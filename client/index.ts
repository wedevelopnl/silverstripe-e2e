import type { APIRequestContext, Page } from '@playwright/test';

export interface FixtureMap {
  [className: string]: { [identifier: string]: number };
}

export interface FixtureLoadResponse {
  pageId: number;
  pageUrl: string;
  fixtureMap: FixtureMap;
}

interface LoadEnvelope {
  success: boolean;
  error?: string;
  data: FixtureLoadResponse;
}

export interface FixtureClientOptions {
  /** Dev endpoint the controller is registered at. Defaults to `/dev/e2e-fixtures`. */
  endpoint?: string;
  /**
   * Optional locator that is visible while a CMS editor is loading. When set,
   * `loadAndNavigate` waits for it to become hidden after navigation.
   */
  editorReadySelector?: string;
}

export function createFixtureClient(options: FixtureClientOptions = {}) {
  const endpoint = options.endpoint ?? '/dev/e2e-fixtures';
  const editorReadySelector = options.editorReadySelector ?? null;

  async function load(request: APIRequestContext, fixture: string): Promise<FixtureLoadResponse> {
    const response = await request.post(`${endpoint}/load`, { form: { fixture } });
    if (!response.ok()) {
      throw new Error(`Fixture "${fixture}" load failed (${response.status()}): ${await response.text()}`);
    }

    const body = (await response.json()) as LoadEnvelope;
    if (!body.success) {
      throw new Error(`Fixture "${fixture}" load failed: ${body.error ?? 'unknown error'}`);
    }

    return body.data;
  }

  async function reset(request: APIRequestContext): Promise<void> {
    const response = await request.post(`${endpoint}/reset?confirm=1`);
    if (!response.ok()) {
      throw new Error(`Fixture reset failed (${response.status()}): ${await response.text()}`);
    }
  }

  async function loadAndNavigate(
    page: Page,
    request: APIRequestContext,
    fixture: string,
  ): Promise<FixtureLoadResponse> {
    const result = await load(request, fixture);
    await page.goto(`/admin/pages/edit/show/${result.pageId}`);
    if (editorReadySelector !== null) {
      await page.locator(editorReadySelector).waitFor({ state: 'hidden' });
    }

    return result;
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
