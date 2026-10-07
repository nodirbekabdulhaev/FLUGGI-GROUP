import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Доработки: «Мой KPI» на дашборде, «Список дел» — только личные, «Мой отдел» у РОП,
 * статус Telegram на странице уведомлений, чат не пропадает после отправки.
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
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
};

test('дашборд менеджера: блок «Мой KPI» с выполнением и суммой', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  await expect(manager.getByText(/^Мой KPI за \d{4}-\d{2}$/)).toBeVisible();
  await expect(manager.getByText('KPI-бонус', { exact: true })).toBeVisible();
  await expect(manager.getByText('Ожидаемая выплата')).toBeVisible();
  await expect(manager.getByTestId('my-kpi-bonus')).toContainText('UZS');
  await shot(manager, 'p11-dashboard-kpi');
});

test('«Список дел» — только личные дела; у РОП вкладка «Мой отдел»', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const rop = await login(browser, 'rop@fluggi.demo');
  await rop.getByRole('button', { name: /^Список дел/ }).click();
  const dock = rop.getByRole('region', { name: 'Список дел' });
  await expect(dock.getByLabel('Фильтр')).toHaveCount(0);
  await expect(dock.locator('a[href^="/projects/"]')).toHaveCount(0);

  await rop.goto('/todos');
  await rop.getByRole('tab', { name: 'Мой отдел' }).click();
  await expect(rop).toHaveURL(/view=all/);
  await expect(rop.getByText('Нет доступа')).toHaveCount(0);

  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/todos');
  await expect(manager.getByRole('tab', { name: 'Мой отдел' })).toHaveCount(0);
  await expect(manager.getByRole('tab', { name: 'Все' })).toHaveCount(0);
});

test('уведомления: статус дублирования в Telegram вместо «появится в Phase 7»', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/notifications');
  await expect(manager.getByText(/Phase 7/)).toHaveCount(0);
  await expect(manager.getByText(/Telegram/).last()).toBeVisible();
});

test('чат остаётся на экране после отправки сообщений', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  const errors: string[] = [];
  manager.on('pageerror', (e) => errors.push(e.message));
  await manager.goto('/notifications');
  await manager.getByRole('button', { name: /^Открыть чат/ }).click();
  await manager.getByRole('button', { name: 'Новый чат' }).click();
  await manager
    .getByRole('region', { name: 'Чаты' })
    .getByRole('list')
    .getByRole('button')
    .first()
    .click();
  for (const n of [1, 2, 3]) {
    const text = `Проверка ${n} ${Date.now() % 100000}`;
    await manager.getByLabel('Сообщение').fill(text);
    await manager.getByRole('button', { name: 'Отправить' }).click();
    await expect(manager.getByTestId('chat-messages').getByText(text)).toBeVisible();
  }
  await expect(manager.getByLabel('Сообщение')).toBeVisible();
  expect(errors).toEqual([]);
});
