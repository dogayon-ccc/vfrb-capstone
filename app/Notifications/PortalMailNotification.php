<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsEmailPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// The email twin of an in-app notification (new order, message, QC result, low stock): a subject, plain lines and one portal link.
class PortalMailNotification extends Notification
{
    use Queueable, RespectsEmailPreference;

    public function __construct(
        private readonly string $subject,
        private readonly array $lines,
        private readonly string $actionLabel,
        private readonly string $path,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->mailChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Hello, ' . $notifiable->name . '!');
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        return $mail->action($this->actionLabel, $this->portalUrl($this->path))->salutation('— VFRB Enterprise');
    }
}
