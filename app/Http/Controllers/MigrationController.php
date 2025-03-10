<?php

namespace App\Http\Controllers;

use App\Models\HistoryMigration;
use Doctrine\Inflector\InflectorFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $query->where(DB::raw('LOWER("OWNER")'), 'like', '%' . strtolower($search) . '%')
                    ->orWhere(DB::raw('LOWER("TABLE_NAME")'), 'like', '%' . strtolower($search) . '%');
            });
        }

        $oracleTables = $oracleTablesQuery->paginate(10);

        // Obtener las columnas de cada tabla de Oracle
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

        // Consultar la tabla `history_migration` para obtener las migraciones
        $migrations = DB::connection('pgsql')  // Asegúrate de usar la conexión correcta (pgsql)
        ->table('history_migrations')
        ->select('migrated_table_oracle', 'migrated_table_pgsql')
        ->whereRaw('created_at = (SELECT MAX(created_at) FROM history_migrations AS h2 WHERE h2.migrated_table_oracle = history_migrations.migrated_table_oracle)')
        ->pluck('migrated_table_pgsql', 'migrated_table_oracle')
        ->mapWithKeys(fn($pgsqlTable, $oracleTable) => [strtolower($oracleTable) => $pgsqlTable]);
    
    
        // Obtener los nombres de las tablas en PostgreSQL
        $pgsqlTables = [];
        $pgsqlColumns = [];

        foreach ($oracleTables as $table) {
            $tableNameLower = strtolower($table->table_name);

            if (isset($migrations[$tableNameLower])) {
                $pgsqlTableName = $migrations[$tableNameLower];
                $pgsqlTables[$tableNameLower] = $pgsqlTableName;

                // Consultar las columnas de la tabla migrada en PostgreSQL
                $pgsqlColumns[$tableNameLower] = DB::connection('pgsql')->select("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = :table_name
                ORDER BY ordinal_position
            ", ['table_name' => $pgsqlTableName]);
            }
        }



        return view('migration', compact('oracleTables', 'columns', 'pgsqlColumns', 'pgsqlTables'));
    }


    public function migrateData($table)
    {
        try {
            // Convertir el nombre de la tabla de Oracle a minúsculas
            $tableOracle = $this->toLowerCase($table);

            // Verificar si la tabla de Oracle ya está en plural
            if (substr($tableOracle, -1) !== 's') {
                $tablePgsql = $tableOracle . 's';  // Si no está en plural, le agregamos 's'
            } else {
                $tablePgsql = $tableOracle;  // Si ya está en plural, dejamos el nombre tal cual
            }

            // Obtener los datos de Oracle
            $oracleData = DB::connection('oracle')->select("SELECT * FROM " . $this->toUpperCase($tableOracle));

            // Verificar si se han encontrado datos
            if (empty($oracleData)) {
                throw new \Exception("No se encontraron datos en la tabla Oracle {$tableOracle}");
            }

            // Obtener las columnas de la tabla PostgreSQL
            $columnsQuery = DB::connection('pgsql')->select("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_name = :table
                ", ['table' => $tablePgsql]);

            // Verificar si se encontraron columnas en PostgreSQL
            if (empty($columnsQuery)) {
                throw new \Exception("No se encontraron columnas en la tabla PostgreSQL {$tablePgsql}");
            }

            // Obtener solo los nombres de las columnas en PostgreSQL
            $pgsqlColumns = array_map(fn($col) => $col->column_name, $columnsQuery);

            // Insertar los datos de Oracle en PostgreSQL
            foreach ($oracleData as $row) {
                $rowArray = (array) $row;
                $rowArray = array_change_key_case($rowArray, CASE_LOWER); // Convertir las claves a minúsculas

                // Filtrar los valores de Oracle para que coincidan con el número de columnas en PostgreSQL
                $columns = array_slice($rowArray, 0, count($pgsqlColumns));
                $values = array_values($columns);

                // Construcción dinámica de la sentencia INSERT
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $columnNames = implode(', ', $pgsqlColumns);

                // Mostrar los datos para depuración
                // dd($tablePgsql, $columnNames, $placeholders, $pgsqlColumns, $values, $row);

                $sql = "INSERT INTO {$tablePgsql} ({$columnNames}) VALUES ({$placeholders})";

                // Ejecutar la consulta con los valores
                DB::connection('pgsql')->statement($sql, $values);
            }

            return back()->with('success', "Datos de la tabla {$tablePgsql} migrados correctamente.");
        } catch (\Exception $e) {
            return back()->with('error', "Error al migrar datos de la tabla {$table}: " . $e->getMessage());
        }
    }

    public function migrateDataExist($tableOracle, $tablePgsql)
    {
        try {
            // Obtener los datos de Oracle
            $oracleData = DB::connection('oracle')->select("SELECT * FROM " . $this->toUpperCase($tableOracle));

            // Verificar si hay datos en Oracle
            if (empty($oracleData)) {
                throw new \Exception("No se encontraron datos en la tabla Oracle {$tableOracle}");
            }

            // Obtener las columnas de la tabla PostgreSQL
            $columnsQuery = DB::connection('pgsql')->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = :table ORDER BY ordinal_position", ['table' => $tablePgsql]);

            // Verificar si hay columnas en PostgreSQL
            if (empty($columnsQuery)) {
                throw new \Exception("No se encontraron columnas en la tabla PostgreSQL {$tablePgsql}");
            }

            // Obtener solo los nombres de las columnas en PostgreSQL
            $pgsqlColumns = array_map(fn($col) => $col->column_name, $columnsQuery);
            $pgsqlColumnTypes = array_map(fn($col) => $col->data_type, $columnsQuery);
            $columnCount = count($pgsqlColumns); // Número total de columnas en PostgreSQL

            // Insertar o actualizar los datos de Oracle en PostgreSQL
            foreach ($oracleData as $row) {
                $rowArray = array_values((array) $row); // Extraer solo los valores (ignorar nombres)

                // Llenar las columnas en PostgreSQL que no están en Oracle con NULL
                $values = array_pad($rowArray, $columnCount, null);

                // Si la tabla tiene un campo 'created_at' o 'updated_at', asignar la fecha actual
                if (in_array('created_at', $pgsqlColumns)) {
                    $createdAtIndex = array_search('created_at', $pgsqlColumns);
                    $values[$createdAtIndex] = now(); // Asignar la fecha actual a 'created_at'
                }

                if (in_array('updated_at', $pgsqlColumns)) {
                    $updatedAtIndex = array_search('updated_at', $pgsqlColumns);
                    $values[$updatedAtIndex] = now(); // Asignar la fecha actual a 'updated_at'
                }

                // Asignar 'false' a los campos booleanos si no hay valor de Oracle
                foreach ($pgsqlColumnTypes as $index => $type) {
                    if ($type === 'boolean' && $values[$index] === null) {
                        $values[$index] = false; // Si el valor es nulo, asignamos 'false'
                    }
                }

                // Construcción dinámica de la sentencia INSERT/UPDATE
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $columnNames = implode(', ', $pgsqlColumns);


                // Verificar si la tabla tiene una clave primaria (suponiendo que la clave primaria es 'id')
                $primaryKey = 'id'; // Cambia esto por el nombre de tu clave primaria si es diferente
                $updateColumns = array_map(fn($col) => "{$col} = EXCLUDED.{$col}", $pgsqlColumns);
                $updateSet = implode(', ', $updateColumns);

                $sql = "INSERT INTO {$tablePgsql} ({$columnNames}) VALUES ({$placeholders}) 
                        ON CONFLICT ({$primaryKey}) 
                        DO UPDATE SET {$updateSet}";

                // Ejecutar la consulta con los valores
                DB::connection('pgsql')->statement($sql, $values);
            }

            return back()->with('success', "Datos de la tabla {$tablePgsql} migrados correctamente.");
        } catch (\Exception $e) {
            return back()->with('error', "Error al migrar datos de la tabla {$tablePgsql}: " . $e->getMessage());
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
        $tableName = $this->toLowerCase($tableName);

        if (substr($tableName, -1) !== 's') {
            $tablePgsql = $tableName . 's';  // Si no está en plural, le agregamos 's'
        } else {
            $tablePgsql = $tableName;  // Si ya está en plural, dejamos el nombre tal cual
        }
        // Consultar si la tabla existe en PostgreSQL en el esquema 'public'
        $result = DB::connection('pgsql')->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_name = :table_name AND table_schema = 'public'
        ", ['table_name' => strtolower($tablePgsql)]);

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
        $postgresDDL = preg_replace('/segment\s*creation\s*immediate/i', '', $postgresDDL);

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



    public function migrateStructureToPostgres($tableName, $migrationCustomize = false)
    {
        try {
            $mensaje = '';
            $tipo = 'Migracion Estructura';
            if ($this->tableExists($tableName)) {
                $tableName = $this->toLowerCase($tableName);

                if (substr($tableName, -1) !== 's') {
                    $tablePgsql = $tableName . 's';  // Si no está en plural, le agregamos 's'
                } else {
                    $tablePgsql = $tableName;  // Si ya está en plural, dejamos el nombre tal cual
                }
                $oracleDDL = $this->getTableDDL($tableName); // Obtén el DDL de Oracle
                $postgresDDL = $this->convertOracleToPostgres($oracleDDL); // Convierte a PostgreSQL

                $postgresDDL = $this->transformTableAndColumnNames($postgresDDL);

                if ($migrationCustomize == true) {
                    return $postgresDDL;
                } else {
                    $currentPostgresDDL = $this->getPostgresTableDDL($tablePgsql);

                    // Normalizamos los DDLs
                    $normalizedPostgresDDL = $this->normalizeDDL($currentPostgresDDL);
                    $normalizedOracleDDL = strtolower($this->normalizeDDL($postgresDDL));
                    // Verificar si la tabla existe
                    if ($normalizedPostgresDDL !== $normalizedOracleDDL) {
                        // Eliminar la tabla existente
                        DB::connection('pgsql')->statement("DROP TABLE IF EXISTS public.$tablePgsql CASCADE");

                        // Crear la nueva tabla
                        DB::connection('pgsql')->statement($postgresDDL);
                        $this->migrateData($tableName);

                        $mensaje = 'La tabla ' . $tablePgsql . ' ha sido actualizada correctamente.';
                    } else {
                        // Si los DDLs coinciden, no hacer nada
                        $mensaje = 'La tabla ' . $tablePgsql . ' ya está actualizada, no se realizaron cambios.';
                    }
                }
            } else {
                // Convertir el nombre de la tabla a minúsculas
                $tableName = $this->toLowerCase($tableName);
                $oracleDDL = $this->getTableDDL($tableName); // Obtén el DDL de Oracle
                $postgresDDL = $this->convertOracleToPostgres($oracleDDL); // Convierte a PostgreSQL
                $postgresDDL = $this->transformTableAndColumnNames($postgresDDL);

                // Ejecuta el DDL convertido en PostgreSQL
                if ($migrationCustomize == true) {
                    return $postgresDDL;
                } else {
                    DB::connection('pgsql')->statement($postgresDDL);
                    $mensaje = 'Migración completada';
                }
            }
            // GUARDA REGISTRO DE MIGRACION
            $this->registerMigration($this->toLowerCase($tableName), $tipo);

            return redirect()->route('migration.index')->with('success', $mensaje);
        } catch (\Exception $e) {
            return redirect()->route('migration.index')->with('error', 'Error migracion: ' . $e->getMessage());
        }
    }


    function transformTableAndColumnNames($postgresDDL)
    {

        // // Extraer el nombre de la tabla usando regex
        if (!preg_match('/create table\s+"[^"]+"\."([^"]+)"/i', $postgresDDL, $matches)) {
            return $postgresDDL; // Si no encuentra coincidencias, retorna el original
        }

        $tableName = $matches[1];

        // Usar Doctrine Inflector para pluralizar
        $inflector = InflectorFactory::create()->build();
        $pluralTableName = $inflector->pluralize($tableName);

        // Si el nombre cambió, reemplazar en el DDL
        if ($tableName !== $pluralTableName) {
            $postgresDDL = str_replace("\"$tableName\"", "\"$pluralTableName\"", $postgresDDL);
        }

        // Transformar claves primarias (PRIMARY KEY)
        preg_match_all('/CONSTRAINT\s+"?(\S+)"?\s+PRIMARY KEY\s*\(([^)]+)\)/i', $postgresDDL, $matches, PREG_SET_ORDER);

        if (!$matches) {
            return $postgresDDL; // No hay claves primarias, retornar el DDL original
        }

        $primaryKeys = [];
        foreach ($matches as $match) {
            // Extraer los nombres de las columnas PRIMARY KEY
            $keys = array_map('trim', explode(',', str_replace('"', '', $match[2])));
            $primaryKeys = array_merge($primaryKeys, $keys);
        }

        if (count($primaryKeys) > 0) {
            $firstPK = $primaryKeys[0]; // Primera clave primaria
            $postgresDDL = preg_replace('/\b' . preg_quote($firstPK, '/') . '\b/', 'id', $postgresDDL);
        }


        // Transformar claves foráneas (FOREIGN KEY)
        preg_match_all('/CONSTRAINT\s+"?(\S+)"?\s+FOREIGN KEY\s*\(([^)]+)\)\s+REFERENCES\s+"?([^"]+)"?\.?"?([^"]+)"/i', $postgresDDL, $foreignMatches, PREG_SET_ORDER);

        foreach ($foreignMatches as $foreignMatch) {
            $columnName = trim($foreignMatch[2], '"'); // Nombre original del campo FK
            $referencedSchema = trim($foreignMatch[3], '"'); // Esquema referenciado (si existe)
            $referencedTable = trim($foreignMatch[4], '"'); // Tabla referenciada

            // Singularizamos y convertimos a minúsculas
            $referencedTableSingular = strtolower(rtrim($referencedTable, 's')); // Singularizamos y minúsculas

            // Renombrar la columna foránea según la regla "nombreTabla_id"
            $newColumnName = $referencedTableSingular . "_id";

            // Reemplazar solo si el nombre de la clave foránea no sigue el formato correcto
            if ($columnName !== $newColumnName) {
                $postgresDDL = str_replace("\"$columnName\"", "\"$newColumnName\"", $postgresDDL);
            }
        }

        // Extraemos el nombre de la tabla para comprobar

        $tablePrefix = strtolower($tableName); // Prefijo de la tabla (por ejemplo, "adi")



        // Buscar nombres de columnas dentro del CREATE TABLE
        preg_match_all('/"([^"]+)"/i', $postgresDDL, $columnMatches);

        if (!isset($columnMatches[1])) {
            return $postgresDDL; // Si no hay columnas, devolvemos el original
        }

        // Recorrer las columnas y eliminar el prefijo si lo tienen
        foreach ($columnMatches[1] as $columnName) {
            // Comprobar si el nombre de la columna empieza con el prefijo de la tabla seguido de "_"
            if (strpos(strtolower($columnName), $tablePrefix . '_') === 0) {
                // Eliminar solo el prefijo y el "_"
                $newColumnName = substr($columnName, strlen($tablePrefix) + 1);

                // Asegurar que el nuevo nombre no quede vacío antes de reemplazar
                if (!empty($newColumnName)) {
                    $postgresDDL = str_replace("\"$columnName\"", "\"$newColumnName\"", $postgresDDL);
                }
            }
        }

        return $postgresDDL;
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


    // ---------------------------------------- HOMOLOGACION EN BASE A REGLAS DE LARAVEL ---------------------------------------- //

    function extractColumnsFromDDL($ddl)
    {
        preg_match_all('/"(.*?)"/', $ddl, $matches);
        return array_map('trim', $matches[1]); // Devuelve los nombres de las columnas
    }

    function extractConstraintsFromDDL($ddl)
    {
        $constraints = [];

        if (preg_match('/PRIMARY KEY \("(.*?)"\)/', $ddl, $pkMatch)) {
            $constraints[] = ['type' => 'primary', 'column' => $pkMatch[1]];
        }

        if (preg_match_all('/FOREIGN KEY \("(.*?)"\) REFERENCES "(.*?)"/', $ddl, $fkMatches, PREG_SET_ORDER)) {
            foreach ($fkMatches as $fkMatch) {
                $constraints[] = ['type' => 'foreign', 'column' => $fkMatch[1], 'references' => $fkMatch[2]];
            }
        }

        return $constraints;
    }

    function normalizeLaravelNaming($table, $columns, $constraints)
    {
        $normalizedTable = Str::snake(Str::singular($table)); // Laravel usa snake_case y nombres en singular
        $normalizedColumns = [];
        foreach ($columns as $column) {
            $newName = Str::snake($column);
            if (in_array($column, array_column($constraints, 'column'))) {
                // Si es clave primaria, cambiar a 'id'
                $newName = 'id';
            } elseif ($foreign = array_filter($constraints, fn($c) => $c['column'] === $column && $c['type'] === 'foreign')) {
                // Si es clave foránea, cambiar a 'tabla_referenciada_id'
                $newName = Str::snake($foreign[0]['references']) . '_id';
            }
            $normalizedColumns[$column] = $newName;
        }

        return ['table' => $normalizedTable, 'columns' => $normalizedColumns];
    }

    function applyNormalizationToDDL($ddl, $columns, $table)
    {
        foreach ($columns as $original => $normalized) {
            $ddl = str_replace("\"$original\"", "\"$normalized\"", $ddl);
        }
        return str_replace("TABLE \"$table\"", "TABLE \"$table\"", $ddl);
    }
}
