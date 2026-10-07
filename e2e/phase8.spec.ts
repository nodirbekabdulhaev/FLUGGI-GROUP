import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Phase 8 — аналитика, прогноз, LTV и здоровье клиентов, глобальный поиск, экспорт.
 */
const PASSWORD = process.env.SEED_DEMO_PASSWORD ?? 'DemoPass2026';
const SHOTS = process.env.E2E_SCREENSHOTS;

async function login(browser: Browser, email: string): Promise<Page> {
  const page = await (await browser.newContext({ acceptDownloads: true })).newPage();
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

test('аналитика: продажи, воронка, источники, прогноз, клиенты', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.getByRole('link', { name: 'Аналитика' }).first().click();
  await expect(ceo).toHaveURL(/\/analytics/);
  await expect(ceo.getByRole('heading', { name: 'Аналитика' })).toBeVisible();
  // Раздел больше не «в разработке»
  await expect(ceo.getByText('Раздел в разработке')).toHaveCount(0);

  // Продажи: показатели, график и таблица-дубль графика
  for (const label of ['Выручка', 'Оплачено', 'Оплаченные сделки', 'Лиды'])
    await expect(ceo.getByText(label, { exact: true }).first()).toBeVisible();
  await expect(ceo.getByRole('img', { name: 'Выручка, Оплачено' })).toBeVisible();
  await ceo.getByRole('button', { name: 'Показать таблицей' }).click();
  await expect(ceo.getByRole('columnheader', { name: 'Дата' })).toBeVisible();
  await shot(ceo, 'p8-sales');

  await ceo.getByRole('tab', { name: 'Воронка' }).click();
  await expect(ceo).toHaveURL(/tab=funnel/);
  await expect(ceo.getByText('Воронка продаж')).toBeVisible();
  await shot(ceo, 'p8-funnel');

  await ceo.getByRole('tab', { name: 'Источники' }).click();
  await expect(ceo.getByText('Выручка по источникам')).toBeVisible();

  await ceo.getByRole('tab', { name: 'Прогноз' }).click();
  await expect(ceo.getByText('Прогноз на 3 месяца')).toBeVisible();
  await expect(ceo.getByRole('columnheader', { name: 'Воронка × вероятность' })).toBeVisible();
  await shot(ceo, 'p8-forecast');

  await ceo.getByRole('tab', { name: 'Клиенты' }).click();
  await expect(ceo.getByText('LTV и здоровье клиентов')).toBeVisible();
  await expect(ceo.getByRole('columnheader', { name: 'LTV' })).toBeVisible();
  await shot(ceo, 'p8-clients');

  // Карточка клиента: LTV и здоровье
  await ceo.getByRole('table').getByRole('link').first().click();
  await expect(ceo).toHaveURL(/\/clients\//);
  await expect(ceo.getByTestId('client-insight')).toContainText('LTV');
  await expect(ceo.getByTestId('client-insight')).toContainText('Здоровье');

  // Исполнитель аналитику не видит
  const smm = await login(browser, 'smm@fluggi.demo');
  await expect(smm.getByRole('link', { name: 'Аналитика' })).toHaveCount(0);
});

test('глобальный поиск и экспорт', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');

  // ⌘K / Ctrl+K открывает поиск; Enter — переход к найденному
  await ceo.keyboard.press('Control+k');
  const input = ceo.getByRole('combobox');
  await expect(input).toBeVisible();
  await input.fill('Hamza');
  await expect(ceo.getByRole('option').first()).toContainText('Hamza Textile');
  await shot(ceo, 'p8-search');
  await input.press('Enter');
  await expect(ceo).toHaveURL(/\/clients\//);
  await expect(ceo.getByRole('heading', { name: 'Hamza Textile' })).toBeVisible();

  // Экспорт лидов в Excel
  await ceo.goto('/sales/leads');
  await ceo.getByRole('button', { name: 'Экспорт' }).click();
  const download = ceo.waitForEvent('download');
  await ceo.getByRole('menuitem', { name: 'Excel (.xlsx)' }).click();
  const file = await download;
  expect(file.suggestedFilename()).toMatch(/^fluggi-leads-\d{4}-\d{2}-\d{2}\.xlsx$/);

  // Менеджер не может выгружать (право «Экспорт» у CEO, РОП и HR)
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/sales/leads');
  await expect(manager.getByRole('heading', { name: 'Лиды' })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Экспорт' })).toHaveCount(0);
});

test('пакет для бухгалтера: реквизиты и скачивание Excel за год', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/settings/documents');
  await ceo.getByLabel('Налоговый режим').selectOption('IT_PARK');
  await ceo.getByLabel('Юридическое название').fill('ООО «Fluggi»');
  await ceo.getByRole('button', { name: 'Сохранить реквизиты' }).click();
  await expect(ceo.getByText('Реквизиты сохранены')).toBeVisible();

  await ceo.goto('/finance/revenue');
  await ceo.getByRole('button', { name: 'Пакет для бухгалтера' }).click();
  await expect(ceo.getByText('Расчёты с клиентами на 31.12')).toBeVisible();
  await shot(ceo, 'p8-accountant');
  const download = ceo.waitForEvent('download');
  await ceo.getByRole('link', { name: 'Скачать Excel' }).click();
  expect((await download).suggestedFilename()).toMatch(/^fluggi-buhgalter-\d{4}\.xlsx$/);

  // РОП финансы компании не видит вовсе
  const rop = await login(browser, 'rop@fluggi.demo');
  await rop.goto('/finance/revenue');
  await expect(rop.getByText('Нет доступа')).toBeVisible();
  await expect(rop.getByRole('button', { name: 'Пакет для бухгалтера' })).toHaveCount(0);
});
