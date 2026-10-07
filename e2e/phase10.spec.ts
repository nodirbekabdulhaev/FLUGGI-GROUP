import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Тарифы и сдельные ставки, категории, прочие поступления, генерация КП и договора.
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
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
};

test('тарифы: CEO задаёт цену и работы исполнителей, видна маржа', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/settings/tariffs');
  await expect(ceo.getByText('Эконом').first()).toBeVisible();
  await expect(ceo.getByText('Маржа').first()).toBeVisible();

  const name = `Премиум ${Date.now() % 100000}`;
  await ceo.getByRole('button', { name: 'Добавить тариф' }).first().click();
  const dialog = ceo.getByRole('dialog');
  await dialog.getByLabel('Название тарифа').fill(name);
  await dialog.getByLabel('Цена продажи').fill('1500');
  await dialog.getByLabel('Валюта', { exact: true }).first().selectOption('USD');
  await dialog.getByRole('button', { name: 'Сдельно' }).click();
  await dialog.getByLabel('Работа').selectOption({ index: 1 });
  await dialog.getByLabel('Количество').fill('20');
  await dialog.getByRole('button', { name: 'Фиксированно' }).click();
  await dialog.getByLabel('Исполнитель').selectOption('DEVELOPER');
  await dialog.getByLabel('Сумма').fill('2000000');
  await dialog.getByRole('button', { name: 'Сохранить' }).click();
  await expect(ceo.getByText('Тариф сохранён')).toBeVisible();
  await expect(ceo.getByText(name)).toBeVisible();
  await shot(ceo, 'p10-tariffs');
});

test('категории расходов редактируются и доступны в расходе', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  const name = `Обучение ${Date.now() % 100000}`;
  await ceo.goto('/settings/finance');
  await ceo.getByRole('button', { name: 'Добавить' }).first().click();
  await ceo.getByRole('dialog').getByLabel('Название').fill(name);
  await ceo.getByRole('dialog').getByRole('button', { name: 'Сохранить' }).click();
  await expect(ceo.getByText('Сохранено')).toBeVisible();
  await expect(ceo.getByText(name)).toBeVisible();
  await expect(ceo.getByText('Распределение накладных')).toBeVisible();
  await shot(ceo, 'p10-finance-settings');

  await ceo.goto('/finance/expenses');
  await expect(ceo.getByLabel('Категория').locator('option', { hasText: name })).toHaveCount(1);
});

test('сдельные ставки в карточке сотрудника и прочие поступления', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/team/employees');
  await ceo.getByLabel('Поиск', { exact: true }).fill('Тимур');
  const row = ceo.getByRole('row', { name: /Тимур Саидов/ });
  await row.getByRole('button', { name: 'Действия' }).click();
  await ceo.getByRole('menuitem', { name: 'Сдельные ставки' }).click();
  const dialog = ceo.getByRole('dialog');
  await expect(dialog.getByText('Рилс').first()).toBeVisible();
  await dialog.getByLabel('Личная ставка: Рилс (съёмка)', { exact: true }).fill('12');
  await dialog.getByLabel('Валюта').first().selectOption('USD');
  await dialog.getByRole('button', { name: 'Сохранить' }).click();
  await expect(ceo.getByText('Ставки сохранены')).toBeVisible();

  await ceo.goto('/finance/incomes');
  await ceo.getByRole('button', { name: 'Добавить поступление' }).click();
  const inc = ceo.getByRole('dialog');
  await inc.getByLabel('Категория').selectOption({ label: 'Проценты банка' });
  await inc.getByLabel('Сумма').fill('125000');
  await inc.getByLabel('Комментарий').fill('Проценты на остаток e2e');
  await inc.getByRole('button', { name: 'Сохранить' }).click();
  await expect(ceo.getByText('Поступление сохранено')).toBeVisible();
  await expect(ceo.getByText('Проценты на остаток e2e').first()).toBeVisible();
  await shot(ceo, 'p10-incomes');
});

test('договор: реквизиты компании и клиента → предпросмотр и скачивание Word', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/settings/documents');
  await ceo.getByLabel('Юридическое название').fill('ООО «FLUGGI GROUP»');
  await ceo.getByLabel('ИНН (СТИР)').fill('309876543');
  await ceo.getByLabel('Руководитель (ФИО полностью)').fill('Абдулхаев Нодирбек');
  await ceo.getByLabel('«В лице …» (родительный падеж)').fill('директора Абдулхаева Нодирбека');
  await ceo.getByLabel('Банк', { exact: true }).fill('АКБ «Капиталбанк»');
  await ceo.getByLabel('МФО').fill('01088');
  await ceo.getByLabel('Расчётный счёт').fill('20208000900123456001');
  await ceo.getByRole('button', { name: 'Сохранить реквизиты' }).click();
  await expect(ceo.getByText('Реквизиты сохранены')).toBeVisible();
  await expect(ceo.getByText('{{contract.number}}').first()).toBeVisible();

  await ceo.goto('/sales/contracts');
  await ceo
    .getByRole('button', { name: /^Документ договора/ })
    .first()
    .click();
  const dialog = ceo.getByRole('dialog');
  const frame = ceo.frameLocator('iframe[title="Предпросмотр документа"]');
  await expect(frame.getByText(/^ДОГОВОР №/)).toBeVisible();
  await expect(frame.getByText('ООО «FLUGGI GROUP»').first()).toBeVisible();
  await shot(ceo, 'p10-contract');
  const [download] = await Promise.all([
    ceo.waitForEvent('download'),
    dialog.getByRole('button', { name: 'Word' }).click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/\.docx$/);
});

test('КП: документ из сделки, реквизиты клиента', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/sales/proposals');
  await manager
    .getByRole('button', { name: /^Документ КП/ })
    .first()
    .click();
  const frame = manager.frameLocator('iframe[title="Предпросмотр документа"]');
  await expect(frame.getByText('Коммерческое предложение')).toBeVisible();
  await expect(frame.getByText('Итого', { exact: true })).toBeVisible();
  await shot(manager, 'p10-proposal');
  await manager.keyboard.press('Escape');

  await manager.goto('/clients');
  await manager.locator('table a[href^="/clients/"]').first().click();
  await manager.getByRole('tab', { name: 'Реквизиты' }).click();
  await manager.getByRole('button', { name: /реквизиты/ }).click();
  const dialog = manager.getByRole('dialog');
  await dialog.getByLabel('ИНН (9 цифр) или ПИНФЛ (14 цифр)').fill('123');
  await dialog.getByRole('button', { name: 'Сохранить' }).click();
  await expect(dialog.getByText('ИНН — 9 цифр, ПИНФЛ — 14 цифр')).toBeVisible();
  await dialog.getByLabel('ИНН (9 цифр) или ПИНФЛ (14 цифр)').fill('301234567');
  await dialog.getByLabel('Подписант (ФИО полностью)').fill('Каримов Алишер');
  await dialog.getByRole('button', { name: 'Сохранить' }).click();
  await expect(manager.getByText('Реквизиты сохранены')).toBeVisible();
  await expect(manager.getByText('301234567')).toBeVisible();
});
