import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Интеграции: форма для сайта → лид; «Входящие»; финансы проекта — только CEO.
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

test('форма для сайта: CEO создаёт, посетитель отправляет → лид «Сайт»', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  const name = `Сайт SMM ${Date.now() % 100000}`;
  await ceo.goto('/settings/integrations');
  await ceo.getByRole('button', { name: 'Новая форма' }).first().click();
  const dialog = ceo.getByRole('dialog');
  await dialog.getByLabel('Название', { exact: true }).fill(name);
  await dialog.getByRole('button', { name: 'Сохранить' }).click();
  await expect(ceo.getByText('Форма сохранена')).toBeVisible();

  await ceo
    .getByRole('row', { name: new RegExp(name) })
    .getByRole('button', { name: 'Код' })
    .click();
  await expect(ceo.getByTestId('embed-code')).toContainText('data-fluggi-form=');
  await expect(ceo.getByTestId('embed-code')).toContainText('/embed.js');
  const link = (await ceo.getByTestId('form-link').textContent())!.trim();
  await shot(ceo, 'p13-form-code');
  await ceo.keyboard.press('Escape');

  // Посетитель сайта (без входа)
  const visitor = await (await browser.newContext()).newPage();
  await visitor.goto(`${link}?utm_source=instagram&utm_campaign=oct`);
  await expect(visitor.getByRole('heading', { name: 'Оставьте заявку' })).toBeVisible();
  const phone = `+99890${String(Date.now()).slice(-7)}`;
  await visitor.getByLabel('Ваше имя').fill('Гость E2E');
  await visitor.getByLabel('Телефон').fill(phone);
  await visitor.getByLabel('Что вас интересует?').fill('Нужен SMM для кафе');
  await visitor.waitForTimeout(1600);
  await shot(visitor, 'p13-public-form');
  await visitor.getByRole('button', { name: 'Отправить' }).click();
  await expect(visitor.getByText('Спасибо! Мы свяжемся с вами в ближайшее время.')).toBeVisible();

  await ceo.goto('/sales/leads');
  await ceo.getByLabel('Поиск', { exact: true }).fill('Гость E2E');
  await expect(ceo.getByText(new RegExp(`Гость E2E — ${name}`)).first()).toBeVisible();
});

test('«Входящие» открываются; без подключения — понятная подсказка', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.getByRole('link', { name: 'Входящие' }).click();
  await expect(manager.getByRole('heading', { name: 'Входящие' })).toBeVisible();

  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/settings/integrations');
  await expect(ceo.getByText('Instagram, Facebook и таргет')).toBeVisible();
  await expect(ceo.getByTestId('meta-webhook')).toContainText('/api/v1/public/meta/webhook');
  await shot(ceo, 'p13-meta');
});

test('финансы проекта видит только CEO', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const rop = await login(browser, 'rop@fluggi.demo');
  await rop.goto('/projects/all');
  await rop.locator('table a[href^="/projects/"]').first().click();
  await expect(rop.getByRole('tab', { name: /Задачи/ })).toBeVisible();
  await expect(rop.getByRole('tab', { name: 'Финансы' })).toHaveCount(0);
  await expect(rop.getByRole('link', { name: 'Прибыль по проектам' })).toHaveCount(0);

  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/projects/all');
  await ceo.locator('table a[href^="/projects/"]').first().click();
  await expect(ceo.getByRole('tab', { name: 'Финансы' })).toBeVisible();
});
