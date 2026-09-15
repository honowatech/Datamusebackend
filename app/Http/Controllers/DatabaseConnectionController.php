<?php

namespace App\Http\Controllers;

use App\Models\TargetDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Exception;

class DatabaseConnectionController extends Controller
{
    /**
     * List all saved databases for the authenticated user
     */
    public function index(Request $request)
    {
        $databases = $request->user()->targetDatabases()
            ->select('id', 'name', 'driver', 'host', 'port', 'database', 'username', 'created_at')
            ->get();

        return response()->json([
            'success' => true,
            'databases' => $databases
        ]);
    }

    /**
     * Connect to a target database, scan its schema, and save/update the credentials
     */
    public function connect(Request $request)
    {
        $request->validate([
            'database_id' => 'nullable|numeric',
            'name' => 'nullable|string|max:255',
            'driver' => 'required_without:database_id|string|in:mysql,pgsql,sqlite,sqlsrv',
            'host' => 'nullable|string',
            'port' => 'nullable|numeric',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'database' => 'required_without:database_id|string'
        ]);

        try {
            $dbCreds = null;
            if ($request->database_id) {
                // Connect using an existing database configuration
                $dbConfig = $request->user()->targetDatabases()->findOrFail($request->database_id);
                $dbCreds = [
                    'driver' => $dbConfig->driver ?? 'mysql',
                    'host' => $dbConfig->host,
                    'port' => $dbConfig->port,
                    'database' => $dbConfig->database,
                    'username' => $dbConfig->username,
                    'password' => $dbConfig->password,
                    'name' => $dbConfig->name,
                    'id' => $dbConfig->id
                ];
            } else {
                // Connect using new raw credentials
                $dbCreds = [
                    'driver' => $request->driver,
                    'host' => $request->host,
                    'port' => $request->port,
                    'database' => $request->database,
                    'username' => $request->username,
                    'password' => $request->password ?? '',
                    'name' => $request->name ?: $request->database,
                    'id' => null
                ];
            }

            // Set dynamic configuration for target DB with connection timeout
            if ($dbCreds['driver'] === 'sqlite') {
                Config::set('database.connections.target_db', [
                    'driver' => 'sqlite',
                    'database' => $dbCreds['database'],
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ]);
            } else {
                Config::set('database.connections.target_db', [
                    'driver' => $dbCreds['driver'],
                    'host' => $dbCreds['host'],
                    'port' => $dbCreds['port'],
                    'database' => $dbCreds['database'],
                    'username' => $dbCreds['username'],
                    'password' => $dbCreds['password'],
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                    'options' => [
                        \PDO::ATTR_TIMEOUT => 3, // 3 seconds timeout to prevent DoS
                    ]
                ]);
            }

            // Try to connect and get tables
            $tables = array_column(\Illuminate\Support\Facades\Schema::connection('target_db')->getTables(), 'name');
            
            // Exclude internal tables (migrations, Sanctum tokens, etc.)
            $filteredTables = array_values(array_filter($tables, function($table) {
                return !in_array($table, ['migrations', 'personal_access_tokens', 'password_reset_tokens', 'failed_jobs', 'users', 'cache', 'cache_locks', 'jobs', 'job_batches']);
            }));

            // Fetch columns for each table to build a detailed schema
            $detailedSchema = [];
            foreach ($filteredTables as $tableName) {
                $columns = \Illuminate\Support\Facades\Schema::connection('target_db')->getColumns($tableName);
                $tableSchema = [];
                foreach ($columns as $col) {
                    $colInfo = [
                        'name' => $col['name'],
                        'type' => $col['type_name'],
                        'nullable' => $col['nullable'],
                    ];

                    // Fetch sample values for text columns
                    if (in_array($col['type_name'], ['varchar', 'char', 'enum', 'text']) 
                        && !in_array($col['name'], ['email', 'phone', 'address', 'password', 'remember_token', 'account_number', 'transaction_number', 'loan_number', 'first_name', 'last_name', 'description', 'reference_account', 'postal_code'])) {
                        try {
                            $distinctValues = DB::connection('target_db')
                                ->table($tableName)
                                ->select($col['name'])
                                ->distinct()
                                ->limit(15)
                                ->pluck($col['name'])
                                ->filter()
                                ->values()
                                ->toArray();
                            if (!empty($distinctValues)) {
                                $colInfo['sample_values'] = $distinctValues;
                            }
                        } catch (\Exception $e) {
                            // Skip if we can't read
                        }
                    }

                    $tableSchema[] = $colInfo;
                }
                $detailedSchema[$tableName] = $tableSchema;
            }

            // Count rows per table
            $tableCounts = [];
            foreach ($filteredTables as $tableName) {
                try {
                    $tableCounts[$tableName] = DB::connection('target_db')->table($tableName)->count();
                } catch (\Exception $e) {
                    $tableCounts[$tableName] = '?';
                }
            }

            // Save or update connection credentials in DB if it was raw input
            $targetDbId = $dbCreds['id'];
            $targetDbName = $dbCreds['name'];
            if (!$targetDbId) {
                $targetDb = $request->user()->targetDatabases()->updateOrCreate(
                    [
                        'host' => $dbCreds['host'] ?? '',
                        'database' => $dbCreds['database'],
                        'username' => $dbCreds['username'] ?? '',
                    ],
                    [
                        'name' => $dbCreds['name'],
                        'driver' => $dbCreds['driver'],
                        'port' => $dbCreds['port'] ?? '',
                        'password' => $dbCreds['password'],
                    ]
                );
                $targetDbId = $targetDb->id;
                $targetDbName = $targetDb->name;
            }

            // Purge the connection configuration cache
            DB::purge('target_db');

            return response()->json([
                'success' => true,
                'database_id' => $targetDbId,
                'database_name' => $targetDbName,
                'tables' => $filteredTables,
                'schema_details' => $detailedSchema,
                'table_counts' => $tableCounts,
                'message' => 'Connected and saved successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Import a CSV or Excel file and transform it into an interactive SQLite database
     */
    public function importFile(Request $request, \App\Services\FileIngestionService $ingestionService)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50MB max
            'name' => 'nullable|string|max:255'
        ]);

        try {
            $result = $ingestionService->ingestFile(
                $request->file('file'),
                $request->user(),
                $request->input('name')
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de l'importation du fichier : " . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete a saved database configuration
     */
    public function destroy($id, Request $request)
    {
        $targetDb = $request->user()->targetDatabases()->where('id', $id)->first();

        if ($targetDb) {
            // If it is an imported SQLite database, delete the file
            if ($targetDb->driver === 'sqlite' && file_exists($targetDb->database)) {
                @unlink($targetDb->database);
            }

            $targetDb->delete();

            return response()->json([
                'success' => true,
                'message' => 'Base de données supprimée avec succès.'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Base de données introuvable ou non autorisée.'
        ], 404);
    }
}
