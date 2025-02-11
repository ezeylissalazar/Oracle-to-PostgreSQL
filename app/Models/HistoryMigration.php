<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoryMigration extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'history_migrations';

    protected $fillable = [
        'migrated_table',
        'fecha_migration',
        'cantidad_migracion',
        'updated_at',
    ];
}
