<?php

namespace Tests\Feature\Analytics;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

/** Встраиваемые блоки для экранов других модулей: экспорт, LTV клиента, пакет для бухгалтера, реквизиты. */
class PartialsTest extends TestCase
{
    use CommsFixtures;

    public function test_partials_render_with_rights(): void
    {
        $this->fixtures();
        $s = $this->sale($this->manager, 'Alfa');
        view()->share('errors', new ViewErrorBag);
        $this->as($this->ceo);
        $html = view('analytics.partials.export-menu', ['entity' => 'payments'])->render();
        $this->assertStringContainsString('/exports/payments?format=xlsx', $html);
        $this->assertStringContainsString('LTV', view('analytics.partials.client-insight', ['client' => $s['client']])->render());
        $this->assertStringContainsString('accountant-package', view('analytics.partials.accountant-button')->render());
        $this->assertStringContainsString(route('automation.company'), view('automation.company-card')->render());
        $this->as($this->manager);
        $this->assertSame('', trim(view('analytics.partials.export-menu', ['entity' => 'leads'])->render()));
        $this->assertSame('', trim(view('analytics.partials.accountant-button')->render()));
        $this->as($this->otherManager);
        $this->expectException(ModelNotFoundException::class);
        view('analytics.partials.client-insight', ['client' => $s['client']])->render();
    }
}
