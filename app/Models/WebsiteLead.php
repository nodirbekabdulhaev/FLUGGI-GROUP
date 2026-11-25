<?php

namespace App\Models;

/** Принятая заявка с сайта (защита от дублей по external_id). */
class WebsiteLead extends Model
{
    protected $table = 'website_leads';

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}
