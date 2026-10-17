<?php

namespace Tests\Feature\Branding;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Brand;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    private User $ceo;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        // Иконки пишутся во временный storage, не в рабочий
        $this->storage = sys_get_temp_dir().'/fluggi-brand-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function form(array $extra = []): array
    {
        return $extra + ['name' => 'Acme Group CRM', 'shortName' => 'Acme'];
    }

    public function test_only_settings_manage_can_view_and_save(): void
    {
        foreach (['ROP', 'MANAGER', 'EXECUTOR', 'ACCOUNTANT'] as $role) {
            $u = $this->user($role);
            $this->actingAsUser($u)->get('/settings/branding')->assertForbidden();
            $this->actingAsUser($u)->post('/settings/branding', $this->form())->assertForbidden();
            $this->actingAsUser($u)->post('/settings/branding/reset')->assertForbidden();
        }
        $this->assertSame('Fluggi CRM', brand()->name());

        $this->actingAsUser($this->ceo)->get('/settings/branding')->assertOk()->assertSee(t('branding.title'));
        $this->actingAsUser($this->user('HR_ADMIN'))->get('/settings/branding')->assertOk();
    }

    public function test_upload_generates_icons_and_brand_shows_everywhere(): void
    {
        $logo = UploadedFile::fake()->image('logo.png', 800, 600);
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['logo' => $logo, 'themeColor' => '#AA3300']))
            ->assertRedirect()->assertSessionHas('ok');

        Settings::forget();
        $brand = brand();
        $version = $brand->version();
        $this->assertNotNull($version);
        $this->assertSame('#aa3300', $brand->themeColor());
        foreach (Brand::FILES as $file => [$size]) {
            $info = getimagesize(Brand::dir($version).'/'.$file.'.png');
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], $file);
        }

        // Аудит
        $log = AuditLog::where('action', 'settings.branding')->sole();
        $this->assertSame('Fluggi CRM', $log->changes['branding']['old']['name']);
        $this->assertSame('Acme Group CRM', $log->changes['branding']['new']['name']);

        // Заголовок, меню, иконки
        $this->actingAsUser($this->ceo)->get('/dashboard')->assertOk()
            ->assertSee('· Acme Group CRM</title>', false)
            ->assertSee('<meta name="theme-color" content="#aa3300">', false)
            ->assertSee('/brand/icon-192?v='.$version, false)
            ->assertSee('/brand/favicon-32?v='.$version, false)
            ->assertSee('/brand/apple-touch-icon?v='.$version, false)
            ->assertDontSee('/icon.svg', false);

        // Страница входа и офлайн-страница (без входа)
        $this->flushSession();
        $this->get('/login?lang=ru')->assertOk()->assertSee('Вход в Acme Group CRM')->assertSee('/brand/icon-192?v='.$version, false);
        $this->get('/offline')->assertOk()->assertSee('Acme Group CRM')->assertSee('/brand/icon-192?v='.$version, false);

        // Иконка отдаётся без входа, с долгим кэшем для адреса с версией
        $r = $this->get('/brand/icon-512?v='.$version)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('immutable', $r->headers->get('Cache-Control'));
        $this->assertSame([512, 512], array_slice(getimagesizefromstring(file_get_contents($r->baseResponse->getFile()->getPathname())), 0, 2));
        $this->assertStringNotContainsString('immutable', $this->get('/brand/icon-512')->assertOk()->headers->get('Cache-Control'));

        // Манифест
        $m = $this->get('/app-manifest')->assertOk();
        $this->assertStringStartsWith('application/manifest+json', $m->headers->get('Content-Type'));
        $json = json_decode($m->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Acme Group CRM', $json['name']);
        $this->assertSame('Acme', $json['short_name']);
        $this->assertSame('#aa3300', $json['theme_color']);
        $this->assertSame('/brand/maskable-512?v='.$version, collect($json['icons'])->firstWhere('purpose', 'maskable')['src']);
        $this->assertSame([], $m->headers->getCookies(), 'манифест без сессии');

        // Новая загрузка заменяет набор, старый удаляется
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['logo' => UploadedFile::fake()->image('logo2.jpg', 300, 300)]))->assertSessionHas('ok');
        Settings::forget();
        $this->assertNotSame($version, brand()->version());
        $this->assertDirectoryDoesNotExist(Brand::dir($version));
        $this->assertNotNull(brand()->version());
        // Без нового файла логотип сохраняется, пустой цвет — стандартный
        $kept = brand()->version();
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['name' => 'Other', 'themeColor' => '']))->assertSessionHas('ok');
        Settings::forget();
        $this->assertSame($kept, brand()->version());
        $this->assertSame(Brand::DEFAULT_THEME, brand()->themeColor());
    }

    public function test_bad_files_are_rejected_and_previous_logo_kept(): void
    {
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['logo' => UploadedFile::fake()->image('ok.png', 256, 256)]))->assertSessionHas('ok');
        Settings::forget();
        $version = brand()->version();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"><script>alert(1)</script></svg>');
        $cases = [
            'svg' => $svg,
            'not an image' => UploadedFile::fake()->createWithContent('logo.png', 'MZ this is not a png'),
            'too small' => UploadedFile::fake()->image('small.png', 100, 100),
            'huge file' => UploadedFile::fake()->create('big.png', 6000, 'image/png'),
            'pdf' => UploadedFile::fake()->createWithContent('logo.pdf', "%PDF-1.4\n%%EOF"),
        ];
        foreach ($cases as $case => $file) {
            $this->actingAsUser($this->ceo)->from('/settings/branding')
                ->post('/settings/branding', $this->form(['name' => 'Changed', 'logo' => $file]))
                ->assertRedirect('/settings/branding')->assertSessionHasErrors('logo');
            Settings::forget();
            $this->assertSame($version, brand()->version(), $case);
            $this->assertSame('Acme Group CRM', brand()->name(), $case);
        }
        $this->assertCount(1, File::directories(Brand::root()));

        // Неверный цвет и длинное название
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['themeColor' => 'red']))->assertSessionHasErrors('themeColor');
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['name' => str_repeat('x', 61)]))->assertSessionHasErrors('name');
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['shortName' => str_repeat('x', 21)]))->assertSessionHasErrors('shortName');
        $this->actingAsUser($this->ceo)->post('/settings/branding', ['name' => '  ', 'shortName' => 'A'])->assertSessionHasErrors('name');
    }

    public function test_reset_restores_defaults(): void
    {
        $this->actingAsUser($this->ceo)->post('/settings/branding', $this->form(['logo' => UploadedFile::fake()->image('ok.png', 512, 512), 'themeColor' => '#123456']))->assertSessionHas('ok');
        Settings::forget();
        $version = brand()->version();

        $this->actingAsUser($this->ceo)->post('/settings/branding/reset')->assertRedirect()->assertSessionHas('ok', t('branding.resetDone'));
        Settings::forget();
        $this->assertSame('Fluggi CRM', brand()->name());
        $this->assertSame('Fluggi', brand()->shortName());
        $this->assertSame(Brand::DEFAULT_THEME, brand()->themeColor());
        $this->assertNull(brand()->version());
        $this->assertDirectoryDoesNotExist(Brand::dir($version));
        $this->assertSame(1, AuditLog::where('action', 'settings.branding_reset')->count());

        $this->actingAsUser($this->ceo)->get('/dashboard')->assertOk()
            ->assertSee('/icons/icon-192.png?v='.Brand::DEFAULT_VERSION, false)
            ->assertSee('/icons/favicon-32.png', false)
            ->assertSee('· Fluggi CRM</title>', false);
        $json = $this->get('/app-manifest')->assertOk()->json();
        $this->assertSame('/icons/icon-512.png?v='.Brand::DEFAULT_VERSION, $json['icons'][1]['src']);
        // Стандартная иконка доступна и по адресу /brand/
        $this->get('/brand/icon-192')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_brand_route_rejects_unknown_files(): void
    {
        foreach (['/brand/evil', '/brand/..%2F..%2F.env', '/brand/icon-192.png', '/brand/', '/brand/favicon'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
        $this->assertFileDoesNotExist(public_path('manifest.webmanifest'), 'статический файл перекрыл бы маршрут');
    }
}
