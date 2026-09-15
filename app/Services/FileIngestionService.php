<?php

namespace App\Services;

use App\Models\TargetDatabase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;
use Exception;
use PDO;

class FileIngestionService
{
    /**
     * Ingest a CSV or Excel file into an SQLite database for the user
     */
    public function ingestFile(UploadedFile $file, User $user, ?string $customName = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'xlsx', 'xls', 'tsv', 'txt'])) {
            throw new Exception("Format de fichier non supporté. Veuillez importer un fichier CSV ou Excel (.xlsx, .xls, .tsv).");
        }

        // 1. Prepare storage directory for user databases
        $storageDir = storage_path('app/imported_databases/user_' . $user->id);
        File::ensureDirectoryExists($storageDir);

        $safeFileBase = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $dbFileName = 'db_' . time() . '_' . Str::random(6) . '.sqlite';
        $sqlitePath = $storageDir . DIRECTORY_SEPARATOR . $dbFileName;

        // Create empty SQLite file
        touch($sqlitePath);

        // 2. Determine table name
        $tableName = !empty($safeFileBase) ? substr($safeFileBase, 0, 50) : 'data_table';
        if (is_numeric($tableName[0])) {
            $tableName = 't_' . $tableName;
        }

        // 3. Initialize reader with auto-delimiter detection for CSV
        $tempPath = $file->getRealPath();
        $reader = SimpleExcelReader::create($tempPath, $extension);

        if (in_array($extension, ['csv', 'tsv', 'txt'])) {
            $firstLine = fgets(fopen($tempPath, 'r'));
            $semicolons = substr_count($firstLine, ';');
            $commas = substr_count($firstLine, ',');
            $tabs = substr_count($firstLine, "\t");
            $pipes = substr_count($firstLine, '|');

            $delim = ',';
            if ($semicolons > $commas && $semicolons > $tabs && $semicolons > $pipes) {
                $delim = ';';
            } elseif ($tabs > $commas && $tabs > $semicolons) {
                $delim = "\t";
            } elseif ($pipes > $commas && $pipes > $semicolons) {
                $delim = '|';
            }
            $reader->useDelimiter($delim);
        }

        // 4. Extract and clean headers
        $rows = $reader->getRows();
        $firstRow = $rows->first();

        if (empty($firstRow)) {
            unlink($sqlitePath);
            throw new Exception("Le fichier semble vide ou ne contient aucune ligne de données.");
        }

        $rawHeaders = array_keys($firstRow);
        $cleanHeaders = [];
        $headerMap = []; // rawHeader => cleanHeader
        $headerCounts = [];

        foreach ($rawHeaders as $idx => $raw) {
            $rawClean = trim((string)$raw);
            $clean = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rawClean);
            $clean = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $clean));
            $clean = trim($clean, '_');

            if (empty($clean) || is_numeric($clean[0])) {
                $clean = 'col_' . ($idx + 1) . ($clean ? '_' . $clean : '');
            }

            if (isset($headerCounts[$clean])) {
                $headerCounts[$clean]++;
                $clean = $clean . '_' . $headerCounts[$clean];
            } else {
                $headerCounts[$clean] = 1;
            }

            $cleanHeaders[] = $clean;
            $headerMap[$raw] = $clean;
        }

        // 5. Sample first 150 rows to infer column data types
        $sampleRows = $rows->take(150);
        $columnTypes = [];

        foreach ($cleanHeaders as $cHeader) {
            $columnTypes[$cHeader] = 'INTEGER'; // Start optimistic
        }

        foreach ($sampleRows as $row) {
            foreach ($rawHeaders as $raw) {
                $cHeader = $headerMap[$raw];
                $val = $row[$raw] ?? null;

                if ($val === null || $val === '') {
                    continue;
                }

                $valStr = trim((string)$val);
                $currentType = $columnTypes[$cHeader];

                if ($currentType === 'TEXT') {
                    continue; // Already fallback to text
                }

                if ($currentType === 'INTEGER') {
                    if (preg_match('/^-?\d+$/', $valStr)) {
                        continue;
                    } elseif (is_numeric(str_replace(',', '.', $valStr))) {
                        $columnTypes[$cHeader] = 'REAL';
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $valStr) || preg_match('/^\d{2}\/\d{2}\/\d{4}/', $valStr)) {
                        $columnTypes[$cHeader] = 'DATE';
                    } else {
                        $columnTypes[$cHeader] = 'TEXT';
                    }
                } elseif ($currentType === 'REAL') {
                    if (is_numeric(str_replace(',', '.', $valStr))) {
                        continue;
                    } else {
                        $columnTypes[$cHeader] = 'TEXT';
                    }
                } elseif ($currentType === 'DATE') {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $valStr) || preg_match('/^\d{2}\/\d{2}\/\d{4}/', $valStr)) {
                        continue;
                    } else {
                        $columnTypes[$cHeader] = 'TEXT';
                    }
                }
            }
        }

        // 6. Connect to SQLite and create schema
        $pdo = new PDO("sqlite:" . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA synchronous = OFF; PRAGMA journal_mode = MEMORY;");

        $colsDef = [];
        $colsDef[] = '"id" INTEGER PRIMARY KEY AUTOINCREMENT';
        foreach ($cleanHeaders as $cHeader) {
            $type = $columnTypes[$cHeader] ?? 'TEXT';
            $colsDef[] = "\"{$cHeader}\" {$type}";
        }

        $createSql = "CREATE TABLE IF NOT EXISTS \"{$tableName}\" (\n  " . implode(",\n  ", $colsDef) . "\n);";
        $pdo->exec($createSql);

        // 7. Bulk insert data in batches
        $placeholders = implode(', ', array_fill(0, count($cleanHeaders), '?'));
        $columnsList = implode(', ', array_map(fn($c) => "\"$c\"", $cleanHeaders));
        $insertSql = "INSERT INTO \"{$tableName}\" ({$columnsList}) VALUES ({$placeholders})";
        $stmt = $pdo->prepare($insertSql);

        $pdo->beginTransaction();
        $totalRows = 0;

        foreach ($reader->getRows() as $row) {
            $values = [];
            foreach ($rawHeaders as $raw) {
                $val = $row[$raw] ?? null;
                if ($val === '' || $val === null) {
                    $values[] = null;
                } else {
                    $valStr = is_string($val) ? trim($val) : $val;
                    $cHeader = $headerMap[$raw];
                    $t = $columnTypes[$cHeader];

                    if ($t === 'REAL' && is_string($valStr)) {
                        $valStr = (float)str_replace(',', '.', $valStr);
                    } elseif ($t === 'INTEGER' && is_string($valStr)) {
                        $valStr = (int)$valStr;
                    }
                    $values[] = $valStr;
                }
            }

            $stmt->execute($values);
            $totalRows++;

            if ($totalRows % 1000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        $pdo->commit();
        $pdo->exec("PRAGMA synchronous = NORMAL; PRAGMA journal_mode = WAL;");

        // 8. Register in TargetDatabase
        $displayName = $customName ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $targetDb = TargetDatabase::create([
            'user_id' => $user->id,
            'name' => $displayName . " (" . strtoupper($extension) . ")",
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'host' => 'localhost',
            'port' => '0',
            'username' => 'sqlite',
            'password' => '',
        ]);

        // 9. Build detailed schema response
        $columnsInfo = [];
        foreach ($cleanHeaders as $cHeader) {
            $columnsInfo[] = [
                'name' => $cHeader,
                'type' => strtolower($columnTypes[$cHeader]),
                'nullable' => true,
                'sample_values' => []
            ];
        }

        // Fetch small distinct sample for text columns
        foreach ($columnsInfo as &$c) {
            if (in_array($c['type'], ['text', 'varchar'])) {
                try {
                    $sStmt = $pdo->query("SELECT DISTINCT \"{$c['name']}\" FROM \"{$tableName}\" WHERE \"{$c['name']}\" IS NOT NULL LIMIT 8");
                    $c['sample_values'] = $sStmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Exception $e) {
                    // Skip
                }
            }
        }

        $schemaDetails = [
            $tableName => $columnsInfo
        ];

        return [
            'success' => true,
            'database' => [
                'id' => $targetDb->id,
                'name' => $targetDb->name,
                'driver' => 'sqlite',
                'database' => $targetDb->database,
                'created_at' => $targetDb->created_at,
            ],
            'schema' => [
                'tables' => [$tableName],
                'schema_details' => $schemaDetails,
                'table_counts' => [
                    $tableName => $totalRows
                ]
            ],
            'stats' => [
                'total_rows' => $totalRows,
                'total_columns' => count($cleanHeaders),
                'table_name' => $tableName
            ]
        ];
    }
}
