<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TelegramAccount extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];
    protected $casts = ['linked_at' => 'datetime'];

    public function linkable()
    {
        return $this->morphTo();
    }

    public function isLinked(): bool
    {
        return $this->telegram_user_id !== null && $this->chat_id !== null;
    }

    public static function issueFor(Model $linkable): self
    {
        $acc = static::firstOrNew(['linkable_type' => $linkable->getMorphClass(), 'linkable_id' => $linkable->getKey()]);
        if (! $acc->isLinked() || ! $acc->token) {
            $acc->token = Str::lower(Str::random(16));
        }
        $acc->organization_id = $linkable->organization_id;
        $acc->save();

        return $acc;
    }
}
