<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncOutbox extends Model
{
    protected $table = 'sync_outbox';

    protected $fillable = [
        'entity_type',
        'local_id',
        'server_id',
        'operation',
        'payload',
        'attempts',
        'status',
        'error',
    ];
}
