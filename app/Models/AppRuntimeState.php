<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRuntimeState extends Model
{
    protected $table = 'app_runtime_state';

    protected $fillable = [
        'has_completed_onboarding',
        'mode',
        'cloud_token_present',
        'active_store_id',
        'cloud_user_id',
        'cloud_token',
        'cloud_base_url',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'has_completed_onboarding' => 'boolean',
            'cloud_token_present' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
