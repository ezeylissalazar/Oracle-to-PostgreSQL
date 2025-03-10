<?php

namespace App\Livewire;

use App\Http\Controllers\MigrationController;
use App\Models\HistoryMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Attributes\On;

class ModalData extends Component
{

    public $isOpenData = false;
    public $isOpenDataSelect = false;
    public $oracleTableName = '';
    public $postgresTableName = '';
    public $postgresColumns = [];
    public $oracleColumns = [];
    protected $listeners = ['name-table-data' => 'botonAddedTable'];
    public $mappedColumns = [];
    public $defaultForeignKeys = [];
    public $oracleForeignKeys;
    public $postgresForeignKeys = [];
    public $foreignKeys;
    public $foreignKeyValues;
    public $selectedPgColumns = [];
    public $hiddenForeignKeys = [];


    #[On('name-table-data')]
    public function botonAddedTable($nameTableData)
    {
        $this->oracleTableName = $nameTableData;
        $this->isOpenData = true;
    }

    public function saveChangesData()
    {
        // Validar el campo antes de continuar
        $this->validate([
            'postgresTableName' => 'required|string|min:1',
        ], [
            'postgresTableName.required' => 'El nombre de la tabla en PostgreSQL es obligatorio.',
        ]);

        // Si pasa la validación, cerrar el modal y cargar datos
        $this->loadColumns();
    }


    public function loadColumns()
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        // Obtener columnas de la tabla en Oracle
        $this->oracleColumns = DB::connection('oracle')
            ->select("SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE TABLE_NAME = UPPER('$this->oracleTableName') AND OWNER = '$schema'");

        // Obtener columnas de la tabla en PostgreSQL
        $this->postgresColumns = DB::connection('pgsql')
            ->select("SELECT column_name FROM information_schema.columns WHERE table_name = LOWER('$this->postgresTableName')");

        $errors = false;
        if (empty($this->oracleColumns)) {
            $this->oracleColumns = [];
            $this->postgresColumns = [];
            $this->addError('oracleTableNameData', "No se encontraron datos para la tabla  en Oracle.");
            $errors = true;
        }

        if (empty($this->postgresColumns)) {
            $this->postgresColumns = [];
            $this->oracleColumns = [];
            $this->addError('postgresTableNameData', "No se encontraron datos para la tabla en PostgreSQL.");
            $errors = true;
        }

        // Si hay errores, detener la ejecución y NO abrir el modal
        if ($errors == false) {
            $this->isOpenData = false;

            // Convertir resultados a un array simple
            $this->oracleColumns = array_column($this->oracleColumns, 'column_name');
            $this->postgresColumns = array_column($this->postgresColumns, 'column_name');

            // Obtener las claves foráneas en PostgreSQL
            $this->postgresForeignKeys = DB::connection('pgsql')
                ->select("
            SELECT 
                kcu.column_name
            FROM 
                information_schema.key_column_usage AS kcu
                JOIN information_schema.table_constraints AS tc 
                    ON kcu.constraint_name = tc.constraint_name
            WHERE 
                kcu.table_name = LOWER('$this->postgresTableName') 
                AND kcu.table_schema = 'public'
                AND tc.constraint_type = 'FOREIGN KEY'");

            // Transformar las claves foráneas de PostgreSQL a un array simple
            $this->postgresForeignKeys = array_column($this->postgresForeignKeys, 'column_name');

            // Inicializar los valores por defecto para claves foráneas
            foreach ($this->postgresForeignKeys as $foreignKey) {
                $this->foreignKeyValues[$foreignKey] = null;
            }

            // Abrir modal de selección de campos
            $this->isOpenDataSelect = true;
        } else {
            $this->isOpenData = true;
        }
    }


    public function saveChangesDataProcess()
    {
        try {

            $tableOracle = $this->oracleTableName;
            $tablePgsql = $this->postgresTableName;
            $this->migrateDataExist($tableOracle, $tablePgsql);
            return redirect()->route('migration.index')->with('success', "Datos de la tabla {$tablePgsql} migrados correctamente.");
        } catch (\Exception $e) {
            return redirect()->route('migration.index')->with('error', "EError al migrar datos de la tabla" . $e->getMessage());
        }
    }


