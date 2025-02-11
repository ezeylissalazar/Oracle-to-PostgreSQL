<?php

namespace App\Http\Controllers;

use App\Models\HistoryMigration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $schema = env('DB_SCHEMA_PREFIX', 'SYSTEM');

        // Consulta base para obtener las tablas de Oracle
        $oracleTablesQuery = DB::connection('oracle')
        ->table(DB::raw('ALL_TABLES'))
        ->select(DB::raw('"OWNER" AS schema_name, "TABLE_NAME" AS table_name'))
        ->where(DB::raw('"OWNER"'), '=', $schema)
        ->orderBy(DB::raw('"OWNER"')) // Aquí quitamos alias incorrectos
        ->orderBy(DB::raw('"TABLE_NAME"'));
    
    

        // Si hay una búsqueda, agregamos filtros adicionales con where()
        if ($search) {
            $oracleTablesQuery->where(function ($query) use ($search) {
                $query->where(DB::raw('LOWER(ac.owner)'), 'like', '%' . strtolower($search) . '%')
                    ->orWhere(DB::raw('LOWER(ac.table_name)'), 'like', '%' . strtolower($search) . '%');
            });
        }

        $oracleTables = $oracleTablesQuery->paginate(10);

        // Obtener las columnas de cada tabla
        $columns = [];
        foreach ($oracleTables as $table) {
            $columns[$table->table_name] = DB::connection('oracle')->select("
                SELECT column_name 
                FROM all_tab_columns 
                WHERE table_name = :table_name 
                AND owner = :schema
                ORDER BY column_id
            ", ['table_name' => $table->table_name, 'schema' => $schema]);
        }

        // Obtener las tablas migradas desde PostgreSQL (los nombres de tablas estarán en minúsculas)
        $migratedTables = DB::connection('pgsql')
            ->table('history_migrations')
            ->pluck('migrated_table')
            ->map(fn($table) => strtolower($table)) // Convertir a minúsculas
            ->toArray();

        // Obtener las columnas de cada tabla migrada en PostgreSQL
        $pgsqlColumns = [];
        foreach ($migratedTables as $tableName) {
            $pgsqlColumns[$tableName] = DB::connection('pgsql')->select("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE LOWER(table_name) = LOWER(:table_name) 
        AND table_schema = 'public'
        ORDER BY ordinal_position
    ", ['table_name' => strtolower($tableName)]);
        }


        return view('welcome', compact('oracleTables', 'columns', 'migratedTables', 'pgsqlColumns'));
    }

    public function migrate($table)
    {
        try {
            DB::beginTransaction();

            $tableName = $table;
            $tableNameMin = strtolower($table);

            $tableExists = DB::connection('pgsql')->table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $tableNameMin)
                ->exists();

            if (!$tableExists) {
                // Si la tabla no existe, crearla
                $columns = DB::connection('oracle')->select("SELECT column_name, data_type FROM all_tab_columns WHERE table_name = '$tableName'");
                $foreignKeys = DB::connection('oracle')->select("SELECT acc.column_name AS column_name, refc.table_name AS referenced_table, refc.column_name AS referenced_column FROM all_cons_columns acc JOIN all_constraints ac ON ac.constraint_name = acc.constraint_name JOIN all_cons_columns refc ON refc.constraint_name = ac.r_constraint_name WHERE ac.constraint_type = 'R' AND ac.table_name = '$tableName'");

                // Obtener la clave primaria
                $primaryKeyColumns = DB::connection('oracle')->select("
                SELECT column_name
                FROM all_cons_columns 
                WHERE table_name = '$tableName' AND constraint_name IN (
                SELECT constraint_name 
                FROM all_constraints 
                WHERE table_name = '$tableName' AND constraint_type = 'P'
                    )   ");
                // $primaryKey = DB::connection('oracle')->select("SELECT column_name FROM all_cons_columns WHERE table_name = '$tableName' AND constraint_name IN (SELECT constraint_name FROM all_constraints WHERE table_name = '$tableName' AND constraint_type = 'P')");
                $createTableSQL = "CREATE TABLE public.\"$tableNameMin\" (";
                $columnDefinitions = [];

                foreach ($columns as $column) {
                    $columnName = strtolower($column->column_name);
                    $dataType = $this->mapOracleDataTypeToPgsql($column->data_type);
                    $columnDefinitions[] = "\"$columnName\" $dataType";
                }
                $createTableSQL .= implode(", ", $columnDefinitions);

                if (!empty($primaryKeyColumns)) {
                    $primaryKeyColumnNames = array_map(function ($col) {
                        return strtolower($col->column_name);
                    }, $primaryKeyColumns);

                    // Crear la definición de la clave primaria dependiendo de la cantidad de columnas
                    $createTableSQL .= ", PRIMARY KEY (" . implode(", ", array_map(fn($col) => "\"$col\"", $primaryKeyColumnNames)) . ")";
                }


                // Agregar las claves foráneas
                foreach ($foreignKeys as $foreignKey) {
                    $columnName = strtolower($foreignKey->column_name);
                    $referencedTable = strtolower($foreignKey->referenced_table);
                    $referencedColumn = strtolower($foreignKey->referenced_column);

                    $createTableSQL .= ", CONSTRAINT fk_{$columnName}_{$referencedTable} FOREIGN KEY (\"$columnName\") REFERENCES public.\"$referencedTable\"(\"$referencedColumn\")";
                }

                $createTableSQL .= ");";

                DB::connection('pgsql')->statement($createTableSQL);
            }

            $oracleData = DB::connection('oracle')->table($table)->get();

            // Verifica cuántos registros están siendo recuperados desde Oracle
            $oracleDataCount = $oracleData->count();

            foreach ($oracleData as $data) {
                $dataArray = (array) $data;

                $this->updateOrInsertData($tableNameMin, $dataArray);
            }
            // GUARDA REGISTRO DE MIGRACION
            $this->registerMigration($tableNameMin);


            DB::commit();

            return redirect()->route('migration.index')->with('success', 'Migración completada');
        } catch (\Exception $e) {
            DB::rollBack();
            $errorMessage = $e->getMessage();
            $error = $this->extractError($errorMessage);
            return redirect()->route('migration.index')->with('error', 'Error migracion: ' . $error);
        }
    }

    private function extractError($errorMessage)
    {
        // Caso específico: error con columna
        if (preg_match('/no existe la columna\s*["`]?(\w+)["`]?/i', $errorMessage, $matches)) {
            return 'Error: Columna no válida - ' . $matches[1];
        }
        // Caso específico: error con la relación (tabla)
        elseif (preg_match('/no existe la relación\s*["`]?(\w+)["`]?/i', $errorMessage, $matches)) {
            return 'Error: Tabla no válida - ' . $matches[1];
        }
        // Caso general: capturar solo la primera línea del error sin el SQL
        elseif (preg_match('/^ERROR:\s*(.*?)(\n|$)/', $errorMessage, $matches)) {
            return 'Error: ' . $matches[1];
        }
        // Si no se captura nada, devolvemos una parte del mensaje original
        else {
            return 'Error: ' . substr($errorMessage, 0, 100) . '...';
        }
    }
    
    

    public function registerMigration($tableName)
    {
        try {
            // Buscar si la tabla ya fue migrada antes
            $migration = HistoryMigration::where('migrated_table', $tableName)->first();

            if ($migration) {
                $migration->update([
                    'updated_at' => now(),
                    'cantidad_migracion' => $migration->cantidad_migracion + 1,
                ]);
            } else {
                HistoryMigration::create([
                    'migrated_table' => $tableName,
                    'fecha_migration' => now(),
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $error = $this->extractError($errorMessage);
            return redirect()->route('migration.index')->with('error', 'Error al insertar en histórico de migración: ' . $error);
        }
    }

    public function updateOrInsertData($tableName, $data)
    {
        // Obtener todas las columnas que forman la clave primaria
        $primaryKeyColumns = DB::connection('pgsql')->select(" 
        SELECT column_name
        FROM information_schema.key_column_usage
        WHERE table_name = :tableName AND constraint_name = (
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_name = :tableName AND constraint_type = 'PRIMARY KEY'
        )", ['tableName' => $tableName]);

        // Crear un array con los nombres de las columnas clave primaria
        $primaryKeyColumnNames = array_map(function ($col) {
            return $col->column_name;
        }, $primaryKeyColumns);

        // Crear un array con los valores de las columnas clave primaria
        $primaryKeyValues = array_intersect_key($data, array_flip($primaryKeyColumnNames));

        // Verificar si el registro ya existe usando las columnas clave primaria
        $existingRecord = DB::connection('pgsql')->table($tableName)
            ->where($primaryKeyValues)
            ->first();

        if ($existingRecord) {
            // Si el registro existe, actualizarlo
            DB::connection('pgsql')->table($tableName)
                ->where($primaryKeyValues)
                ->update($data);
        } else {
            // Si no existe, insertarlo
            DB::connection('pgsql')->table($tableName)
                ->insert($data);
        }
    }



    private function mapOracleDataTypeToPgsql($oracleDataType)
    {
        $mapping = [
            'VARCHAR2'     => 'VARCHAR',
            'CHAR'         => 'CHAR',
            'CLOB'         => 'TEXT',
            'NUMBER'       => 'INTEGER', 
            'NUMBER(1)'    => 'BOOLEAN', 
            'NUMBER(10)'   => 'BIGINT', 
            'NUMBER(*,*)'  => 'NUMERIC', 
            'FLOAT'        => 'FLOAT',
            'BINARY_FLOAT' => 'REAL',
            'BINARY_DOUBLE' => 'DOUBLE PRECISION',
            'DATE'         => 'TIMESTAMP',
            'TIMESTAMP'    => 'TIMESTAMP',
            'TIMESTAMP(6)' => 'TIMESTAMP', 
            'INTERVAL'     => 'INTERVAL',
            'BOOLEAN'      => 'BOOLEAN',
            'BLOB'         => 'BYTEA',
            'RAW'          => 'BYTEA',
            'LONG'         => 'TEXT',
            'LONG RAW'     => 'BYTEA',
            'ROWID'        => 'TEXT',
            'XMLTYPE'      => 'XML',
            'BINARY'       => 'BYTEA', 
        ];

        return $mapping[strtoupper($oracleDataType)];
    }
}
