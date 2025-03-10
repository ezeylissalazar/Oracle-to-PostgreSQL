<?php

namespace App\Livewire;

use App\Http\Controllers\MigrationController;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

use Livewire\Component;

class ModalStructure extends Component
{

    public $isOpen = false;
    public $tableName;
    public $newTableName;
    public $postgresDDL;
    public $tableNameOracle;
    public $oracleFields = []; // Para almacenar los campos de la tabla de Oracle
    public $newFields = []; // Para almacenar los nuevos nombres de los campos
    protected $listeners = ['name-table' => 'botonAdded'];


    #[On('name-table')]
    public function botonAdded($nameTable)
    {
        // Obtener el DDL convertido a PostgreSQL
        $this->postgresDDL = '';
        $this->postgresDDL = app(MigrationController::class)->migrateStructureToPostgres($nameTable, true);
        $postgresDDL = $this->postgresDDL;
        // Extraer el nombre de la tabla convertida (lo que está después de 'create table "public"."')
        preg_match('/create table "public"\."(.*?)"/', $postgresDDL, $matches);
        $this->tableName = $matches[1] ?? $nameTable; // Si no se encuentra el nombre, usamos el original

        $this->newTableName = $this->tableName;
        $this->tableNameOracle = $nameTable;
        // Extraer los nombres de los campos convertidos (lo que está entre las comillas dobles)
        preg_match_all('/\t"(.*?)"/', $postgresDDL, $fieldMatches);
        $convertedFields = $fieldMatches[1];

        // Inicializar los inputs para los nuevos nombres de campo (solo usando PostgreSQL)
        $this->newFields = array_map(function ($field) use ($convertedFields) {
            return [
                'original' => $field, // El nombre del campo original
                'new' => $field,      // El nombre del campo convertido a PostgreSQL
            ];
        }, $convertedFields);

        // Abrir el modal
        $this->isOpen = true;
    }


    public function saveChanges()
    {
        $tableName = $this->tableName;
        $newTableName = $this->newTableName;
        $newFields = $this->newFields;
        $postgresDDL = $this->postgresDDL;
    
        foreach ($newFields as $index => $field) {
            // Si el campo es clave primaria
            if (strpos($postgresDDL, 'primary key ("' . $field['original'] . '"') !== false) {
                // Cambiar el nombre de la columna en la tabla
                $postgresDDL = preg_replace('/"public"\."' . preg_quote($field['original'], '/') . '"/', '"public"."' . $field['new'] . '"', $postgresDDL);
                // Cambiar el nombre de la columna en la clave primaria
                $postgresDDL = preg_replace('/primary key \("'. preg_quote($field['original'], '/') . '"\)/', 'primary key ("'. $field['new'] . '")', $postgresDDL);
            }
            // Si el campo es clave foránea
            elseif (strpos($postgresDDL, 'REFERENCES "public"."' . $field['original'] . '"') !== false) {
                // Solo cambiar el nombre de la columna en la clave foránea
                $postgresDDL = preg_replace('/"public"\."' . preg_quote($field['original'], '/') . '"/', '"public"."' . $field['new'] . '"', $postgresDDL);
            }
            // Si es un campo normal (ni clave primaria ni clave foránea)
            else {
                // Cambiar el nombre de la columna normal
                $postgresDDL = preg_replace('/"public"\."' . preg_quote($field['original'], '/') . '"/', '"public"."' . $field['new'] . '"', $postgresDDL);
            }
        }
    
        // Ver el resultado para debugging
        dd($postgresDDL, $newTableName, $tableName);
    
        // Desactivar el modal
        $this->isOpen = false;
    }

    public function render()
    {
        return view('livewire.modal-structure');
    }
}
