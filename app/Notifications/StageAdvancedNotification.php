<?php

// app/Notifications/StageAdvancedNotification.php
//
// WHY THIS EXISTS:
//   Same real gap as OrderStatusNotification, different trigger: the
//   7-stage production auto-advance already inserts an in-app bell
//   notification (see ProductionController::notifyStageAdvance()), but no
//   actual email reaches the customer when their order moves to the next
//   stage (e.g. Cutting → Sewing). This adds that email.
//
//   Sent to the CUSTOMER only — managers already get their own in-app
//   notification from the same trigger point and don't need an email for
//   every stage move across every order, which would be far too noisy for
//   day-to-day staff use.
//
// STYLE: mirrors CustomResetPasswordNotification.php exactly — mail-only
// channel, synchronous (not queued), same reasoning: immediate, visible
// failure in Laravel's logs if Mailtrap doesn't receive it, rather than a
// silently stuck queue job.

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StageAdvancedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $orderId,
        private readonly string $fromStage,
        private readonly string $toStage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        $orderUrl    = $frontendUrl . '/customer/orders/' . $this->orderId;
        $fromLabel   = ucfirst($this->fromStage);
        $toLabel     = ucfirst($this->toStage);

        return (new MailMessage)
            ->subject("Your VFRB Order #{$this->orderId} Has Moved to {$toLabel}")
            ->greeting('Hello, ' . $notifiable->name . '!')
            ->line("Your order #{$this->orderId} has progressed from {$fromLabel} to {$toLabel}.")
            ->line('You can check the full production timeline any time from your order page.')
            ->action('View My Order', $orderUrl)
            ->salutation('— VFRB Enterprise System');
    }
}
