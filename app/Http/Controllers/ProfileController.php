<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil de l'utilisateur connecté (page « Profil »).
 *
 * - `GET    /profile`          profil + compteurs (projets, questionnaires créés) ;
 * - `PUT    /profile`          nom, e-mail, téléphone, langue ;
 * - `PUT    /profile/password` mot de passe actuel exigé ; les autres sessions sont révoquées ;
 * - `POST   /profile/avatar`   photo (jpg, png, webp ≤ 2 Mo) ; `GET` la sert, `DELETE` la retire.
 */
class ProfileController extends ApiController
{
    private const DISK = 'local';

    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['required', 'string', Rule::in(['fr', 'en'])],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
        ]);

        $user->fill($data)->save();

        return $this->ok($this->payload($user));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'password.different' => "Le nouveau mot de passe doit être différent de l'actuel.",
            'password.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
        ]);

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Le mot de passe actuel est incorrect.']]);
        }

        $user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();

        // Les autres appareils doivent se reconnecter ; la session courante reste ouverte.
        $others = $user->tokens();
        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $others->whereKeyNot($current->getKey());
        }
        $others->delete();

        return $this->ok(['message' => 'Mot de passe modifié.']);
    }

    public function storeAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.max' => 'La photo ne doit pas dépasser 2 Mo.',
            'avatar.mimes' => 'Formats acceptés : JPG, PNG ou WebP.',
        ]);

        $user = $request->user();
        $file = $request->file('avatar');
        $path = $file->storeAs('avatars', $user->id.'-'.Str::random(16).'.'.$file->extension(), self::DISK);

        $this->deleteAvatarFile($user);
        $user->forceFill(['avatar_path' => $path, 'avatar_updated_at' => Carbon::now()])->save();

        return $this->ok($this->payload($user));
    }

    public function showAvatar(Request $request): Response
    {
        $user = $request->user();
        $disk = Storage::disk(self::DISK);

        if ($user->avatar_path === null || ! $disk->exists($user->avatar_path)) {
            return $this->fail('Aucune photo de profil.', 404);
        }

        return $disk->response($user->avatar_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->deleteAvatarFile($user);
        $user->forceFill(['avatar_path' => null, 'avatar_updated_at' => null])->save();

        return $this->ok($this->payload($user));
    }

    private function deleteAvatarFile(User $user): void
    {
        if ($user->avatar_path !== null) {
            Storage::disk(self::DISK)->delete($user->avatar_path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        return [
            'user' => new UserResource($user),
            'stats' => [
                'projects_count' => $user->projects()->count(),
                'surveys_created_count' => Survey::query()->where('created_by', $user->id)->count(),
            ],
        ];
    }
}
