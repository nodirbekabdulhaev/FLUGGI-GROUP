<?php

namespace App\Services\Import;

use App\Models\LeadSource;
use App\Services\Crm\ClientService;
use App\Services\Crm\Crm;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\LeadService;
use App\Support\Format;

/**
 * Клиенты (и основной контакт): создаются через ClientService::create — ответственный по тем же
 * правилам, таймлайн и аудит. Дубли по телефону, email или названию (без учёта регистра)
 * среди видимых клиентов и внутри файла пропускаются.
 */
final class ClientImporter extends Importer
{
    public static function entity(): string
    {
        return 'clients';
    }

    public static function permission(): string
    {
        return 'client.create';
    }

    public static function backUrl(): string
    {
        return '/clients';
    }

    protected function columns(): array
    {
        return [
            'name' => ['required' => true],
            'client_type' => [],
            'industry' => [],
            'phone' => ['type' => 'phone'],
            'email' => ['type' => 'email'],
            'telegram' => [],
            'website' => [],
            'city' => [],
            'country' => [],
            'source' => [],
            'owner_email' => ['type' => 'email'],
            'comment' => [],
            'contact_name' => [],
            'contact_position' => [],
            'contact_phone' => ['type' => 'phone'],
            'contact_email' => ['type' => 'email'],
        ];
    }

    public function rules(): array
    {
        return [t('import.rules.clientDuplicates'), t('import.rules.owner')];
    }

    protected function example(): array
    {
        return [
            'name' => t('import.example.company'), 'client_type' => 'COMPANY', 'industry' => t('import.example.industry'),
            'phone' => '+998 71 200-00-00', 'email' => 'office@example.uz', 'telegram' => '@example', 'website' => 'example.uz',
            'city' => t('import.example.city'), 'country' => t('import.example.country'), 'source' => 'REFERRAL', 'owner_email' => '',
            'comment' => t('import.example.comment'), 'contact_name' => t('import.example.contactName'),
            'contact_position' => t('import.example.position'), 'contact_phone' => '+998 90 123-45-67', 'contact_email' => 'director@example.uz',
        ];
    }

    protected function reference(): array
    {
        return [
            $this->col('client_type') => self::enumRows(Crm::CLIENT_TYPES, 'crm.clientType'),
            $this->col('source') => self::refRows(LeadSource::where('is_active', true)->orderBy('sort')->get()),
            $this->col('owner_email') => self::assignable('client.create'),
        ];
    }

    protected function label(array $v): string
    {
        return (string) ($v['name'] ?? '');
    }

    protected function check(array $v, ImportContext $ctx): array
    {
        $data = [
            'name' => Values::text($v['name'], 160, $this->col('name')),
            'type' => Values::enum($v['client_type'], Crm::CLIENT_TYPES, 'crm.clientType', $this->col('client_type')) ?? 'COMPANY',
            'industry' => Values::text($v['industry'], 120, $this->col('industry')),
            'phone' => Values::phone($v['phone'], $this->col('phone')),
            'email' => Values::email($v['email'], $this->col('email')),
            'telegram' => Values::text($v['telegram'], 64, $this->col('telegram')),
            'website' => Values::text($v['website'], 200, $this->col('website')),
            'city' => Values::text($v['city'], 80, $this->col('city')),
            'country' => Values::text($v['country'], 80, $this->col('country')),
            'source_id' => Values::ref($v['source'], $this->sources($ctx), $this->col('source'))?->id,
            'comment' => Values::text($v['comment'], 4000, $this->col('comment')),
        ];
        $contact = [
            'full_name' => Values::text($v['contact_name'], 120, $this->col('contact_name')),
            'position' => Values::text($v['contact_position'], 120, $this->col('contact_position')),
            'phone' => Values::phone($v['contact_phone'], $this->col('contact_phone')),
            'email' => Values::email($v['contact_email'], $this->col('contact_email')),
        ];
        if (! $contact['full_name'] && ($contact['position'] || $contact['phone'] || $contact['email'])) {
            throw new RowError(t('import.errors.contactName'));
        }
        if ($contact['full_name']) {
            $data['contact'] = $contact;
        }
        $data['owner_id'] = $this->owner($v['owner_email'], 'client.create')?->id;

        $keys = [
            'name:'.mb_strtolower($data['name']),
            ($p = Crm::phoneKey($data['phone'])) ? 'phone:'.$p : null,
            ($em = Crm::emailKey($data['email'])) ? 'email:'.$em : null,
        ];
        $found = $this->existing($data);
        if ($found) {
            return ['status' => 'duplicate', 'reason' => t('import.reasons.duplicateOf', ['list' => implode(', ', $found)])];
        }

        return ['status' => 'create', 'data' => $data, 'keys' => $keys];
    }

    /** Видимые клиенты с тем же названием, телефоном или email. */
    private function existing(array $data): array
    {
        $clients = CrmAccess::clients()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($data['name'])])->limit(3)->get();
        if ($data['phone'] || $data['email']) {
            $clients = $clients->merge(LeadService::duplicates($data['phone'], null, $data['email'])['clients']);
        }

        return $clients->unique('id')->take(3)->map(fn ($c) => Format::code('C', $c->number).' «'.$c->name.'»')->values()->all();
    }

    protected function create(array $data, ImportContext $ctx): string
    {
        $client = app(ClientService::class)->create($data);

        return Format::code('C', $client->number);
    }
}
