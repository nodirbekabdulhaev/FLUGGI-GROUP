import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Phase 4 — сценарий приёмки §84, шаги 16–20:
 * оплата → проект с задачами из шаблона SMM → РОП назначает исполнителя →
 * исполнитель видит задачи, берёт в работу, двигает карточку на Kanban.
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

/** Вызов API из браузера пользователя (cookie-сессия + CSRF), как это делает интерфейс. */
async function call<T = { id: string }>(page: Page, method: string, path: string, body?: object) {
  const res = await page.evaluate(
    async ({ method, path, body }) => {
      const csrf = document.cookie.match(/fluggi_csrf=([^;]+)/)?.[1] ?? '';
      const r = await fetch(`/api/v1${path}`, {
        method,
        headers: { 'Content-Type': 'application/json', 'x-csrf-token': csrf },
        body: body ? JSON.stringify(body) : undefined,
      });
      return { status: r.status, body: await r.json().catch(() => null) };
    },
    { method, path, body },
  );
  expect(res.status, `${method} ${path}: ${JSON.stringify(res.body)}`).toBeLessThan(300);
  return res.body as T;
}

/** Сделка с услугой SMM, доведённая до подтверждённой оплаты. */
async function paidSmmProject(manager: Page, rop: Page) {
  const refs = await call<{ services: { id: string; code: string }[] }>(
    manager,
    'GET',
    '/references',
  );
  const smm = refs.services.find((s) => s.code === 'SMM')!;
  const clients = await call<{ items: { id: string }[] }>(manager, 'GET', '/clients?pageSize=1');
  const name = `E2E SMM ${Date.now()}`;
  const deal = await call(manager, 'POST', '/deals', {
    clientId: clients.items[0]!.id,
    title: name,
    amount: '7000000',
    serviceId: smm.id,
  });
  const kp = await call(manager, 'POST', '/proposals', {
    dealId: deal.id,
    title: name,
    items: [{ serviceId: smm.id, description: 'SMM', quantity: 1, unitPrice: '7000000' }],
  });
  await call(manager, 'POST', `/proposals/${kp.id}/send`);
  await call(manager, 'POST', `/proposals/${kp.id}/accept`);
  const contract = await call(manager, 'POST', '/contracts', {
    dealId: deal.id,
    proposalId: kp.id,
    contractDate: new Date().toISOString().slice(0, 10),
    amount: '7000000',
  });
  await call(manager, 'POST', `/contracts/${contract.id}/sign`);
  const pay = await call(manager, 'POST', '/payments', {
    dealId: deal.id,
    amount: '7000000',
    type: 'FULL',
    method: 'BANK',
  });
  const confirmed = await call<{ project: { id: string } }>(
    rop,
    'POST',
    `/payments/${pay.id}/confirm`,
    {},
  );
  return { projectId: confirmed.project.id, name };
}

test('проект: шаблон задач, команда, Kanban', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  const rop = await login(browser, 'rop@fluggi.demo');
  const { projectId, name } = await paidSmmProject(manager, rop);

  // РОП: проект в списке, задачи шаблона в колонке «К выполнению»
  await rop.goto('/projects/all');
  await rop.getByPlaceholder('Поиск по проекту или клиенту').fill(name);
  await rop.getByRole('link', { name: new RegExp(name) }).click();
  await expect(rop).toHaveURL(new RegExp(`/projects/${projectId}`));
  const todo = rop.getByTestId('column-TODO');
  await expect(todo.getByText('Контент-план')).toBeVisible();
  await expect(todo.getByText(/ждёт исполнителя: SMM-менеджер/).first()).toBeVisible();

  // Шаг 17: назначить SMM-менеджера — его задачи шаблона назначаются автоматически
  await rop.getByRole('tab', { name: /Команда/ }).click();
  await rop.getByRole('button', { name: 'Добавить исполнителя' }).click();
  const dialog = rop.getByRole('dialog');
  const option = dialog.getByLabel('Сотрудник').locator('option', { hasText: 'SMM-менеджер' });
  await dialog.getByLabel('Сотрудник').selectOption((await option.first().getAttribute('value'))!);
  await expect(dialog.getByLabel('Роль в проекте')).toHaveValue('SMM');
  await dialog.getByRole('button', { name: 'Добавить исполнителя' }).click();
  await expect(rop.getByText('Исполнитель добавлен в проект')).toBeVisible();
  await expect(rop.getByRole('cell', { name: 'SMM-менеджер' })).toBeVisible();
  await expect(rop.getByText('Планирование').first()).toBeVisible();

  // Шаг 19–20: исполнитель видит свои задачи и берёт задачу в работу
  const smm = await login(browser, 'smm@fluggi.demo');
  await smm.goto('/tasks');
  await expect(smm.getByRole('link', { name: 'Контент-план' }).first()).toBeVisible();
  await smm.goto(`/projects/${projectId}`);
  await smm
    .getByTestId('column-TODO')
    .getByRole('button', { name: 'Контент-план', exact: true })
    .click();
  const task = smm.getByRole('dialog');
  await expect(task.getByText(name)).toBeVisible();
  await task.getByRole('button', { name: 'В работе' }).click();
  await expect(smm.getByText('Статус задачи изменён')).toBeVisible();
  await task.getByLabel('Комментарий к задаче').fill('Начал работу');
  await task.getByRole('button', { name: 'Отправить' }).click();
  await expect(task.getByText('Начал работу')).toBeVisible();
  await smm.keyboard.press('Escape');

  // Kanban: перетащить «Публикация» в «На проверке»
  const card = smm.getByTestId('column-TODO').getByText('Публикация');
  const target = smm.getByTestId('column-REVIEW');
  const from = (await card.boundingBox())!;
  const to = (await target.boundingBox())!;
  // Тянем за тело карточки: заголовок — это кнопка открытия задачи
  await smm.mouse.move(from.x + 10, from.y + from.height + 10);
  await smm.mouse.down();
  await smm.mouse.move(from.x + 30, from.y + from.height + 30, { steps: 5 });
  await smm.mouse.move(to.x + to.width / 2, to.y + 40, { steps: 15 });
  await smm.mouse.up();
  await expect(smm.getByTestId('column-REVIEW').getByText('Публикация')).toBeVisible();
  await expect(smm.getByText('Статус задачи изменён').first()).toBeVisible();
  // Исполнитель не видит деньги проекта
  await smm.getByRole('tab', { name: 'О проекте' }).click();
  await expect(smm.getByText('Стоимость видна руководителям и отделу продаж')).toBeVisible();

  // РОП: проект «В работе», задача на проверке, уведомление
  await rop.goto('/projects/active');
  await rop.getByPlaceholder('Поиск по проекту или клиенту').fill(name);
  await expect(
    rop.getByRole('row', { name: new RegExp(name) }).getByText('В работе'),
  ).toBeVisible();
  // Уведомление создаёт outbox-обработчик асинхронно
  await expect(async () => {
    await rop.goto('/notifications');
    await expect(rop.getByText('Задача на проверке').first()).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 20_000 });
});
