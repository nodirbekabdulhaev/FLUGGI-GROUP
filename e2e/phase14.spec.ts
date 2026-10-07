import { expect, test, type Browser, type Page } from '@playwright/test';

/** Удаление сотрудника: открытая работа передаётся другому, история остаётся. */
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

test('CEO удаляет ненужного сотрудника', async ({ browser, isMobile }) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  const email = `temp.${Date.now()}@fluggi.demo`;
  await ceo.goto('/team/employees');
  await ceo.getByRole('button', { name: 'Добавить сотрудника' }).first().click();
  const form = ceo.getByRole('dialog');
  await form.getByLabel('Имя и фамилия').fill('Временный Сотрудник');
  await form.getByLabel('Email').fill(email);
  await form.getByLabel('Роль').selectOption('EXECUTOR');
  await form.getByRole('button', { name: 'Создать' }).click();
  await ceo
    .getByRole('dialog', { name: 'Временный пароль' })
    .getByRole('button', { name: 'Закрыть' })
    .last()
    .click();

  await ceo.getByLabel('Поиск', { exact: true }).fill(email);
  const row = ceo.getByRole('row', { name: new RegExp(email.replace(/\./g, '\\.')) });
  await row.getByRole('button', { name: 'Действия' }).click();
  await ceo.getByRole('menuitem', { name: 'Удалить' }).click();
  const dialog = ceo.getByRole('dialog');
  await expect(dialog.getByText('Открытой работы нет — можно удалить.')).toBeVisible();
  await dialog.getByRole('button', { name: 'Удалить сотрудника' }).click();
  await expect(ceo.getByText('Сотрудник удалён')).toBeVisible();
  await expect(ceo.getByText(email)).toHaveCount(0);
});
