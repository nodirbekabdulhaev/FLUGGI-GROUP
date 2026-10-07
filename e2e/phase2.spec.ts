import { expect, test, type Page } from '@playwright/test';

/**
 * Phase 2 — сценарий приёмки §84, шаги 3–8:
 * менеджер создаёт лид → встреча → РОП получает уведомление → встреча проведена →
 * квалификация → клиент + сделка → сделка в воронке.
 */
const PASSWORD = process.env.SEED_DEMO_PASSWORD ?? 'DemoPass2026';

async function login(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Пароль').fill(PASSWORD);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/dashboard/);
}

test('лид → встреча → уведомление РОП → квалификация → сделка', async ({
  page,
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'полный сценарий — на desktop');
  const company = `E2E Company ${Date.now()}`;

  await login(page, 'manager1@fluggi.demo');
  await page.goto('/sales/leads');
  await page.getByRole('button', { name: 'Добавить лид' }).first().click();
  const form = page.getByRole('dialog');
  await form.getByLabel('Имя контакта').fill('E2E Контакт');
  await form.getByLabel('Компания').fill(company);
  await form.getByLabel('Телефон').fill('+998900000001');
  await form.getByLabel('Источник').selectOption({ label: 'Instagram' });
  await form.getByLabel('Услуга').selectOption({ label: 'SMM' });
  await form.getByLabel('Бюджет').fill('9000000');
  await form.getByRole('button', { name: 'Создать лид' }).click();
  await expect(page).toHaveURL(/\/sales\/leads\/[0-9a-f-]{36}$/);
  await expect(page.getByRole('heading', { name: `${company} — SMM` })).toBeVisible();

  // Встреча → лид автоматически на этапе «Назначена встреча»
  await page.getByRole('tab', { name: 'Встречи' }).click();
  await page.getByRole('button', { name: 'Назначить встречу' }).first().click();
  await page.getByRole('dialog').getByRole('button', { name: 'Назначить встречу' }).click();
  await expect(page.getByText('Встреча назначена')).toBeVisible();

  // РОП видит уведомление о встрече
  const ropContext = await browser.newContext();
  const rop = await ropContext.newPage();
  await login(rop, 'rop@fluggi.demo');
  // уведомление создаёт outbox-обработчик асинхронно — перезагружаем страницу до появления
  await expect(async () => {
    await rop.goto('/notifications');
    await expect(rop.getByText(new RegExp(`${company}`)).first()).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
  await ropContext.close();

  // Встреча проведена → квалификация
  await page.getByRole('button', { name: 'Отметить проведённой' }).first().click();
  await page.getByRole('dialog').getByLabel('Результат').fill('Нужен SMM на 3 месяца');
  await page.getByRole('dialog').getByRole('button', { name: 'Отметить проведённой' }).click();
  await expect(page.getByText('Встреча проведена').first()).toBeVisible();

  await page.getByRole('button', { name: 'Квалифицировать' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Квалифицировать' }).click();
  await expect(page).toHaveURL(/\/sales\/deals\/[0-9a-f-]{36}$/);
  await expect(page.getByText('9 000 000 UZS').first()).toBeVisible();

  // Сделка в воронке, на этапе «Потребность определена»
  await page.goto('/sales/pipeline');
  await expect(page.getByRole('link', { name: `SMM — ${company}` })).toBeVisible();

  // Закрытие без причины невозможно
  await page.getByRole('link', { name: `SMM — ${company}` }).click();
  await page.getByRole('button', { name: 'Действия' }).click();
  await page.getByRole('menuitem', { name: 'Закрыть' }).click();
  await expect(
    page.getByRole('dialog').getByRole('button', { name: 'Закрыть', exact: true }).last(),
  ).toBeDisabled();
});

test('исполнитель не видит продажи', async ({ page }) => {
  await login(page, 'designer@fluggi.demo');
  await page.goto('/sales/leads');
  await expect(page.getByText('Нет доступа')).toBeVisible();
  expect(await page.evaluate(async () => (await fetch('/api/v1/leads')).status)).toBe(403);
});

test('новая сделка для существующего клиента со страницы «Сделки»', async ({ page, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page, 'manager1@fluggi.demo');
  await page.goto('/sales/deals');
  await page.getByRole('button', { name: 'Новая сделка' }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Клиент').selectOption({ index: 1 });
  await expect(dialog.getByLabel('Контакт').locator('option')).not.toHaveCount(1);
  await dialog.getByLabel('Название').fill('E2E повторная продажа');
  await dialog.getByLabel('Сумма').fill('5000000');
  await dialog.getByRole('button', { name: 'Создать сделку' }).click();
  await expect(page).toHaveURL(/\/sales\/deals\/[0-9a-f-]{36}$/);
  await expect(page.getByText('Повторная продажа').first()).toBeVisible();
  expect(errors).toEqual([]);
});
