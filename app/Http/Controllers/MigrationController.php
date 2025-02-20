<?php

namespace App\Http\Controllers;

use App\Models\HistoryMigration;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MigrationController extends Controller
{

    public function index(Request $request)
    {
        $search = $request->input('search');

        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

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



        return view('migration', compact('oracleTables', 'columns', 'migratedTables', 'pgsqlColumns'));
    }

    public function migrateData($table)
    {

        try {
            $tipo = 'Migracion Data';
            $table = $this->toLowerCase($table);
            // Obtener la clave primaria de la tabla
            $primaryKeyQuery = DB::connection('pgsql')->select("
        SELECT column_name
        FROM information_schema.key_column_usage
        WHERE table_name = :table
        AND constraint_name = (
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_name = :table
            AND constraint_type = 'PRIMARY KEY'
            LIMIT 1
        )
        ", ['table' => $table]);

            // Verificar si encontramos la clave primaria
            if (empty($primaryKeyQuery)) {
                throw new \Exception("No se encontró una clave primaria para la tabla {$table}");
            }

            $primaryKeyColumn = $primaryKeyQuery[0]->column_name;
            $oracleData = DB::connection('oracle')->select("SELECT * FROM " . $this->toUpperCase($table));

            // Suponiendo que $oracleData contiene los datos que estamos migrando
            foreach ($oracleData as $row) {
                $rowArray = (array) $row;
                $rowArray = array_change_key_case($rowArray, CASE_LOWER); // Convertir claves a minúsculas

                // Obtener claves y valores
                $columns = array_keys($rowArray);
                $values = array_values($rowArray);

                // Construcción dinámica de la sentencia INSERT ON CONFLICT
                $updateSet = implode(', ', array_map(fn($col) => "$col = excluded.$col", $columns));
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $columnNames = implode(', ', $columns);

                // Usar la clave primaria para la cláusula ON CONFLICT
                $sql = "INSERT INTO {$table} ({$columnNames}) VALUES ({$placeholders}) 
                ON CONFLICT ({$primaryKeyColumn}) DO UPDATE SET {$updateSet};";

                // Ejecutar la consulta con los valores
                DB::connection('pgsql')->statement($sql, $values);
                $this->registerMigration($table, $tipo);
            }
            return back()->with('success', "Datos de la tabla {$table} migrados correctamente.");
        } catch (\Exception $e) {
            return back()->with('error', "Error al migrar datos de la tabla {$table}: " . $e->getMessage());
        }
    }

    private function toLowerCase($name)
    {
        return strtolower($name); // Convierte el nombre a minúsculas
    }

    private function toUpperCase($name)
    {
        return strtoupper($name); // Convierte el nombre a mayúsculas
    }


    public function tableExists($tableName)
    {
        // Consultar si la tabla existe en PostgreSQL en el esquema 'public'
        $result = DB::connection('pgsql')->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_name = :table_name AND table_schema = 'public'
        ", ['table_name' => strtolower($tableName)]);

        // Si la tabla existe, devuelve true
        return count($result) > 0;
    }

    public function getPostgresTableDDL($tableName)
    {
        $schema = 'public';
        $tableName = $this->toLowerCase($tableName);

        // Consulta para obtener las columnas de la tabla en PostgreSQL
        $columns = DB::connection('pgsql')->select("
            SELECT column_name, data_type, character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = :schema
            AND table_name = :table_name
        ", ['table_name' => $tableName, 'schema' => $schema]);

        // Generar el DDL basado en las columnas
        $ddl = "CREATE TABLE $schema.$tableName (";

        foreach ($columns as $column) {
            $ddl .= "\n    " . $column->column_name . " " . $column->data_type;
            if (isset($column->character_maximum_length)) {
                $ddl .= "($column->character_maximum_length)";
            }
            $ddl .= ",";
        }

        // Eliminar la última coma y cerrar la definición de la tabla
        $ddl = rtrim($ddl, ',') . "\n);";

        return $ddl;
    }


    // Obtener el DDL de la tabla en Oracle
    public function getTableDDL($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        // Consulta que obtiene el DDL completo de la tabla en Oracle
        $ddlQuery = DB::connection('oracle')->select("
            SELECT dbms_metadata.get_ddl('TABLE', :table_name, :schema) AS ddl
            FROM dual
        ", ['table_name' => strtoupper($tableName), 'schema' => strtoupper($schema)]);

        return $ddlQuery[0]->ddl;
    }

    public function convertOracleToPostgres($oracleDDL)
    {
        // Convertir todo a minúsculas para manejar las convenciones de PostgreSQL
        $postgresDDL = strtolower($oracleDDL);
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        // Reemplazar el esquema por "public" en PostgreSQL
        $postgresDDL = str_ireplace($schema, 'public', $postgresDDL);

        // Eliminar parámetros de Oracle no válidos en PostgreSQL
        $postgresDDL = preg_replace('/PCTUSED\s*\d+/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/NOCOMPRESS/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/LOGGING/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/SHARING\s*=\s*METADATA/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/PCTFREE\s*\d+/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/INITRANS\s*\d+/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/MAXTRANS\s*\d+/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/COMPUTE\s*STATISTICS/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/STORAGE\s*\([^\)]*\)/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/TABLESPACE\s*["\w]+/i', '', $postgresDDL);
        $postgresDDL = preg_replace('/\bNUMBER\b/i', 'NUMERIC', $postgresDDL);

        // Reemplazar tipos de datos y otras sintaxis específicas de Oracle
        $postgresDDL = preg_replace('/NUMBER\((\d+),0\)/i', 'BIGINT', $postgresDDL); // Oracle NUMBER(19,0) -> PostgreSQL BIGINT
        $postgresDDL = preg_replace('/NUMBER\((\d+),(\d+)\)/i', 'NUMERIC($1,$2)', $postgresDDL); // NUMBER con decimales -> NUMERIC
        $postgresDDL = str_ireplace('VARCHAR2', 'VARCHAR', $postgresDDL);
        $postgresDDL = str_ireplace('RAW(16)', 'BYTEA', $postgresDDL);
        $postgresDDL = str_ireplace('RAW', 'BYTEA', $postgresDDL);
        $postgresDDL = str_ireplace('DATE', 'TIMESTAMP', $postgresDDL);

        // Conversión para CLOB y BLOB de Oracle
        $postgresDDL = str_ireplace('CLOB', 'TEXT', $postgresDDL); // Oracle CLOB -> PostgreSQL TEXT
        $postgresDDL = str_ireplace('BLOB', 'BYTEA', $postgresDDL); // Oracle BLOB -> PostgreSQL BYTEA

        // Eliminar la sintaxis LOB de Oracle, ya que no se utiliza en PostgreSQL
        $postgresDDL = preg_replace('/\s*lob\s*\([^\)]+\)\s*store\s*as\s*basicfile\s*\([^\)]*\)/i', '', $postgresDDL);

        // Convertir comillas dobles de Oracle a minúsculas en PostgreSQL
        $postgresDDL = preg_replace_callback('/"([^"]+)"/', function ($matches) {
            return '"' . strtolower($matches[1]) . '"';
        }, $postgresDDL);


        // Manejo de índices y restricciones
        $postgresDDL = preg_replace('/USING\s*INDEX\s*/i', '', $postgresDDL);
        $postgresDDL = str_ireplace('ENABLE', '', $postgresDDL);

        // Eliminar restricciones redundantes y asegurarse de que la sintaxis sea compatible con PostgreSQL
        $postgresDDL = str_ireplace('CONSTRAINT IF NOT EXISTS', 'CONSTRAINT IF NOT EXISTS', $postgresDDL);

        // **Eliminar la clave foránea que hace referencia a SYSTEM.USERS**
        $postgresDDL = preg_replace('/CONSTRAINT\s+\w+\s+FOREIGN KEY\s*\(\s*user_id\s*\)\s*REFERENCES\s*"public"\.users\s*\(\s*id\s*\)\s*ON DELETE CASCADE/i', '', $postgresDDL);
        $postgresDDL = str_ireplace('uptimestampd_at', 'updated_at', $postgresDDL);

        return $postgresDDL;
    }

    public function normalizeDDL($ddl)
    {
        // Elimina los saltos de línea y espacios extra
        $ddl = preg_replace('/\s+/', ' ', $ddl);

        // Elimina las comillas alrededor de los nombres de columnas
        $ddl = preg_replace('/"(\w+)"/', '$1', $ddl);

        // Unifica los tipos de datos para evitar diferencias de formato
        $ddl = preg_replace('/varchar\(\d+\)/i', 'varchar', $ddl);
        $ddl = preg_replace('/numeric\(\d+,\d+\)/i', 'numeric', $ddl);
        $ddl = preg_replace('/timestamp without time zone/i', 'timestamp', $ddl);

        // Convertir toda la cadena a minúsculas
        $ddl = strtolower($ddl);

        return $ddl;
    }



    public function migrateStructureToPostgres($tableName)
    {
        try {
            $mensaje = '';
            $tipo = 'Migracion Estructura';
            if ($this->tableExists($tableName)) {
                $tableName = $this->toLowerCase($tableName);
                $oracleDDL = $this->getTableDDL($tableName); // Obtén el DDL de Oracle
                $postgresDDL = $this->convertOracleToPostgres($oracleDDL); // Convierte a PostgreSQL
                $currentPostgresDDL = $this->getPostgresTableDDL($tableName);

                // Normalizamos los DDLs
                $normalizedPostgresDDL = $this->normalizeDDL($currentPostgresDDL);
                $normalizedOracleDDL = strtolower($this->normalizeDDL($postgresDDL));

                // Verificar si la tabla existe
                if ($normalizedPostgresDDL !== $normalizedOracleDDL) {
                    // Eliminar la tabla existente
                    DB::connection('pgsql')->statement("DROP TABLE IF EXISTS public.$tableName CASCADE");

                    // Crear la nueva tabla
                    DB::connection('pgsql')->statement($postgresDDL);

                    $mensaje = 'La tabla ' . $tableName . ' ha sido actualizada correctamente.';
                } else {
                    // Si los DDLs coinciden, no hacer nada
                    $mensaje = 'La tabla ' . $tableName . ' ya está actualizada, no se realizaron cambios.';
                }
            } else {
                // Convertir el nombre de la tabla a minúsculas
                $tableName = $this->toLowerCase($tableName);
                $oracleDDL = $this->getTableDDL($tableName); // Obtén el DDL de Oracle
                $postgresDDL = $this->convertOracleToPostgres($oracleDDL); // Convierte a PostgreSQL

                // Ejecuta el DDL convertido en PostgreSQL
                DB::connection('pgsql')->statement($postgresDDL);
                $mensaje = 'Migración completada';
            }
            // GUARDA REGISTRO DE MIGRACION
            $this->registerMigration($this->toLowerCase($tableName), $tipo);

            return redirect()->route('migration.index')->with('success', $mensaje);
        } catch (\Exception $e) {
            return redirect()->route('migration.index')->with('error', 'Error migracion: ' . $e->getMessage());
        }
    }

    public function registerMigration($tableName, $tipo)
    {
        try {
            // Buscar si la tabla ya fue migrada antes
            $migration = HistoryMigration::where('migrated_table', $tableName)->where('tipo_migracion', $tipo)->first();

            if ($migration) {
                $migration->update([
                    'updated_at' => now(),
                    'cantidad_migracion' => $migration->cantidad_migracion + 1,
                ]);
            } else {
                HistoryMigration::create([
                    'migrated_table' => $tableName,
                    'fecha_migration' => now(),
                    'tipo_migracion' => $tipo,
                ]);
            }
        } catch (\Exception $e) {
            return redirect()->route('migration.index')->with('error', 'Error al insertar en histórico de migración: ' . $e->getMessage());
        }
    }
}
