<?php

// app/Notifications/CustomVerifyEmailNotification.php
//
// WHY THIS EXISTS:
//   Laravel's default VerifyEmail notification generates a URL for a WEB route.
//   We need it to point to our API route: /api/email/verify/{id}/{hash}
//   which is a SIGNED URL (tamper-proof, time-limited).
//
//   After verifying, the API controller REDIRECTS to the React frontend
//   at /verify-email?status=verified — so the user sees a proper React page,
//   not raw JSON in their browser.
//
// FLOW:
//   Register → this email sent → user clicks link → /api/email/verify/{id}/{hash}
//   → Laravel checks signature → marks email_verified_at → redirect to React page
//
// MAILTRAP: Caught automatically by your sandbox.smtp.mailtrap.io config.

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmailNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->buildVerificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verify Your VFRB Enterprise Email Address')
            ->greeting('Welcome to VFRB Enterprise, ' . $notifiable->name . '!')
            ->line('Thank you for registering. One last step — please verify your email address to activate your account.')
            ->action('Verify Email Address', $verificationUrl)
            ->line('This verification link will **expire in 60 minutes**.')
            ->line('If you did not create a VFRB Enterprise account, no action is needed.')
            ->salutation('— VFRB Enterprise System');
    }

    /**
     * Build a SIGNED URL pointing to our API verification endpoint.
     *
     * WHY SIGNED: A signed URL contains a cryptographic signature of all its
     * parameters. If anyone tampers with the ID or hash in the URL, Laravel's
     * ValidateSignature middleware rejects it with 403. This prevents attackers
     * from verifying someone else's email.
     *
     * The route name 'verification.verify' is registered in api.php.
     */
    protected function buildVerificationUrl(object $notifiable): string
    {
        $expiry = Config::get('auth.verification.expire', 60);

        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes($expiry),
            [
                'id'   => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
