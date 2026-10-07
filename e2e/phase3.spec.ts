import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Phase 3 — сценарий приёмки §84, шаги 9–16 и 25:
 * КП → отправлено → принято → договор → подписан → оплата → РОП подтверждает →
 * проект создан, комиссии начислены.
 */
const PASSWORD = process.env.SEED_DEMO_PASSWORD ?? 'DemoPass2026';

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

test('КП → договор → оплата → проект и комиссии', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');

  // Новая сделка у существующего клиента
  await manager.goto('/sales/deals');
  await manager.getByRole('button', { name: 'Новая сделка' }).first().click();
  const dd = manager.getByRole('dialog');
  await dd.getByLabel('Клиент').selectOption({ index: 1 });
  const title = `E2E продажа ${Date.now()}`;
  await dd.getByLabel('Название').fill(title);
  await dd.getByLabel('Сумма').fill('8000000');
  await dd.getByRole('button', { name: 'Создать сделку' }).click();
  await expect(manager).toHaveURL(/\/sales\/deals\/[0-9a-f-]{36}$/);
  const dealUrl = manager.url();

  // КП
  await manager.getByRole('tab', { name: 'КП' }).click();
  await manager.getByRole('button', { name: 'Новое КП' }).click();
  const kp = manager.getByRole('dialog');
  await kp.getByLabel('Название').fill('Брендинг');
  await kp.getByLabel('Описание').first().fill('Логотип и фирменный стиль');
  await kp.getByLabel('Цена').first().fill('10000000');
  await expect(kp.getByText('10 000 000 UZS').last()).toBeVisible();
  await kp.getByRole('button', { name: 'Сохранить' }).click();
  await expect(manager.getByText('КП сохранено (версия 1)')).toBeVisible();
  await manager.getByRole('button', { name: 'Отправлено клиенту' }).click();
  await expect(manager.getByText('КП отправлено', { exact: true }).first()).toBeVisible();
  await manager.getByRole('button', { name: 'Клиент принял' }).click();
  await expect(manager.getByText('Принято').first()).toBeVisible();

  // Договор по КП → подписан
  await manager.getByRole('tab', { name: 'Договоры' }).click();
  await manager.getByRole('button', { name: 'Новый договор' }).click();
  await manager.getByRole('dialog').getByLabel('По принятому КП').selectOption({ index: 1 });
  await manager.getByRole('dialog').getByRole('button', { name: 'Новый договор' }).click();
  await manager.getByRole('button', { name: 'Подписан' }).click();
  await expect(manager.getByText('Подписан').first()).toBeVisible();

  // Оплата — менеджер добавляет, но подтвердить не может
  await manager.getByRole('tab', { name: 'Оплаты' }).click();
  await manager.getByRole('button', { name: 'Новая оплата' }).click();
  await manager.getByRole('dialog').getByLabel('Сумма').fill('10000000');
  await manager.getByRole('dialog').getByRole('button', { name: 'Новая оплата' }).click();
  await expect(manager.getByText('Ожидает подтверждения', { exact: true })).toBeVisible();
  await expect(manager.getByRole('button', { name: 'Подтвердить оплату' })).toHaveCount(0);

  // РОП подтверждает → проект + комиссии
  const rop = await login(browser, 'rop@fluggi.demo');
  await rop.goto(dealUrl);
  await rop.getByRole('tab', { name: 'Оплаты' }).click();
  await rop.getByRole('button', { name: 'Подтвердить оплату' }).click();
  await expect(rop.getByText(/Создан проект P-\d{5}/)).toBeVisible();
  await expect(rop.getByText(/Начислены комиссии/)).toBeVisible();
  await rop.reload();
  await expect(rop.getByText('Оплачено').first()).toBeVisible();
  await expect(rop.getByText(/P-\d{5}/).first()).toBeVisible();

  // Менеджер видит свою комиссию
  await manager.goto('/finance/commissions');
  await expect(manager.getByText('Менеджер — 10% от оплаты').first()).toBeVisible();
});
