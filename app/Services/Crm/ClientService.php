<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Клиенты, их контакты и реквизиты для договора. */
final class ClientService
{
    public const FIELDS = ['name', 'type', 'industry', 'phone', 'email', 'telegram', 'website', 'city', 'country', 'source_id', 'comment'];

    public const TRACKED = ['name', 'type', 'phone', 'email', 'telegram', 'website', 'city', 'industry'];

    public const CONTACT_FIELDS = ['full_name', 'position', 'phone', 'telegram', 'whatsapp', 'instagram', 'email', 'is_primary'];

    /** Реквизиты клиента (поля — как у прежней версии, используются в договорах). */
    public const REQUISITES = [
        'legalName', 'inn', 'director', 'directorPosition', 'signerGenitive', 'basis',
        'address', 'phone', 'bank', 'mfo', 'account', 'oked', 'vatCode',
    ];

    public function create(array $input): Client
    {
        $owner = CrmAccess::assignableOwner($input['owner_id'] ?? null, 'client.create');

        return DB::transaction(function () use ($input, $owner) {
            $client = Client::create(array_intersect_key($input, array_flip(self::FIELDS)) + [
                'owner_id' => $owner->id,
                'team_id' => $owner->team_id,
            ]);
            if (! empty($input['contact']['full_name'])) {
                Contact::create(array_intersect_key($input['contact'], array_flip(self::CONTACT_FIELDS)) + [
                    'client_id' => $client->id,
                ] + ['is_primary' => true]);
            }
            Activities::log('client.created', ['client_id' => $client->id], ['name' => $client->name]);
            Audit::log('client.create', 'client', $client->id, ['name' => ['old' => null, 'new' => $client->name]]);

            return $client->refresh();
        });
    }

    public function update(Client $client, array $input): Client
    {
        $data = array_intersect_key($input, array_flip(self::FIELDS));

        return DB::transaction(function () use ($client, $data) {
            $old = $client->getAttributes();
            $client->update($data);
            $after = $client->fresh();
            $changes = Audit::diff($old, $after->getAttributes(), self::TRACKED);
            if ($changes) {
                Activities::log('client.updated', ['client_id' => $client->id], ['changes' => $changes]);
                Audit::log('client.update', 'client', $client->id, $changes);
            }

            return $after;
        });
    }

    public function addContact(Client $client, array $input): Contact
    {
        return DB::transaction(function () use ($client, $input) {
            if (! empty($input['is_primary'])) {
                Contact::where('client_id', $client->id)->update(['is_primary' => false]);
            }
            $contact = Contact::create(array_intersect_key($input, array_flip(self::CONTACT_FIELDS)) + ['client_id' => $client->id]);
            Audit::log('contact.create', 'contact', $contact->id, ['full_name' => ['old' => null, 'new' => $contact->full_name]]);

            return $contact;
        });
    }

    public function updateContact(Contact $contact, array $input): Contact
    {
        $data = array_intersect_key($input, array_flip(self::CONTACT_FIELDS));

        return DB::transaction(function () use ($contact, $data) {
            if (! empty($data['is_primary'])) {
                Contact::where('client_id', $contact->client_id)->where('id', '<>', $contact->id)->update(['is_primary' => false]);
            }
            $old = $contact->getAttributes();
            $contact->update($data);
            $after = $contact->fresh();
            $changes = Audit::diff($old, $after->getAttributes(), ['full_name', 'position', 'phone', 'telegram', 'email', 'is_primary']);
            if ($changes) {
                Audit::log('contact.update', 'contact', $contact->id, $changes);
            }

            return $after;
        });
    }

    public function removeContact(Contact $contact): void
    {
        if (Deal::withTrashed()->where('contact_id', $contact->id)->exists()) {
            throw new BusinessRule(t('clients.errors.contactInDeal'));
        }
        DB::transaction(function () use ($contact) {
            $contact->delete();
            Audit::log('contact.delete', 'contact', $contact->id, ['full_name' => ['old' => $contact->full_name, 'new' => null]]);
        });
    }

    public function saveRequisites(Client $client, array $input): array
    {
        $value = [];
        foreach (self::REQUISITES as $key) {
            $value[$key] = trim((string) ($input[$key] ?? ''));
        }

        return DB::transaction(function () use ($client, $value) {
            $before = $client->requisites;
            $client->update(['requisites' => $value]);
            Audit::log('client.requisites', 'client', $client->id, ['requisites' => ['old' => $before, 'new' => $value]]);

            return $value;
        });
    }

    /** Контакт клиента, видимого пользователю с правом $code (иначе 404). */
    public static function contact(string $id, string $code = 'client.update'): Contact
    {
        $contact = Contact::findOrFail($id);
        CrmAccess::client($contact->client_id, $code);

        return $contact;
    }
}
