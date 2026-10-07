import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Посещаемость и оклад — только у менеджеров и РОП.
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
test('исполнитель и CEO не отмечают приход; менеджер — отмечает', async ({ browser }) => {
  const smm = await login(browser, 'smm@fluggi.demo');
  await expect(smm.getByText('Мои задачи')).toBeVisible();
  await expect(smm.getByRole('button', { name: /Отметить приход|Отметить уход/ })).toHaveCount(0);
  await expect(smm.getByRole('link', { name: 'Посещаемость' })).toHaveCount(0);

  const pm = await login(browser, 'pm@fluggi.demo');
  await expect(pm.getByRole('button', { name: /Отметить приход|Отметить уход/ })).toHaveCount(0);

  const ceo = await login(browser, 'ceo@fluggi.demo');
  await expect(ceo.getByRole('button', { name: /Отметить приход|Отметить уход/ })).toHaveCount(0);

  const manager = await login(browser, 'manager2@fluggi.demo');
  await expect(
    manager
      .getByRole('button', { name: /Отметить уход|Отметить приход/ })
      .or(manager.getByText(/Уход \d{2}:\d{2}/))
      .first(),
  ).toBeVisible();
  await shot(manager, 'p16-manager-attendance');
});

test('зарплата: у исполнителя нет оклада, есть «Сдельно»', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/finance/payroll');
  await ceo.getByRole('button', { name: 'Рассчитать месяц' }).click();
  await expect(ceo.getByText('Зарплата рассчитана')).toBeVisible();
  await expect(ceo.getByRole('columnheader', { name: 'Сдельно' })).toBeVisible();
  await shot(ceo, 'p16-payroll');
});
