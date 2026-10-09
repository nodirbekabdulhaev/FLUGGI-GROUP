<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка с формы (журнал): данные, UTM, страница; ссылка на созданный или найденный лид.
 */
class FormSubmission extends Model
{
    protected $table = 'form_submissions';

    public const UPDATED_AT = null;

    protected $casts = [
        'data' => 'array',
        'utm' => 'array',
        'created_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'form_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
