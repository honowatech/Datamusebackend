<?php

namespace App\Http\Controllers;

use App\Models\TargetDatabase;
use App\Models\BusinessMetric;
use App\Support\ApiKeyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Exception;

class ChatController extends Controller
{
    public function __construct(private readonly ApiKeyResolver $apiKeys)
    {
    }

    /**
     * Set up dynamic database connection using a saved configuration ID
     */
    private function connectTargetDb($databaseId, $user)
    {
        // Ensure the connection is reachable by the authenticated user: owned, or the materialized
        // datasource of a survey he supervises (module Enquêtes, B-09b).
        $dbConfig = TargetDatabase::query()->accessibleBy($user)->findOrFail($databaseId);

        if ($dbConfig->driver === 'sqlite') {
            Config::set('database.connections.target_db', [
                'driver' => 'sqlite',
                'database' => $dbConfig->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        } else {
            Config::set('database.connections.target_db', [
                'driver' => $dbConfig->driver ?? 'mysql',
                'host' => $dbConfig->host,
                'port' => $dbConfig->port,
                'database' => $dbConfig->database,
                'username' => $dbConfig->username,
                'password' => $dbConfig->password, // Decrypted automatically by model casting
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'options' => [
                    \PDO::ATTR_TIMEOUT => 3, // 3 seconds timeout to prevent DoS
                ]
            ]);
        }
        
        DB::purge('target_db');
    }

    /**
     * Generate SQL using AI API (supports multi-turn conversation for inserts)
     */
    public function generateSql(Request $request, \App\Services\LlmRouterService $routerService, \App\Services\SqlGenerationService $sqlGenerationService)
    {
        $request->validate([
            'prompt' => 'required|string',
            'schema' => 'required|array',
            'apiKey' => 'nullable|string',
            'database_id' => 'required|numeric',
            'provider' => 'nullable|string',
            'conversationHistory' => 'nullable|array'
        ]);

        $user = $request->user();
        
        try {
            // Authorization check (propriétaire ou source d'enquête supervisée, B-09b)
            $dbConfig = TargetDatabase::query()->accessibleBy($user)->findOrFail($request->database_id);
            $driver = $dbConfig->driver ?? 'mysql';

            $provider = $request->provider ?? 'gemini';
            $apiKey = $this->apiKeys->resolve($request, $user, $provider);

            if (empty($apiKey)) {
                return response()->json([
                    'success' => false,
                    'message' => ApiKeyResolver::missingKeyMessage($provider)
                ], 400);
            }

            $prompt = $request->prompt;
            $history = $request->conversationHistory ?? [];
            
            // 1. Semantic Routing: Determine query complexity
            $category = $routerService->categorize($prompt, $provider, $apiKey);
            
            // Fetch business metrics for the user's database
            $metrics = BusinessMetric::where('user_id', $user->id)
                ->where('target_database_id', $request->database_id)
                ->get()
                ->toArray();
                
            // 2. Generate SQL based on the detected category and schema
            $decoded = $sqlGenerationService->generate(
                $category, 
                $prompt, 
                $history, 
                $request->schema, 
                $driver, 
                $provider, 
                $apiKey,
                $metrics
            );

            // 3. Return formatted response to frontend
            if ($decoded['type'] === 'message') {
                return response()->json([
                    'success' => true,
                    'type' => 'message',
                    'message' => $decoded['content']
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'type' => 'sql',
                    'sql_query' => $decoded['content'],
                    'chart_config' => $decoded['chart_config'] ?? null,
                    'python_code' => $decoded['python_code'] ?? null
                ]);
            }
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Base de données introuvable ou non autorisée.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Translate technical database errors to non-developer readable French
     */
    private function translateDatabaseError($message)
    {
        if (stripos($message, 'Table\' doesn\'t exist') !== false || stripos($message, '1146 Table') !== false) {
            return "Désolé, il semble que la table demandée n'existe pas encore dans votre base de données. Veuillez vérifier si les tables ont bien été créées.";
        }
        if (stripos($message, 'Unknown column') !== false || stripos($message, '1054 Unknown column') !== false) {
            return "Une information (colonne) demandée est introuvable. La structure de votre table a peut-être changé ou le nom de la colonne est incorrect.";
        }
        if (stripos($message, 'isn\'t in GROUP BY') !== false || stripos($message, '1055') !== false) {
            return "Nous avons rencontré une règle stricte de regroupement de données (ONLY_FULL_GROUP_BY). La requête a tenté de combiner des informations détaillées et des résumés de manière non autorisée par le serveur.";
        }
        if (stripos($message, 'Syntax error') !== false || stripos($message, '1064') !== false) {
            return "La structure de la commande générée contient une petite erreur de syntaxe. Je vais ajuster ma formulation pour la prochaine tentative.";
        }
        if (stripos($message, 'Access denied') !== false || stripos($message, '1045') !== false) {
            return "L'accès à la base de données a été refusé. Veuillez vérifier vos identifiants de connexion dans les paramètres.";
        }
        
        return "Une petite difficulté technique est survenue lors de la lecture des données. Veuillez reformuler votre question ou vérifier les paramètres de votre base de données.";
    }

    /**
     * Execute SQL Query securely
     */
    public function executeSql(Request $request)
    {
        $request->validate([
            'sql_query' => 'required|string',
            'database_id' => 'required|numeric'
        ]);

        try {
            $this->connectTargetDb($request->database_id, $request->user());
            
            $query = trim(str_replace(';', '', $request->sql_query));
            
            // Clean query check for basic write command protection.
            // E-03 : `WITH …` (CTE, y compris `WITH RECURSIVE`) est une lecture. Sans ce cas, la question
            // « quels sont les freins les plus cités ? » — dont la requête éclate une colonne multi-valuée
            // avec une CTE récursive — partait dans la branche d'écriture : le chat répondait « Requête de
            // modification effectuée avec succès » et n'affichait aucune ligne.
            $isSelect = preg_match('/^\s*(select|show|with)\b/i', $query) === 1;

            if ($isSelect) {
                // Double protection: block multi-query statements and non-read queries containing hazardous keywords
                if (stripos($query, ';') !== false || stripos($query, 'update') !== false || stripos($query, 'delete') !== false || stripos($query, 'drop') !== false || stripos($query, 'alter') !== false || stripos($query, 'truncate') !== false || stripos($query, 'insert') !== false) {
                    throw new Exception("Requête non autorisée pour une opération de lecture.");
                }

                $results = DB::connection('target_db')->select($request->sql_query);
                return response()->json([
                    'success' => true,
                    'type' => 'read',
                    'results' => $results
                ]);
            } else {
                // Execute DML / DDL modifications
                DB::connection('target_db')->statement($request->sql_query);
                return response()->json([
                    'success' => true,
                    'type' => 'write',
                    'message' => 'Requête de modification effectuée avec succès.'
                ]);
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Base de données introuvable ou non autorisée.'
            ], 404);
        } catch (Exception $e) {
            $friendlyMessage = $this->translateDatabaseError($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $friendlyMessage,
                'debug_message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Analyse textuelle des résultats SQL (Data Analyst)
     */
    public function analyzeData(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string',
            'data_summary' => 'required|array',
            'statistical_profile' => 'nullable|array',
            'apiKey' => 'nullable|string',
            'provider' => 'nullable|string'
        ]);

        $user = $request->user();
        $provider = $request->provider ?? 'gemini';
        $apiKey = $this->apiKeys->resolve($request, $user, $provider);

        if (empty($apiKey)) {
            return response()->json(['success' => false, 'message' => "Clé API non configurée."], 400);
        }

        $systemInstruction = "Tu es un Data Analyst expert. Tu dois rédiger une synthèse métier analytique pour répondre à la question de l'utilisateur. Tu as accès à un échantillon de données et potentiellement à un profil statistique complet (moyenne, médiane, écart-type, distribution).
Instructions :
- Appuie-toi fortement sur les métriques statistiques si elles sont fournies.
- Identifie les valeurs aberrantes (outliers), les tendances et les anomalies en utilisant ces statistiques.
- Calcule et mentionne des pourcentages et proportions pertinents.
- Fournis des recommandations et des insights métier actionnables.
- Ne cite pas de code SQL. Sois clair, professionnel, et retourne le résultat en Markdown riche (listes à puces, texte en gras, sans bloc ```markdown global).";
        
        // JSON_UNESCAPED_UNICODE : sans cela « Enquêteur » arrive au modèle sous la forme
        // « Enquêteur », qu'il recopie tel quel dans son analyse (constaté en E-03).
        $json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $promptText = "Question initiale : " . $request->prompt . "\n\nExtrait des résultats (50 premières lignes max) : \n" . json_encode($request->data_summary, $json);

        if ($request->has('statistical_profile') && !empty($request->statistical_profile)) {
            $promptText .= "\n\nProfil statistique complet du dataset : \n" . json_encode($request->statistical_profile, $json);
        }

        try {
            // Passe par LlmProviderService (et non Http:: en direct) : un seul point d'appel, donc un seul
            // endroit où le mode rejeu (LLM_DRIVER=replay) s'applique.
            $aiResponse = app(\App\Services\LlmProviderService::class)->generate(
                $provider,
                $apiKey,
                [['role' => 'user', 'content' => $promptText]],
                $systemInstruction,
                ['prompt_name' => 'chat_analysis'],
            );

            return response()->json([
                'success' => true,
                'analysis' => trim($aiResponse)
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur d\'analyse : ' . $e->getMessage()], 500);
        }
    }

    /**
     * Compute data profile for the given SQL query on the target DB
     */
    public function profileData(Request $request, \App\Services\DataProfilingService $profilingService)
    {
        $request->validate([
            'sql_query' => 'required|string',
            'database_id' => 'required|numeric'
        ]);

        try {
            $this->connectTargetDb($request->database_id, $request->user());
            
            $profile = $profilingService->profileResults($request->sql_query);

            return response()->json([
                'success' => true,
                'profile' => $profile
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du profilage: ' . $e->getMessage()
            ], 500);
        }
    }
}
