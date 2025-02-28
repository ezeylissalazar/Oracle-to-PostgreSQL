<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

use Livewire\Component;

class ModalStructure extends Component
{

    public $isOpen = false;
    public $tableName = '';
    public $oracleFields = []; // Para almacenar los campos de la tabla de Oracle
    public $newFields = []; // Para almacenar los nuevos nombres de los campos
    protected $listeners = ['name-table' => 'botonAdded'];

    #[On('name-table')]
    public function botonAdded($nameTable)
    {
        $this->tableName = $nameTable;

        // Realizar la consulta a la base de datos Oracle para obtener los campos de la tabla
        // Asegúrate de tener una conexión adecuada a tu base de datos Oracle configurada en config/database.php
        $oracleConnection = 'oracle'; // Ajusta esto según tu configuración de conexión
        $query = "SELECT column_name FROM all_tab_columns WHERE table_name = UPPER(?)"; // Consulta Oracle
        $fields = DB::connection($oracleConnection)->select($query, [$nameTable]);

        // Asignar los campos obtenidos a la propiedad $oracleFields
        $this->oracleFields = $fields;

        // Inicializar los inputs para los nuevos nombres de campo
        $this->newFields = array_map(function ($field) {
            return ['original' => $field->column_name, 'new' => '']; // Inicializamos los inputs vacíos
        }, $fields);

        $this->isOpen = true; // Abrir el modal
    }

    public function saveChanges()
    {
        // Guardar los nuevos nombres de los campos
        // Aquí puedes agregar la lógica para guardar los cambios en tu base de datos, por ejemplo:
        // foreach ($this->newFields as $field) {
        //     // Lógica para guardar $field['new'] en la base de datos
        // }
        // Una vez guardado, puedes cerrar el modal
        $this->isOpen = false;
    }

    public function render()
    {
        return view('livewire.modal-structure');
    }
}
