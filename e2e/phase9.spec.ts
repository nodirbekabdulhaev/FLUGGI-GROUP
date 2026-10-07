import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Личные дела («Список дел»), регулярные дела (налоговый календарь), чат сотрудников.
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

test('«Список дел» внизу и регулярные дела', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  const title = `Позвонить в банк ${Date.now()}`;

  // Панель внизу → «Добавить» → дело появляется в списке
  await ceo.getByRole('button', { name: /^Список дел/ }).click();
  await ceo
    .getByRole('region', { name: 'Список дел' })
    .getByRole('button', { name: 'Добавить' })
    .click();
  await ceo.getByLabel('Название').fill(title);
  await ceo.getByLabel('Тип').selectOption('CALL');
  await ceo.getByLabel('Приоритет').selectOption('URGENT');
  await ceo.getByRole('button', { name: 'Сохранить' }).click();
  await expect(ceo.getByText('Дело добавлено')).toBeVisible();
  const dock = ceo.getByRole('region', { name: 'Список дел' });
  await expect(dock.getByText(title)).toBeVisible();
  await expect(dock.getByText('Звонок').first()).toBeVisible();
  await shot(ceo, 'p9-dock');

  // Выполнено — исчезает из панели
  await dock.getByRole('button', { name: `Выполнено: ${title}` }).click();
  await expect(ceo.getByText('Дело выполнено')).toBeVisible();
  await expect(dock.getByText(title)).toHaveCount(0);
  await dock.getByRole('button', { name: 'Свернуть' }).first().click();

  // Регулярные: налоговый календарь IT-Park
  await ceo.getByRole('link', { name: 'Мои дела' }).first().click();
  await ceo.getByRole('tab', { name: 'Регулярные' }).click();
  await ceo.getByRole('button', { name: 'Налоговый календарь IT-Park' }).click();
  await expect(ceo.getByText('Налоговый календарь добавлен')).toBeVisible();
  for (const text of [
    /^Налог с оборота за /,
    /^ИНПС — посчитать за /,
    /^Квартальный отчёт IT-Park за /,
    /^Годовой статотчёт за /,
  ])
    await expect(ceo.getByRole('cell', { name: text })).toBeVisible();
  await expect(ceo.getByText('ежемесячно 4-го · за 3 дн.').first()).toBeVisible();
  await shot(ceo, 'p9-recurring');

  // Выполненные дела — на вкладке «Мои»
  await ceo.getByRole('tab', { name: 'Мои' }).click();
  await ceo.getByRole('button', { name: 'Выполненные' }).click();
  await expect(ceo.getByText(title)).toBeVisible();
});

test('чат сотрудников: сообщение и непрочитанные', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  const manager = await login(browser, 'manager1@fluggi.demo');
  const text = `Как дела с клиентом? ${Date.now()}`;

  await ceo.getByRole('button', { name: /^Открыть чат/ }).click();
  await ceo.getByRole('button', { name: 'Новый чат' }).click();
  await ceo.getByLabel('Найти сотрудника').fill('Асрор');
  await ceo.getByRole('button', { name: /Асрор Рахимов/ }).click();
  await ceo.getByLabel('Сообщение').fill(text);
  await ceo.getByRole('button', { name: 'Отправить' }).click();
  await expect(ceo.getByTestId('chat-messages').getByText(text)).toBeVisible();
  await shot(ceo, 'p9-chat');

  // У менеджера — счётчик непрочитанных и сообщение
  await manager.reload();
  await expect(
    manager.getByRole('button', { name: /Открыть чат, непрочитанных: \d+/ }),
  ).toBeVisible({ timeout: 20_000 });
  await manager.getByRole('button', { name: /^Открыть чат/ }).click();
  await manager.getByRole('button', { name: /Нодир Абдулхаев/ }).click();
  await expect(manager.getByTestId('chat-messages').getByText(text)).toBeVisible();
  const reply = `Всё хорошо, договор подписан ${Date.now() % 100000}`;
  await manager.getByLabel('Сообщение').fill(reply);
  await manager.getByRole('button', { name: 'Отправить' }).click();
  await expect(ceo.getByTestId('chat-messages').getByText(reply)).toBeVisible({
    timeout: 10_000,
  });
});
