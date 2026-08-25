<?php

// app/Notifications/OrderStatusNotification.php
//
// WHY THIS EXISTS:
//   Closes a real gap flagged during pre-demo review (Aug 2026): order
//   confirm/cancel already updates the DB and creates an in-app bell
//   notification (see OrderController::notifyCustomer()), but no actual
//   email reaches the customer. This adds that email, for exactly the two
//   moments a customer needs to know about even if they're not sitting in
//   the app: their order was CONFIRMED (production is starting) or
//   CANCELLED (with the manager's stated reason, if given).
//
//   Deliberately NOT sent for every possible order status — a manager
//   manually setting a production stage through the same admin endpoint
//   does not trigger this; that's covered separately by
//   StageAdvancedNotification, so customers don't get double emails for
//   what is functionally one event.
//
// STYLE: mirrors CustomResetPasswordNotification.php exactly — mail-only
// channel, synchronous (not queued), for the same beginner-team
// debuggability reason stated there: if Mailtrap doesn't receive it, the
// error shows up immediately instead of hiding in a queue worker.
//
// MAILTRAP: caught automatically in local env via MAIL_MAILER=smtp
// pointing at sandbox.smtp.mailtrap.io, confirmed working end-to-end
// (Aug 25 2026 — CustomResetPasswordNotification test delivered
// successfully).

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusNotification extends Notification
{
    use Queueable;

    /**
     * @param int         $orderId The order this notification is about.
     * @param string      $status  Exactly 'confirmed' or 'cancelled' — this
     *                             class is only ever constructed for those
     *                             two cases, enforced at the call site in
     *                             OrderController::adminUpdate(), not here.
     * @param string|null $reason  Manager's note, shown only when cancelled
     *                             and a reason was actually provided.
     */
    public function __construct(
        private readonly int $orderId,
        private readonly string $status,
        private readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        $orderUrl    = $frontendUrl . '/customer/orders/' . $this->orderId;

        $mail = (new MailMessage)
            ->greeting('Hello, ' . $notifiable->name . '!');

        if ($this->status === 'confirmed') {
            $mail->subject("Your VFRB Order #{$this->orderId} Has Been Confirmed")
                ->line("Good news — your order #{$this->orderId} has been reviewed and confirmed by VFRB Enterprise.")
                ->line('Production will begin shortly. You can track its progress at any time from your order page.')
                ->action('View My Order', $orderUrl);
        } else {
            // 'cancelled'
            $mail->subject("Update on Your VFRB Order #{$this->orderId}")
                ->line("We're sorry to let you know that order #{$this->orderId} has been cancelled.");
            if ($this->reason) {
                $mail->line("Reason given: {$this->reason}");
            }
            $mail->line('If you have questions about this, please reply through your order\'s message thread and our team will follow up.')
                ->action('View My Order', $orderUrl);
        }

        return $mail->salutation('— VFRB Enterprise System');
    }
}
