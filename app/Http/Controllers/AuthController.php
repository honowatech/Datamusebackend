<?php

namespace App\Http\Controllers;

use App\Enums\ProjectRole;
use App\Enums\UserRole;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserResource;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Services\Survey\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public const JOIN_CODE_ERROR = 'Ce code de connexion est invalide ou expiré.';

    public function __construct(private readonly MembershipService $membership) {}

    /**
     * Inscription d'un nouvel utilisateur.
     *
     * Réponse rétro-compatible : `user`/`token` à la racine + `data: {token, user, project?}` (contrat AuthResponse).
     * `join_code` optionnel : l'utilisateur rejoint le projet avec le rôle/zone de l'invitation (B-03).
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'join_code' => 'nullable|string|size:8',
            'phone' => 'nullable|string|max:30',
            'locale' => 'nullable|string|in:fr,en',
        ]);

        $invitation = null;
        if ($request->filled('join_code')) {
            $invitation = $this->membership->findUsableJoinCode((string) $request->join_code);
            if ($invitation === null) {
                throw ValidationException::withMessages(['join_code' => [self::JOIN_CODE_ERROR]]);
            }
        }

        [$user, $project] = DB::transaction(function () use ($request, $invitation) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'locale' => $request->locale ?: 'fr',
                'role' => $invitation?->role === ProjectRole::Enqueteur ? UserRole::Enqueteur : UserRole::Analyste,
            ]);

            $project = null;
            if ($invitation instanceof ProjectInvitation) {
                $this->membership->accept($invitation, $user);
                $project = $invitation->project->loadCount(['members', 'surveys']);
            }

            return [$user, $project];
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        $data = [
            'token' => $token,
            'user' => new UserResource($user),
        ];
        if ($project !== null) {
            $data['project'] = (new ProjectResource($project))->forUser($user);
        }

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
            'has_gemini_api_key' => ! empty($user->gemini_api_key),
            'has_deepseek_api_key' => ! empty($user->deepseek_api_key),
            'message' => 'Inscription réussie.',
            'data' => $data,
        ], 201);
    }

    /**
     * Connexion de l'utilisateur et génération du token.
     *
     * Réponse rétro-compatible : `user`/`token` à la racine + `data: {token, user}` (contrat AuthResponse).
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Les identifiants de connexion fournis sont incorrects.',
            ], 401);
        }

        // Supprimer les anciens tokens de cet utilisateur (optionnel, pour éviter d'accumuler)
        $user->tokens()->delete();

        $token = $user->createToken($request->input('device_name') ?: 'auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
            'has_gemini_api_key' => ! empty($user->gemini_api_key),
            'has_deepseek_api_key' => ! empty($user->deepseek_api_key),
            'message' => 'Connexion réussie.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
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
            'message' => 'Déconnexion réussie.',
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
            'has_gemini_api_key' => ! empty($user->gemini_api_key),
            'has_deepseek_api_key' => ! empty($user->deepseek_api_key),
            'data' => ['user' => new UserResource($user)],
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
            'has_gemini_api_key' => ! empty($user->gemini_api_key),
            'has_deepseek_api_key' => ! empty($user->deepseek_api_key),
        ]);
    }
}
