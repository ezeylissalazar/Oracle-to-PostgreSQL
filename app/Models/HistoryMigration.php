<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoryMigration extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'history_migrations';

    protected $fillable = [
        'migrated_table_pgsql',
        'migrated_table_oracle',
        'tipo_migracion',
    ];
}
