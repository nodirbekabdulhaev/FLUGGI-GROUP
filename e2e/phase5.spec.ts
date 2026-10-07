import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Phase 5 — финансы: ввод сумм с разрядами, исправление оплаты, расходы проекта,
 * финансовая карточка, дашборд, утверждение комиссий, правила комиссий.
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

test('финансы: оплата, расходы проекта, дашборд, комиссии', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  const rop = await login(browser, 'rop@fluggi.demo');
  const { projectId, name } = await paidSmmProject(manager, rop);

  // Сумма форматируется при вводе; неподтверждённую оплату можно исправить
  const project = await call<{ deal: { id: string } }>(rop, 'GET', `/projects/${projectId}`);
  await manager.goto(`/sales/deals/${project.deal.id}`);
  await manager.getByRole('tab', { name: 'Оплаты' }).click();
  await manager.getByRole('button', { name: 'Новая оплата' }).click();
  const pay = manager.getByRole('dialog');
  await pay.getByLabel('Сумма').pressSequentially('500000');
  await expect(pay.getByLabel('Сумма')).toHaveValue('500 000');
  await pay.getByRole('button', { name: 'Новая оплата' }).click();
  await expect(manager.getByText('Оплата добавлена — ожидает подтверждения')).toBeVisible();
  await manager.getByRole('button', { name: 'Изменить' }).first().click();
  const edit = manager.getByRole('dialog');
  await edit.getByLabel('Сумма').fill('');
  await edit.getByLabel('Сумма').pressSequentially('1000000');
  await expect(edit.getByLabel('Сумма')).toHaveValue('1 000 000');
  await edit.getByRole('button', { name: 'Сохранить' }).click();
  await expect(manager.getByText('Оплата изменена')).toBeVisible();
  await expect(manager.getByText('1 000 000 UZS').first()).toBeVisible();

  // РОП: расход проекта во вкладке «Финансы»
  await rop.goto(`/projects/${projectId}?tab=finance`);
  await expect(rop.getByText('Стоимость проекта')).toBeVisible();
  await rop.getByRole('button', { name: 'Новый расход' }).click();
  const exp = rop.getByRole('dialog');
  await exp.getByLabel('Сумма').pressSequentially('2500000');
  await exp.getByLabel('Категория').selectOption('DESIGN');
  await exp.getByRole('button', { name: 'Новый расход' }).click();
  await expect(rop.getByText('Расход добавлен')).toBeVisible();
  await expect(rop.getByRole('cell', { name: /2 500 000 UZS/ })).toBeVisible();
  // 7 000 000 − 2 500 000 = 4 500 000, маржа 64.29%
  await expect(rop.getByText('4 500 000 UZS')).toBeVisible();
  await expect(rop.getByText('64.29%')).toBeVisible();

  // CEO: дашборд, прибыль по проектам, утверждение комиссий
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/finance/revenue?period=month');
  await expect(ceo.getByRole('heading', { name: 'Финансовый обзор' })).toBeVisible();
  await expect(ceo.getByText('Операционная прибыль')).toBeVisible();
  await expect(ceo.getByText('Расходы по категориям')).toBeVisible();
  await ceo.goto('/finance/profit');
  await ceo.getByPlaceholder('Поиск по проекту или клиенту').fill(name);
  await expect(ceo.getByRole('row', { name: new RegExp(name) }).getByText('64.29%')).toBeVisible();

  await ceo.goto('/finance/commissions');
  await ceo.getByLabel('Статус').selectOption('ACCRUED');
  await ceo
    .getByLabel(/^Выбрать .* PAY-/)
    .first()
    .check();
  await ceo.getByRole('button', { name: 'Утвердить' }).click();
  await expect(ceo.getByText(/Комиссии утверждены: 1/)).toBeVisible();

  // Правила комиссий редактируются в настройках (ТЗ §34)
  await ceo.goto('/settings/commission-rules');
  await expect(ceo.getByText(/Средний чек за месяц, USD > 3/)).toBeVisible();

  // Менеджер не видит финансы компании
  await manager.goto('/finance/revenue');
  await expect(manager.getByText('Нет доступа')).toBeVisible();
});
