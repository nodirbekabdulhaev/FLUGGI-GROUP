<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** Telegram-канал для автопубликации SMM: бот — администратор с правом публиковать (проверка getChat + getChatMember). */
class SmmTelegramChannel extends Model
{
    use SoftDeletes;

    protected $table = 'smm_telegram_channels';

    protected $casts = [
        'can_post' => 'boolean',
        'checked_at' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** «@fluggi_uz» или числовой id. */
    public function handle(): string
    {
        return $this->username ? '@'.$this->username : $this->chat_id;
    }
}
