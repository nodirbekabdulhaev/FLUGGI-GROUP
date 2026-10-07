import { expect, test, type Page } from '@playwright/test';

/**
 * Phase 1 — сценарий приёмки §84, шаги 1–2:
 * CEO создаёт менеджера → менеджер входит → видит только свои разделы.
 */
const PASSWORD = process.env.SEED_DEMO_PASSWORD ?? 'DemoPass2026';

async function login(page: Page, email: string, password = PASSWORD) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Пароль').fill(password);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/dashboard/);
}

async function logout(page: Page) {
  await page
    .getByRole('button', { name: /CEO|Нодир|Азиз|Асрор|E2E/ })
    .first()
    .click();
  await page.getByRole('menuitem', { name: 'Выйти' }).click();
  await expect(page).toHaveURL(/\/login/);
}

test('неверный пароль показывает понятную ошибку', async ({ page }) => {
  await page.goto('/login');
  // Несуществующий email: не увеличиваем счётчик блокировки демо-аккаунтов.
  await page.getByLabel('Email').fill('nobody@fluggi.demo');
  await page.getByLabel('Пароль').fill('wrong-password-1');
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Неверный' })).toHaveText(
    'Неверный email или пароль',
  );
});

test('без входа — редирект на /login', async ({ page }) => {
  await page.goto('/team/employees');
  await expect(page).toHaveURL(/\/login\?next=%2Fteam%2Femployees/);
});

test('CEO создаёт менеджера, менеджер входит и видит только свои разделы', async ({
  page,
  isMobile,
}) => {
  test.skip(isMobile, 'полный сценарий — на desktop');
  const email = `e2e.manager.${Date.now()}@fluggi.demo`;

  await login(page, 'ceo@fluggi.demo');
  await expect(page.getByRole('heading', { name: /Здравствуйте/ })).toBeVisible();
  await expect(page.getByLabel('Период')).toBeVisible();

  await page.goto('/team/employees');
  await page.getByRole('button', { name: 'Добавить сотрудника' }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Имя и фамилия').fill('E2E Менеджер');
  await dialog.getByLabel('Email').fill(email);
  await dialog.getByLabel('Роль').selectOption('MANAGER');
  await dialog.getByLabel('Отдел продаж').selectOption({ label: 'Отдел продаж 1' });
  await dialog.getByRole('button', { name: 'Создать' }).click();

  const passwordDialog = page.getByRole('dialog', { name: 'Временный пароль' });
  await expect(passwordDialog).toBeVisible();
  const tempPassword = (await passwordDialog.locator('code').textContent())!.trim();
  await passwordDialog.getByRole('button', { name: 'Закрыть' }).last().click();
  await page.getByLabel('Поиск').fill(email);
  await expect(page.getByText(email)).toBeVisible();

  await logout(page);
  await login(page, email, tempPassword);

  const nav = page.getByRole('navigation', { name: 'Главное меню' });
  await expect(nav.getByRole('button', { name: 'Продажи' })).toBeVisible();
  await expect(nav.getByRole('button', { name: 'Команда' })).toHaveCount(0);
  await expect(nav.getByRole('link', { name: 'Настройки' })).toHaveCount(0);
  await expect(page.getByLabel('Период')).toHaveCount(0);

  // Прямой переход в чужой раздел: интерфейс показывает «Нет доступа», API отвечает 403.
  await page.goto('/team/employees');
  await expect(page.getByText('Нет доступа')).toBeVisible();
  const status = await page.evaluate(async () => (await fetch('/api/v1/users')).status);
  expect(status).toBe(403);

  // Нереализованный раздел явно помечен, а не выдаётся за рабочий.
  await page.goto('/projects/all');
  await expect(page.getByText('Раздел в разработке')).toBeVisible();
});

test('мобильное меню открывается как drawer', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'только mobile');
  await login(page, 'rop@fluggi.demo');
  await page.getByRole('button', { name: 'Открыть меню' }).click();
  const drawer = page.getByRole('dialog', { name: 'Главное меню' });
  await expect(drawer).toBeVisible();
  await drawer.getByRole('button', { name: 'Команда' }).click();
  await drawer.getByRole('link', { name: 'Менеджеры' }).click();
  await expect(page).toHaveURL(/\/team\/managers/);
  await expect(drawer).toBeHidden();
  await expect(page.getByText('Асрор Рахимов').filter({ visible: true })).toBeVisible();
  // РОП первого отдела не видит менеджера второго отдела
  await expect(page.getByText('Жасур Алимов')).toHaveCount(0);
});
