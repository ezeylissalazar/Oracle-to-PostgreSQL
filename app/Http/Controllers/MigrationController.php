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
            }
            return back()->with('success', "Datos de la tabla {$table} migrados correctamente.");
        } catch (\Exception $e) {
            return back()->with('error', "Error al migrar datos de la tabla {$table}: " . $e->getMessage());
        }
    }

    // public function migrateData($table)
    // {
    //     try {
    //         $table = $this->toLowerCase($table); // Asegurar que el nombre de la tabla esté en minúsculas

    //         // Verificar si la tabla existe en PostgreSQL
    //         $tableExists = DB::connection('pgsql')->select("SELECT to_regclass('public.{$table}') as exists");
    //         if (empty($tableExists) || is_null($tableExists[0]->exists)) {
    //             return back()->with('error', "La tabla {$table} no existe en PostgreSQL.");
    //         }

    //         // Obtener los datos desde Oracle
    //         $oracleData = DB::connection('oracle')->select("SELECT * FROM " . $this->toUpperCase($table));

    //         if (empty($oracleData)) {
    //             return back()->with('error', "No hay datos en la tabla {$table} para migrar.");
    //         }

    //         foreach ($oracleData as $row) {
    //             $rowArray = (array) $row;
    //             $rowArray = array_change_key_case($rowArray, CASE_LOWER); // Convertir claves a minúsculas

    //             // Obtener claves y valores
    //             $columns = array_keys($rowArray);
    //             $values = array_values($rowArray);

    //             // Construcción dinámica de INSERT ON CONFLICT
    //             $updateSet = implode(', ', array_map(fn($col) => "$col = excluded.$col", $columns));

    //             $placeholders = implode(', ', array_fill(0, count($values), '?'));
    //             $columnNames = implode(', ', $columns);

    //             $sql = "INSERT INTO {$table} ({$columnNames}) VALUES ({$placeholders}) 
    //                     ON CONFLICT (id_empleado) DO UPDATE SET {$updateSet};";

    //             DB::connection('pgsql')->statement($sql, $values);
    //         }

    //         return back()->with('success', "Datos de la tabla {$table} migrados correctamente.");
    //     } catch (\Exception $e) {
    //         return back()->with('error', "Error al migrar datos de la tabla {$table}: " . $e->getMessage());
    //     }
    // }




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



    // Obtener el DDL de la tabla en Oracle
    public function getTableDDL($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        // Convertir el nombre de la tabla a minúsculas
        $tableName = $this->toLowerCase($tableName);

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

        // Reemplazar el esquema "SYSTEM" por "public" en PostgreSQL
        $postgresDDL = str_ireplace('"SYSTEM".', '"public".', $postgresDDL);

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

        return $postgresDDL;
    }


    public function migrateStructureToPostgres($tableName)
    {
        try {
            DB::beginTransaction();
            if ($this->tableExists($tableName)) {
                // Obtener las estructuras de las tablas en Oracle y PostgreSQL
                $oracleStructure = $this->getOracleTableStructure($tableName);
                $postgresStructure = $this->getPostgresTableStructure($tableName);
                // Comparar y sincronizar las estructuras de las tablas

                $this->compareTableStructures($oracleStructure, $postgresStructure, $tableName);
            } else {
                // Convertir el nombre de la tabla a minúsculas
                $tableName = $this->toLowerCase($tableName);
                $oracleDDL = $this->getTableDDL($tableName); // Obtén el DDL de Oracle

                $postgresDDL = $this->convertOracleToPostgres($oracleDDL); // Convierte a PostgreSQL

                // Ejecuta el DDL convertido en PostgreSQL
                DB::connection('pgsql')->statement($postgresDDL);
            }
            // GUARDA REGISTRO DE MIGRACION
            $this->registerMigration($this->toLowerCase($tableName));
            DB::commit();
            return redirect()->route('migration.index')->with('success', 'Migración completada');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('migration.index')->with('error', 'Error migracion: ' . $e->getMessage());
        }
    }

    private function getOracleTableStructure($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        return DB::connection('oracle')->select("
                SELECT column_name, data_type
                FROM all_tab_columns 
                WHERE table_name = :table_name 
                AND owner = :schema
                ORDER BY column_id
            ", ['table_name' => $tableName, 'schema' => $schema]);
    }

    // Obtener la estructura de la tabla en PostgreSQL
    private function getPostgresTableStructure($tableName)
    {
        return DB::connection('pgsql')->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ?", [$this->toLowerCase($tableName)]);
    }



    // Comparar y sincronizar las estructuras de las tablas
    private function compareTableStructures($oracleStructure, $postgresStructure, $tableName)
    {
        $oracleColumns = collect($oracleStructure);
        $postgresColumns = collect($postgresStructure);

        $oraclePrimaryKey = $this->getOraclePrimaryKey($tableName);
        $oraclePrimaryKey = $this->toLowerCase($oraclePrimaryKey->column_name);
        $postgresPrimaryKey = $this->getPostgresPrimaryKey($tableName);
        $postgresPrimaryKey = $postgresPrimaryKey->column_name;

        $oracleForeignKeys = collect($this->getOracleForeignKeys($tableName));
        $postgresForeignKeys = collect($this->getPostgresForeignKeys($tableName));

        $oracleIndexes = collect($this->getOracleIndexes($tableName));
        $postgresIndexes = collect($this->getPostgresIndexes($tableName));

        $oracleConstraints = collect($this->getOracleConstraints($tableName));
        $postgresConstraints = collect($this->getPostgresConstraints($tableName));


        // === 1. Validar columnas y tipos de datos ===
        foreach ($oracleColumns as $oracleColumn) {
            $postgresColumn = $postgresColumns->firstWhere('column_name', $this->toLowerCase($oracleColumn->column_name));

            if (!$postgresColumn) {
                $this->addColumnInPostgres($oracleColumn, $tableName);
            } else {
                $dataTypeOracle = $this->convertOracleToPostgres($oracleColumn->data_type);
                $dataTypeOracle = $this->toLowerCase($dataTypeOracle);

                if ($dataTypeOracle !== $postgresColumn->data_type) {
                    $this->alterColumnTypeInPostgres($oracleColumn, $postgresColumn, $tableName);
                }
            }
        }

        foreach ($postgresColumns as $postgresColumn) {
            $oracleColumn = $oracleColumns->firstWhere('column_name', $this->toUpperCase($postgresColumn->column_name));

            if (!$oracleColumn) {
                $this->dropColumnInPostgres($postgresColumn, $tableName);
            }
        }

        // === 2. Validar Clave Primaria ===
        if ($oraclePrimaryKey !== $postgresPrimaryKey) {
            $this->syncPrimaryKey($oraclePrimaryKey, $postgresPrimaryKey, $tableName);
        }

        // === 3. Validar Claves Foráneas ===
        foreach ($oracleForeignKeys as $oracleFK) {

            $postgresFK = $postgresForeignKeys->firstWhere('column_name', $this->toLowerCase($oracleFK->column_name));

            if (!$postgresFK) {
                $this->addForeignKeyInPostgres($oracleFK, $tableName);
            } elseif (
                $oracleFK->referenced_table !== $postgresFK->referenced_table ||
                $oracleFK->referenced_column !== $postgresFK->referenced_column
            ) {
                $this->updateForeignKeyInPostgres($oracleFK, $postgresFK, $tableName);
            }
        }



        foreach ($postgresForeignKeys as $postgresFK) {

            if (!$oracleForeignKeys->firstWhere('column_name', $postgresFK->column_name)) {
                $this->dropForeignKeyInPostgres($postgresFK, $tableName);
            }
        }

        // === 4. Validar Índices ===
        foreach ($oracleIndexes as $oracleIndex) {
            if (!$postgresIndexes->contains('index_name', $this->toLowerCase($oracleIndex->index_name))) {
                $this->addIndexInPostgres($oracleIndex, $tableName);
            }
        }

        foreach ($postgresIndexes as $postgresIndex) {
            if (!$oracleIndexes->contains('index_name', $this->toUpperCase($postgresIndex->index_name))) {
                $this->dropIndexInPostgres($postgresIndex, $tableName);
            }
        }

        // === 5. Validar Restricciones ===
        foreach ($oracleConstraints as $oracleConstraint) {
            $restriction = $this->toLowerCase($oracleConstraint->constraint_name);
            if (!$postgresConstraints->contains('constraint_name', $restriction)) {
                $this->addConstraintInPostgres($oracleConstraint, $tableName);
            }
        }
        foreach ($postgresConstraints as $postgresConstraint) {
            $restriction = $this->toUpperCase($oracleConstraint->constraint_name);
            if (!$oracleConstraints->contains('constraint_name', $restriction)) {
                $this->dropConstraintInPostgres($postgresConstraint, $tableName);
            }
        }
    }

    private function syncPrimaryKey($oraclePK, $postgresPK, $tableName)
    {
        if ($postgresPK) {
            DB::connection('pgsql')->statement("ALTER TABLE $tableName DROP CONSTRAINT $postgresPK;");
        }
        DB::connection('pgsql')->statement("ALTER TABLE $tableName ADD PRIMARY KEY ($oraclePK->column_name);");
    }

    private function addForeignKeyInPostgres($foreignKey, $tableName)
    {
        DB::connection('pgsql')->statement("ALTER TABLE $tableName ADD CONSTRAINT {$this->toLowerCase($foreignKey->constraint_name)}
                       FOREIGN KEY ({$this->toLowerCase($foreignKey->column_name)})
                       REFERENCES {$this->toLowerCase($foreignKey->referenced_table)}({$this->toLowerCase($foreignKey->referenced_column)});");
    }

    private function updateForeignKeyInPostgres($oracleFK, $postgresFK, $tableName)
    {
        $this->dropForeignKeyInPostgres($postgresFK, $tableName);
        $this->addForeignKeyInPostgres($oracleFK, $tableName);
    }

    private function dropForeignKeyInPostgres($foreignKey, $tableName)
    {
        $tableName = $this->toLowerCase($tableName);
        DB::connection('pgsql')->statement("ALTER TABLE $tableName DROP CONSTRAINT {$foreignKey->constraint_name};");
    }

    private function addIndexInPostgres($index, $tableName)
    {
        $tableName = $this->toLowerCase($tableName);
        DB::connection('pgsql')->statement("CREATE INDEX {$index->index_name} ON $tableName ({$index->column_name});");
    }

    private function dropIndexInPostgres($index, $tableName)
    {
        DB::connection('pgsql')->statement("DROP INDEX {$index->index_name};");
    }

    private function transformOracleConstraintToPostgres($constraint)
    {
        switch ($constraint->constraint_type) {
            case 'C': // Check
                return "CHECK ({$this->getCheckCondition($constraint)})";

            case 'P': // Primary Key
                return "PRIMARY KEY ({$this->toLowerCase($constraint->constraint_name)})";

            case 'R': // Foreign Key
                return "FOREIGN KEY ({$this->toLowerCase($constraint->constraint_name)}) 
                REFERENCES {$this->toLowerCase($constraint->referenced_table)}({$this->toLowerCase($constraint->referenced_column)})";

            case 'U': // Unique
                return "UNIQUE ({$this->toLowerCase($constraint->constraint_name)})";

            default:
                throw new \Exception("Tipo de restricción desconocido: {$constraint->constraint_type}");
        }
    }

    private function getCheckCondition($constraint)
    {
        if (!empty($constraint->search_condition)) {
            return $this->toLowerCase($constraint->search_condition);
        }
    }


    private function addConstraintInPostgres($constraint, $tableName)
    {
        $tableName = $this->toLowerCase($tableName);
        $restriction_name = $this->convertOracleToPostgres($constraint->constraint_name);
        $restriction_definition = $this->transformOracleConstraintToPostgres($constraint);
        DB::connection('pgsql')->statement("ALTER TABLE $tableName ADD CONSTRAINT {$restriction_name} {$restriction_definition}");
    }

    private function dropConstraintInPostgres($constraint, $tableName)
    {
        $tableName = $this->toLowerCase($tableName);
        DB::connection('pgsql')->statement("ALTER TABLE $tableName DROP CONSTRAINT {$constraint->constraint_name};");
    }

    // Agregar un campo a PostgreSQL
    private function addColumnInPostgres($oracleColumn, $tableName)
    {

        $columnName = $this->toLowerCase($oracleColumn->column_name);
        $dataType = $this->convertOracleToPostgres($oracleColumn->data_type);

        // Ejecutar ALTER TABLE para agregar el campo
        DB::connection('pgsql')->statement("ALTER TABLE {$tableName} ADD {$columnName} {$dataType}");
    }

    // Eliminar un campo de PostgreSQL
    private function dropColumnInPostgres($postgresColumn, $tableName)
    {
        $columnName = $postgresColumn->column_name;

        // Ejecutar ALTER TABLE para eliminar el campo
        DB::connection('pgsql')->statement("ALTER TABLE {$tableName} DROP COLUMN {$columnName}");
    }

    // Modificar el tipo de dato de un campo en PostgreSQL
    private function alterColumnTypeInPostgres($oracleColumn, $postgresColumn, $tableName)
    {
        $columnName = $this->toLowerCase($oracleColumn->column_name);
        $dataType = $this->convertOracleToPostgres($oracleColumn->data_type);

        // Ejecutar ALTER COLUMN para cambiar el tipo de dato
        DB::connection('pgsql')->statement("ALTER TABLE {$tableName} ALTER COLUMN {$columnName} TYPE {$dataType}");
    }

    private function getOraclePrimaryKey($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        return DB::connection('oracle')->selectOne("
        SELECT acc.column_name
        FROM all_cons_columns acc
        JOIN all_constraints ac ON acc.constraint_name = ac.constraint_name
        WHERE ac.table_name = :table_name
        AND ac.owner = :schema
        AND ac.constraint_type = 'P'
    ", ['table_name' => strtoupper($tableName), 'schema' => strtoupper($schema)]);
    }

    private function getPostgresPrimaryKey($tableName)
    {
        return DB::connection('pgsql')->selectOne("
        SELECT kcu.column_name
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu 
        ON tc.constraint_name = kcu.constraint_name
        WHERE tc.table_name = ?
        AND tc.constraint_type = 'PRIMARY KEY'
    ", [$this->toLowerCase($tableName)]);
    }

    private function getOracleForeignKeys($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        return DB::connection('oracle')->select("
        SELECT acc.column_name, ac_r.table_name AS referenced_table, acc_r.column_name AS referenced_column, ac.constraint_name
        FROM all_cons_columns acc
        JOIN all_constraints ac ON acc.constraint_name = ac.constraint_name
        JOIN all_constraints ac_r ON ac.r_constraint_name = ac_r.constraint_name
        JOIN all_cons_columns acc_r ON ac_r.constraint_name = acc_r.constraint_name AND acc_r.position = acc.position
        WHERE ac.table_name = :table_name
        AND ac.owner = :schema
        AND ac.constraint_type = 'R'
    ", ['table_name' => $tableName, 'schema' => $schema]);
    }


    private function getPostgresForeignKeys($tableName)
    {
        return DB::connection('pgsql')->select("
            SELECT
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS referenced_table,
                ccu.column_name AS referenced_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu 
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_name = kcu.table_name  -- Asegurar que coinciden con la tabla
            JOIN information_schema.constraint_column_usage ccu 
                ON tc.constraint_name = ccu.constraint_name
            WHERE tc.table_name = ?
            AND tc.constraint_type = 'FOREIGN KEY'
        ", [$this->toLowerCase($tableName)]);
    }


    private function getOracleIndexes($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        return DB::connection('oracle')->select("
        SELECT ind.index_name, col.column_name
        FROM all_indexes ind
        JOIN all_ind_columns col ON ind.index_name = col.index_name
        WHERE ind.table_name = :table_name
        AND ind.owner = :schema
    ", ['table_name' => strtoupper($tableName), 'schema' => strtoupper($schema)]);
    }

    private function getPostgresIndexes($tableName)
    {
        return DB::connection('pgsql')->select("
        SELECT indexname AS index_name, indexdef
        FROM pg_indexes
        WHERE tablename = ?
    ", [$this->toLowerCase($tableName)]);
    }

    private function getOracleConstraints($tableName)
    {
        $schema = env('DB_SCHEMA_PREFIX', 'BIPWORK2');

        return DB::connection('oracle')->select("
            SELECT constraint_name, search_condition, constraint_type
            FROM all_constraints
            WHERE table_name = :table_name
            AND owner = :schema
            AND constraint_type = 'C' -- Solo restricciones CHECK
        ", ['table_name' => strtoupper($tableName), 'schema' => strtoupper($schema)]);
    }



    private function getPostgresConstraints($tableName)
    {
        return DB::connection('pgsql')->select("
        SELECT constraint_name, constraint_type
        FROM information_schema.table_constraints
        WHERE table_name = ?
    ", [$this->toLowerCase($tableName)]);
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
            return redirect()->route('migration.index')->with('error', 'Error al insertar en histórico de migración: ' . $e->getMessage());
        }
    }
}
