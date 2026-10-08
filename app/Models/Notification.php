<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];
    protected $casts = ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'read_at' => 'datetime'];

    public const TYPES = [
        'lesson_reminder' => 'Напоминание о занятии',
        'payment_due' => 'Напоминание об оплате',
        'payment_received' => 'Оплата получена',
        'absence' => 'Пропуск занятия',
        'new_lead' => 'Новая заявка',
        'system' => 'Системное',
    ];

    public function recipient()
    {
        return $this->morphTo();
    }
}
