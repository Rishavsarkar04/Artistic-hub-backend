<?php

namespace App\Notifications;

use App\Enums\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password reset email. The link opens the reset page of the user's own area: the storefront's for
 * customers, the admin panel's for admins (backend SRS, BE-AUTH-01).
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $token,
        public Role $role,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your password')
            ->line('We received a request to reset the password for your '.config('app.name').' account.')
            ->action('Reset password', $this->resetUrl($notifiable->getEmailForPasswordReset()))
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes and can be used once.')
            ->line("If you didn't ask for this, you can ignore this email: your password stays the same.");
    }

    /** e.g. https://shop.example/reset-password?token=…&email=… (or /admin/reset-password for admins). */
    public function resetUrl(string $email): string
    {
        $path = $this->role === Role::Admin ? '/admin/reset-password' : '/reset-password';

        return rtrim((string) config('app.frontend_url'), '/').$path.'?'.http_build_query(['token' => $this->token, 'email' => $email]);
    }
}