    public function migrateDataExist($tableOracle, $tablePgsql)
    {
        try {
            $tipo = "Migracion de Datos";
            $mappedColumns = $this->mappedColumns;
            $oracleColumns = array_keys($mappedColumns);
            $oracleData = DB::connection('oracle')->table($tableOracle)->select($oracleColumns)->get();

            if ($oracleData->isEmpty()) {
                throw new \Exception("No hay datos para migrar en la tabla {$tableOracle}.");
            }

            $pgsqlColumns = Schema::connection('pgsql')->getColumnListing($tablePgsql);
            $columnsInfo = DB::connection('pgsql')->select("
            SELECT 
                c.column_name, 
                c.data_type, 
                c.is_nullable,
                c.table_name,
                CASE 
                    WHEN tc.constraint_type = 'FOREIGN KEY' THEN true 
                    ELSE false 
                END AS is_foreign_key,
                ccu.table_name AS referenced_table, 
                ccu.column_name AS referenced_column
            FROM information_schema.columns c
            LEFT JOIN information_schema.key_column_usage kcu 
                ON c.column_name = kcu.column_name AND c.table_name = kcu.table_name
            LEFT JOIN information_schema.table_constraints tc 
                ON kcu.constraint_name = tc.constraint_name AND tc.constraint_type = 'FOREIGN KEY'
            LEFT JOIN information_schema.constraint_column_usage ccu 
                ON tc.constraint_name = ccu.constraint_name
            WHERE c.table_name = ?
        ", [$tablePgsql]);



            $columnTypes = [];
            foreach ($columnsInfo as $column) {
                $columnTypes[$column->column_name] = $column->data_type;
            }

            $nonNullableForeignKeys = [];
            $nonNullableData = [];
            $foreignKeyReferences = [];

            foreach ($columnsInfo as $column) {
                $columnTypes[$column->column_name] = $column->data_type;

                if ($column->is_nullable === 'NO' && $column->is_foreign_key === true) {
                    $nonNullableForeignKeys[] = $column->column_name;
                    // Guardar la referencia de la clave foránea
                    $foreignKeyReferences[$column->column_name] = [
                        'table' => $column->referenced_table,
                        'column' => $column->referenced_column
                    ];
                }
                if ($column->is_nullable === 'NO') {
                    $nonNullableData[] = $column->column_name;
                }
            }
            $foreignKeyValues = $this->foreignKeyValues ?? [];
            $insertData = [];
            foreach ($oracleData as $row) {
                $newRow = [];
                $exists = true;

                foreach ($pgsqlColumns as $pgColumn) {
                    $oracleField = array_search($pgColumn, $mappedColumns);
                    if ($oracleField !== false) {
                        $oracleFieldLower = strtolower($oracleField);
                        $newRow[$pgColumn] = $row->$oracleFieldLower;

                        // VALOR POR DEFECTO PARA CAMPOS NOT NULL
                        if (in_array($pgColumn, $nonNullableData)) {
                            if ($newRow[$pgColumn] == null) {
                                $newRow[$pgColumn] = 0;
                            }
                        }
                    } elseif (in_array($pgColumn, ['created_at', 'updated_at'])) {
                        $newRow[$pgColumn] = now();
                    } elseif (array_key_exists($pgColumn, $foreignKeyValues)) {
                        $newRow[$pgColumn] = $foreignKeyValues[$pgColumn];
                    } else {
                        if (isset($columnTypes[$pgColumn]) && $columnTypes[$pgColumn] === 'boolean') {
                            $newRow[$pgColumn] = true;
                        } else {
                            $newRow[$pgColumn] = null;
                        }
                    }

                    // **Validar si hay claves foráneas nulas que no lo permiten**
                    foreach ($nonNullableForeignKeys as $foreignKey) {

                        if (array_key_exists($foreignKey, $newRow)) {
                            if (is_null($newRow[$foreignKey])) {
                                return redirect()->route('migration.index')->with('error', "La clave foránea '{$foreignKey}' no puede ser nula. Debe ingresar un valor por defecto.");
                            }
                        }
                        if (in_array($pgColumn, $nonNullableForeignKeys)) {
                            if (!$this->validateForeignKey($pgColumn, $newRow[$pgColumn], $foreignKeyReferences)) {
                                return redirect()->route('migration.index')->with('error', "Error: El valor '{$newRow[$pgColumn]}' en la clave foránea '{$pgColumn}' no existe en la tabla referenciada. Registre el valor o ingrese uno por defecto");
                            }
                        }
                    }
                }


                if (is_null($newRow['id'])) {
                    unset($newRow['id']);

                    // Excluimos las columnas 'id', 'created_at' y 'updated_at'
                    $filteredRow = array_diff_key($newRow, ['id' => null, 'created_at' => null, 'updated_at' => null]);

                    // Verificar si la fila ya existe en la base de datos PostgreSQL
                    $exists = DB::connection('pgsql')
                        ->table($tablePgsql)
                        ->where($filteredRow) // Comparar todas las columnas de la fila excepto 'id', 'created_at', 'updated_at'
                        ->exists();

                    if ($exists) {
                        $exists = true;
                    } else {
                        $exists = false;
                    }
                } else {
                    $exists = false;
                }
                $insertData[] = $newRow;
                
                // Cuando alcance 1000 registros, hacer la inserción y limpiar el array
                if (count($insertData) >= 1000) {
                    if (!$exists) {
                        DB::connection('pgsql')->table($tablePgsql)->upsert(
                            $insertData,
                            ['id'],
                            array_diff($pgsqlColumns, ['id', 'created_at'])
                        );
                        $insertData = []; // Limpiar para la siguiente tanda
                    }
                }
                if (!$exists) {
                    DB::connection('pgsql')->table($tablePgsql)->upsert(
                        $insertData,
                        ['id'],
                        array_diff($pgsqlColumns, ['id', 'created_at'])
                    );
                    $insertData = []; // Limpiar para la siguiente tanda
                }
            }

            // Insertar cualquier dato restante
            if (!empty($insertData)) {
                if (!$exists) {
                    DB::connection('pgsql')->table($tablePgsql)->upsert(
                        $insertData,
                        ['id'],
                        array_diff($pgsqlColumns, ['id', 'created_at'])
                    );
                }
            }

            $this->registerMigration($tablePgsql, $tableOracle, $tipo);
            return redirect()->route('migration.index')->with('success', "Datos migrados correctamente a {$tablePgsql}.");
        } catch (\Exception $e) {
            return redirect()->route('migration.index')->with('error', "Error al migrar datos: " . $e->getMessage());
        }
    }

    private function validateForeignKey($column, $value, $foreignKeyReferences)
    {
        if (!isset($foreignKeyReferences[$column])) {
            return true;
        }

        $referencedTable = $foreignKeyReferences[$column]['table'];
        $referencedColumn = $foreignKeyReferences[$column]['column'];

        $exists = DB::connection('pgsql')->table($referencedTable)
            ->where($referencedColumn, $value)
            ->exists();

        return $exists;
    }

    public function updateSelection()
    {
        // Recalcular los valores seleccionados para deshabilitarlos en otros select
        $this->selectedPgColumns = array_values(array_filter($this->mappedColumns));

        // Ocultar inputs de claves foráneas si ya están seleccionadas en el mapeo
        $this->hiddenForeignKeys = array_intersect($this->postgresForeignKeys, $this->selectedPgColumns);
    }

    public function registerMigration($tableNamePgsql, $tableNameOracle, $tipo)
    {
        try {
            HistoryMigration::create([
                'migrated_table_pgsql' => $tableNamePgsql,
                'migrated_table_oracle' => $tableNameOracle,
                'tipo_migracion' => $tipo,
            ]);
        } catch (\Exception $e) {
            return redirect()->route('migration.index')->with('error', 'Error al insertar en histórico de migración: ' . $e->getMessage());
        }
    }

    public function render()
    {
        // return view('livewire.modal-data');
        return view('livewire.modal-data', [
            'selectedPgColumns' => $this->selectedPgColumns ?? [],
        ]);
    }
}
