import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Phase 6 — KPI, цели, дашборды, посещаемость, графики, зарплата.
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

test('дашборды, KPI и цели, посещаемость, зарплата', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');

  // CEO: карточки компании (ТЗ §5)
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await expect(ceo.getByText('Компания')).toBeVisible();
  for (const label of [
    'Выручка',
    'Прибыль',
    'Оплачено',
    'Ожидается',
    'Новые лиды',
    'Проекты в работе',
  ])
    await expect(ceo.getByText(label, { exact: true }).first()).toBeVisible();

  // РОП: отдел и таблица менеджеров (§58); ставит цель менеджеру (§31)
  const rop = await login(browser, 'rop@fluggi.demo');
  await expect(rop.getByText('Менеджеры отдела')).toBeVisible();
  await rop.goto('/kpi');
  await expect(rop.getByRole('heading', { name: 'KPI' })).toBeVisible();
  const row = rop.getByRole('row', { name: /Асрор Рахимов/ });
  await row.getByRole('button', { name: 'Цели' }).click();
  const dialog = rop.getByRole('dialog');
  await dialog.getByLabel('Выручка').fill('');
  await dialog.getByLabel('Выручка').pressSequentially('100000000');
  await expect(dialog.getByLabel('Выручка')).toHaveValue('100 000 000');
  await dialog.getByLabel('Лиды').fill('20');
  await dialog.getByRole('button', { name: 'Сохранить' }).click();
  await expect(rop.getByText('Цели сохранены')).toBeVisible();
  await expect(row.getByText(/Лиды: \d+ \/ 20/)).toBeVisible();

  // Менеджер: свои показатели на главной (§59) и отметка прихода (§35)
  const manager = await login(browser, 'manager1@fluggi.demo');
  await expect(manager.getByText('Мои показатели')).toBeVisible();
  await expect(manager.getByText('Моя цель')).toBeVisible();
  const checkIn = manager.getByRole('button', { name: 'Отметить приход' });
  if (await checkIn.isVisible()) {
    await checkIn.click();
    await expect(manager.getByText('Приход отмечен')).toBeVisible();
  }
  await expect(
    manager
      .getByRole('button', { name: /Отметить уход|Отметить приход/ })
      .or(manager.getByText(/Уход \d{2}:\d{2}/))
      .first(),
  ).toBeVisible();

  // HR: исправляет запись посещаемости
  const hr = await login(browser, 'hr@fluggi.demo');
  await hr.goto('/attendance');
  await hr.getByRole('button', { name: 'Внести / исправить' }).click();
  const at = hr.getByRole('dialog');
  await at.getByLabel('Сотрудник').selectOption({ label: 'Асрор Рахимов' });
  await at.getByLabel('Дата').fill('2026-10-01');
  await at.getByLabel('Статус').selectOption('VACATION');
  await at.getByRole('button', { name: 'Сохранить' }).click();
  await expect(hr.getByText('Запись сохранена')).toBeVisible();
  await hr.getByRole('tab', { name: /Записи/ }).click();
  await expect(hr.getByText('Отпуск').first()).toBeVisible();

  // CEO: рабочие графики и расчёт зарплаты (§32, §36)
  await ceo.goto('/settings/schedules');
  await expect(ceo.getByText('Менеджеры · 09:00–18:00')).toBeVisible();
  await ceo.goto('/finance/payroll');
  await ceo.getByRole('button', { name: 'Рассчитать месяц' }).click();
  await expect(ceo.getByText('Зарплата рассчитана')).toBeVisible();

  // Исполнитель видит свои задачи на главной и только свою зарплату
  const smm = await login(browser, 'smm@fluggi.demo');
  await expect(smm.getByText('Мои задачи')).toBeVisible();
  await smm.goto('/finance/payroll');
  await expect(smm.getByText('Ваша зарплата по месяцам')).toBeVisible();
  await expect(smm.getByRole('button', { name: 'Рассчитать месяц' })).toHaveCount(0);
});
