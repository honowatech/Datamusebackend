<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class DataProfilingService
{
    public function profileResults(string $sqlQuery, string $connectionName = 'target_db'): array
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        // Wrap query
        $subquery = "($sqlQuery) AS subq";
        
        // Fetch sample to determine columns
        $sample = $connection->select("$sqlQuery LIMIT 1");
        
        if (empty($sample)) {
            return ['total_rows' => 0, 'total_columns' => 0, 'columns' => []];
        }

        $sampleRow = (array)$sample[0];
        $totalColumns = count($sampleRow);

        $totalRowsRes = $connection->select("SELECT COUNT(*) as cnt FROM $subquery");
        $totalRows = $totalRowsRes[0]->cnt;

        $columnsProfile = [];

        foreach ($sampleRow as $colName => $sampleVal) {
            $colNameQuoted = $driver === 'mysql' ? "`$colName`" : "\"$colName\"";
            
            $type = 'text';
            if (is_int($sampleVal) || is_float($sampleVal) || (is_numeric($sampleVal) && !preg_match('/^0\d+/', $sampleVal))) {
                $type = 'numeric';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$sampleVal)) {
                $type = 'date';
            }
            
            $profile = [
                'type' => $type
            ];
            
            try {
                if ($type === 'numeric') {
                    $metricsSql = "SELECT 
                        COUNT($colNameQuoted) as cnt,
                        SUM(CASE WHEN $colNameQuoted IS NULL THEN 1 ELSE 0 END) as null_count,
                        MIN($colNameQuoted) as min_val,
                        MAX($colNameQuoted) as max_val,
                        AVG($colNameQuoted) as mean_val,
                        SUM($colNameQuoted) as sum_val";
                    
                    if ($driver === 'mysql' || $driver === 'pgsql') {
                        $metricsSql .= ", STDDEV($colNameQuoted) as stddev_val";
                    } else {
                        $metricsSql .= ", 0 as stddev_val";
                    }
                    
                    $metricsSql .= " FROM $subquery";
                    
                    $metrics = $connection->select($metricsSql)[0];
                    $profile['count'] = (int)$metrics->cnt;
                    $profile['null_count'] = (int)$metrics->null_count;
                    $profile['min'] = is_numeric($metrics->min_val) ? (float)$metrics->min_val : null;
                    $profile['max'] = is_numeric($metrics->max_val) ? (float)$metrics->max_val : null;
                    $profile['mean'] = is_numeric($metrics->mean_val) ? (float)$metrics->mean_val : null;
                    $profile['stddev'] = is_numeric($metrics->stddev_val) ? (float)$metrics->stddev_val : null;
                    $profile['sum'] = is_numeric($metrics->sum_val) ? (float)$metrics->sum_val : null;
                    
                    $offset = floor($profile['count'] / 2);
                    $medianQuery = "SELECT $colNameQuoted as val FROM $subquery WHERE $colNameQuoted IS NOT NULL ORDER BY $colNameQuoted LIMIT 1 OFFSET $offset";
                    $medianRes = $connection->select($medianQuery);
                    $profile['median'] = !empty($medianRes) ? (float)$medianRes[0]->val : null;
                    
                    // Approximate p25 and p75
                    $p25Offset = floor($profile['count'] * 0.25);
                    $p25Query = "SELECT $colNameQuoted as val FROM $subquery WHERE $colNameQuoted IS NOT NULL ORDER BY $colNameQuoted LIMIT 1 OFFSET $p25Offset";
                    $p25Res = $connection->select($p25Query);
                    $profile['p25'] = !empty($p25Res) ? (float)$p25Res[0]->val : null;

                    $p75Offset = floor($profile['count'] * 0.75);
                    $p75Query = "SELECT $colNameQuoted as val FROM $subquery WHERE $colNameQuoted IS NOT NULL ORDER BY $colNameQuoted LIMIT 1 OFFSET $p75Offset";
                    $p75Res = $connection->select($p75Query);
                    $profile['p75'] = !empty($p75Res) ? (float)$p75Res[0]->val : null;
                    
                } elseif ($type === 'date') {
                    $metricsSql = "SELECT 
                        MIN($colNameQuoted) as min_date,
                        MAX($colNameQuoted) as max_date,
                        SUM(CASE WHEN $colNameQuoted IS NULL THEN 1 ELSE 0 END) as null_count
                        FROM $subquery";
                    $metrics = $connection->select($metricsSql)[0];
                    
                    $profile['min_date'] = $metrics->min_date;
                    $profile['max_date'] = $metrics->max_date;
                    $profile['null_count'] = (int)$metrics->null_count;
                    
                    if ($profile['min_date'] && $profile['max_date']) {
                        $minDate = new \DateTime($profile['min_date']);
                        $maxDate = new \DateTime($profile['max_date']);
                        $profile['span_days'] = $minDate->diff($maxDate)->days;
                    } else {
                        $profile['span_days'] = null;
                    }
                } else {
                    $metricsSql = "SELECT 
                        COUNT(DISTINCT $colNameQuoted) as distinct_count,
                        SUM(CASE WHEN $colNameQuoted IS NULL THEN 1 ELSE 0 END) as null_count
                        FROM $subquery";
                    $metrics = $connection->select($metricsSql)[0];
                    
                    $profile['distinct_count'] = (int)$metrics->distinct_count;
                    $profile['null_count'] = (int)$metrics->null_count;
                    
                    $topValuesSql = "SELECT $colNameQuoted as val, COUNT(*) as cnt FROM $subquery WHERE $colNameQuoted IS NOT NULL GROUP BY $colNameQuoted ORDER BY cnt DESC LIMIT 5";
                    $topValuesRes = $connection->select($topValuesSql);
                    
                    $top5 = [];
                    foreach ($topValuesRes as $tv) {
                        $top5[(string)$tv->val] = (int)$tv->cnt;
                    }
                    $profile['top_5_values'] = $top5;
                    
                    if (count($topValuesRes) > 0) {
                        $profile['most_frequent_value'] = $topValuesRes[0]->val;
                        $profile['most_frequent_pct'] = $totalRows > 0 ? round(($topValuesRes[0]->cnt / $totalRows) * 100, 2) : 0;
                    }
                }
            } catch (Exception $e) {
                $profile['error'] = $e->getMessage();
            }
            
            $columnsProfile[$colName] = $profile;
        }

        return [
            'total_rows' => $totalRows,
            'total_columns' => $totalColumns,
            'columns' => $columnsProfile
        ];
    }
}
