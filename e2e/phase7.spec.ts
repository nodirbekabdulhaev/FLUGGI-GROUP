import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * Phase 7 — Telegram-бот, настройки уведомлений, автоматизация, повторные продажи.
 * Полная привязка Telegram проверяется, если API запущен с ботом и задан E2E_TELEGRAM_WEBHOOK_SECRET
 * (тот же, что TELEGRAM_WEBHOOK_SECRET у API); иначе — сообщение «бот не настроен».
 */
const PASSWORD = process.env.SEED_DEMO_PASSWORD ?? 'DemoPass2026';
const API = process.env.E2E_API_URL ?? 'http://localhost:4000';
const SECRET = process.env.E2E_TELEGRAM_WEBHOOK_SECRET;
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
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
};

test('Telegram и личные настройки уведомлений', async ({ browser, isMobile, request }) => {
  test.skip(isMobile, 'desktop');
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/profile');
  await expect(manager.getByText('Telegram-бот')).toBeVisible();

  if (SECRET) {
    // Если уже подключён с прошлого прогона — отключаем
    const unlink = manager.getByRole('button', { name: 'Отключить' });
    const connect = manager.getByRole('button', { name: 'Подключить Telegram' });
    await expect(unlink.or(connect)).toBeVisible();
    if (await unlink.isVisible()) await unlink.click();
    await manager.getByRole('button', { name: 'Подключить Telegram' }).click();
    const command = (await manager.getByTestId('telegram-command').textContent())!.trim();
    expect(command).toMatch(/^\/start [\w-]+$/);
    await expect(manager.getByRole('link', { name: 'Открыть бота' })).toHaveAttribute(
      'href',
      /^https:\/\/t\.me\/\w+\?start=/,
    );
    // Сообщение боту — как его доставит Telegram через webhook
    const res = await request.post(`${API}/api/v1/telegram/webhook`, {
      headers: { 'x-telegram-bot-api-secret-token': SECRET },
      data: {
        update_id: Date.now(),
        message: {
          message_id: 1,
          text: command,
          chat: { id: 900001, type: 'private' },
          from: { id: 900001, username: 'demo_manager' },
        },
      },
    });
    expect(res.status()).toBe(200);
    await expect(manager.getByText('Подключён', { exact: true })).toBeVisible({ timeout: 10_000 });
    await expect(manager.getByText('@demo_manager')).toBeVisible();
    await manager.getByRole('button', { name: 'Отправить тестовое сообщение' }).click();
    await expect(manager.getByText(/поставлено в очередь/)).toBeVisible();
  } else {
    await expect(manager.getByTestId('telegram-not-configured')).toBeVisible();
  }

  // Индивидуальные настройки: выключаем Telegram для «Новый лид» и возвращаем
  const box = manager.getByRole('checkbox', { name: 'Новый лид: Telegram' });
  await expect(box).toBeVisible();
  if (!(await box.isChecked())) await box.check(); // прошлый прогон мог прерваться
  await expect(box).toBeChecked();
  await box.uncheck();
  await expect(manager.getByRole('checkbox', { name: 'Новый лид: Telegram' })).not.toBeChecked();
  await manager.waitForLoadState('networkidle');
  await manager.reload();
  await expect(manager.getByRole('checkbox', { name: 'Новый лид: Telegram' })).not.toBeChecked();
  await shot(manager, 'p7-profile');
  await manager.getByRole('checkbox', { name: 'Новый лид: Telegram' }).check();
  await expect(manager.getByRole('checkbox', { name: 'Новый лид: Telegram' })).toBeChecked();
  // CEO-отчётов у менеджера нет
  await expect(manager.getByText('Еженедельный отчёт')).toHaveCount(0);
  // Раздела «Автоматизация» у менеджера нет
  await manager.goto('/settings/automation');
  await expect(manager.getByRole('button', { name: 'Сохранить' })).toHaveCount(0);
});

test('автоматизация: параметры, планировщик, отчёт; повторные продажи', async ({
  browser,
  isMobile,
}) => {
  test.skip(isMobile, 'desktop');
  const ceo = await login(browser, 'ceo@fluggi.demo');
  await ceo.goto('/settings');
  await ceo.getByRole('link', { name: 'Автоматизация' }).click();
  await expect(ceo).toHaveURL(/\/settings\/automation/);
  await expect(ceo.getByLabel('Порог «крупного» лида и сделки, UZS')).toHaveValue(/\d \d{3} \d{3}/);

  // Предпросмотр отчёта — тот же текст, что в Telegram (§56–57)
  await expect(ceo.getByTestId('report-preview')).toContainText('ОТЧЁТ ЗА');
  await expect(ceo.getByTestId('report-preview')).toContainText('Новые лиды:');
  await ceo.getByRole('button', { name: 'За неделю' }).click();
  await expect(ceo.getByTestId('report-preview')).toContainText('НЕДЕЛЬНЫЙ ОТЧЁТ');

  // Ручной запуск задачи планировщика
  await ceo.getByRole('button', { name: 'Запустить «Умные напоминания» сейчас' }).click();
  await expect(ceo.getByText('«Умные напоминания» выполнена')).toBeVisible();
  await expect(
    ceo.getByRole('row', { name: /Умные напоминания/ }).getByText('Успешно'),
  ).toBeVisible();

  // Сохранение параметров
  await ceo.getByRole('button', { name: 'Сохранить' }).first().click();
  await expect(ceo.getByText('Настройки сохранены')).toBeVisible();
  await shot(ceo, 'p7-automation');

  // Повторные продажи: в меню продаж
  const manager = await login(browser, 'manager1@fluggi.demo');
  await manager.goto('/sales/leads');
  await manager.getByRole('link', { name: 'Повторные продажи' }).first().click();
  await expect(manager).toHaveURL(/\/sales\/follow-ups/);
  await expect(manager.getByRole('heading', { name: 'Повторные продажи' })).toBeVisible();
  await manager.getByRole('button', { name: 'Все открытые' }).click();
  await shot(manager, 'p7-follow-ups');
});
