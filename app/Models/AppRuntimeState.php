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
        'bootstrap_status',
        'bootstrap_progress_percent',
        'bootstrap_progress_label',
        'bootstrap_generation',
        'last_delta_pull_at',
        'last_delta_push_at',
        'store_sync_states',
    ];

    protected function casts(): array
    {
        return [
            'has_completed_onboarding' => 'boolean',
            'cloud_token_present' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_delta_pull_at' => 'datetime',
            'last_delta_push_at' => 'datetime',
            'store_sync_states' => 'array',
        ];
    }
}
