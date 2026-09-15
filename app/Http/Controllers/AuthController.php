<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel utilisateur
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
            'has_gemini_api_key' => !empty($user->gemini_api_key),
            'has_deepseek_api_key' => !empty($user->deepseek_api_key),
            'message' => 'Inscription réussie.'
        ], 201);
    }

    /**
     * Connexion de l'utilisateur et génération du token
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Les identifiants de connexion fournis sont incorrects.'
            ], 401);
        }

        // Supprimer les anciens tokens de cet utilisateur (optionnel, pour éviter d'accumuler)
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
            'has_gemini_api_key' => !empty($user->gemini_api_key),
            'has_deepseek_api_key' => !empty($user->deepseek_api_key),
            'message' => 'Connexion réussie.'
        ]);
    }

    /**
     * Déconnexion (révocation du token)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie.'
        ]);
    }

    /**
     * Récupérer l'utilisateur actuellement connecté avec le statut des clés API
     */
    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'user' => $user,
            'has_gemini_api_key' => !empty($user->gemini_api_key),
            'has_deepseek_api_key' => !empty($user->deepseek_api_key),
        ]);
    }

    /**
     * Mettre à jour les clés API chiffrées de l'utilisateur
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'gemini_api_key' => 'nullable|string',
            'deepseek_api_key' => 'nullable|string',
        ]);

        $user = $request->user();

        if ($request->has('gemini_api_key')) {
            $user->gemini_api_key = $request->gemini_api_key;
        }
        if ($request->has('deepseek_api_key')) {
            $user->deepseek_api_key = $request->deepseek_api_key;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Paramètres mis à jour avec succès.',
            'has_gemini_api_key' => !empty($user->gemini_api_key),
            'has_deepseek_api_key' => !empty($user->deepseek_api_key),
        ]);
    }
}
