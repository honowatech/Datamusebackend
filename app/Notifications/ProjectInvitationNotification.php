<?php

namespace App\Notifications;

use App\Http\Resources\InvitationResource;
use App\Models\ProjectInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation par e-mail à rejoindre un projet d'enquête (lien `FRONTEND_URL/invitations/accept?token=…`).
 * Envoyée via `Notification::route('mail', $email)->notify(...)` (destinataire sans compte possible).
 */
class ProjectInvitationNotification extends Notification
{
    public function __construct(public readonly ProjectInvitation $invitation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['project', 'inviter']);
        $project = $invitation->project;
        $inviter = $invitation->inviter?->name ?? 'Un membre de Datamuse';
        $role = $invitation->role?->value ?? 'enqueteur';
        $url = self::acceptUrl($invitation);

        $message = (new MailMessage)
            ->subject("Invitation au projet « {$project->name} » — Datamuse")
            ->greeting('Bonjour,')
            ->line("{$inviter} vous invite à rejoindre le projet d'enquête « {$project->name} » en tant que {$role}.");

        if ($invitation->zone) {
            $message->line("Zone : {$invitation->zone}.");
        }

        $message->action("Accepter l'invitation", $url);

        if ($invitation->expires_at) {
            $message->line('Ce lien expire le '.$invitation->expires_at->locale('fr')->isoFormat('LL').'.');
        }

        return $message->line("Si vous n'attendiez pas cette invitation, vous pouvez ignorer cet e-mail.");
    }

    public static function acceptUrl(ProjectInvitation $invitation): string
    {
        return InvitationResource::joinUrl($invitation) ?? rtrim((string) config('app.frontend_url'), '/').'/invitations/accept';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'project_id' => $this->invitation->project_id,
            'url' => self::acceptUrl($this->invitation),
        ];
    }
}
