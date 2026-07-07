import { test as setup } from '@playwright/test';
import { authenticateAdmin } from '@wedevelop/e2e';

setup('authenticate as admin', async ({ page }) => {
  await authenticateAdmin(page, { storageStatePath: 'tests/E2E/.auth/admin.json' });
});
