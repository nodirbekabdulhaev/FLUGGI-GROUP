<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class StudentEvent extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];
    protected $casts = ['meta' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function log(Student|int $student, string $type, string $title, array $meta = []): self
    {
        return static::create([
            'student_id' => $student instanceof Student ? $student->id : $student,
            'user_id' => auth()->id(),
            'type' => $type,
            'title' => $title,
            'meta' => $meta ?: null,
        ]);
    }
}
