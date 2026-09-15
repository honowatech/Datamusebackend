<?php

namespace App\Http\Controllers;

use App\Models\BusinessMetric;
use Illuminate\Http\Request;

class BusinessMetricController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'target_database_id' => 'required|numeric'
        ]);

        $metrics = BusinessMetric::where('user_id', $request->user()->id)
            ->where('target_database_id', $request->target_database_id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $metrics
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'target_database_id' => 'required|numeric|exists:target_databases,id',
            'term' => 'required|string|max:255',
            'sql_definition' => 'required|string',
            'description' => 'nullable|string'
        ]);

        // Ensure database belongs to user
        $db = $request->user()->targetDatabases()->findOrFail($request->target_database_id);

        $metric = BusinessMetric::create([
            'user_id' => $request->user()->id,
            'target_database_id' => $db->id,
            'term' => $request->term,
            'sql_definition' => $request->sql_definition,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Métrique métier créée avec succès',
            'data' => $metric
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'term' => 'sometimes|required|string|max:255',
            'sql_definition' => 'sometimes|required|string',
            'description' => 'nullable|string'
        ]);

        $metric = BusinessMetric::where('user_id', $request->user()->id)->findOrFail($id);
        $metric->update($request->only(['term', 'sql_definition', 'description']));

        return response()->json([
            'success' => true,
            'message' => 'Métrique métier mise à jour',
            'data' => $metric
        ]);
    }

    public function destroy($id, Request $request)
    {
        $metric = BusinessMetric::where('user_id', $request->user()->id)->findOrFail($id);
        $metric->delete();

        return response()->json([
            'success' => true,
            'message' => 'Métrique métier supprimée'
        ]);
    }
}
