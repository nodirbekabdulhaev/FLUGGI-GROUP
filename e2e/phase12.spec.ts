import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Направления бизнеса и проект-менеджер «Медиа», регулярные дела только у CEO.
 */
const PASSWORD = process.env.SEED_DEMO_PASSWORD ?? 'DemoPass2026';
const SHOTS = process.env.E2E_SCREENSHOTS;

async function login(browser: Browser, email: string): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  page.on('dialog', (d) => d.accept());
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Пароль').fill(PASSWORD);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/dashboard/);
  return page;
}

const shot = async (page: Page, name: string) => {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
};

test('проект-менеджер «Медиа»: дашборд и только медиа-проекты', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const pm = await login(browser, 'pm@fluggi.demo');
  await expect(pm.getByText(/^Проекты направления: Медиа/)).toBeVisible();
  await expect(pm.getByText('Без исполнителей')).toBeVisible();
  await shot(pm, 'p12-pm-dashboard');

  await pm.goto('/projects/all');
  await expect(pm.locator('table tbody tr').first()).toBeVisible();
  await expect(pm.locator('table tbody').getByText(/IT и разработка/)).toHaveCount(0);
  await expect(pm.locator('table tbody').getByText(/Медиа/).first()).toBeVisible();
  await shot(pm, 'p12-pm-projects');
});

test('CEO: направления в справочниках и у сотрудника', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/settings/references');
  await expect(ceo.getByText('Направления бизнеса')).toBeVisible();
  await expect(ceo.getByText('Медиа: SMM, брендинг, продакшн').first()).toBeVisible();

  await ceo.goto('/team/employees');
  await ceo.getByRole('button', { name: /Добавить сотрудника|Новый сотрудник/ }).click();
  await ceo.getByRole('dialog').getByLabel('Роль').selectOption('PROJECT_MANAGER');
  await expect(ceo.getByText('Направления', { exact: true })).toBeVisible();
  await expect(ceo.getByRole('checkbox', { name: 'Медиа: SMM, брендинг, продакшн' })).toBeVisible();
});

test('регулярные дела: налоговый календарь только у CEO', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/todos?view=recurring');
  await expect(manager.getByRole('button', { name: 'Новое правило' })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Налоговый календарь IT-Park' })).toHaveCount(0);

  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/todos?view=recurring');
  await expect(ceo.getByRole('button', { name: 'Налоговый календарь IT-Park' })).toBeVisible();
});
