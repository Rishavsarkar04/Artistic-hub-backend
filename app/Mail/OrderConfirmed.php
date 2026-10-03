<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, when the payment is confirmed (PaymentCaptureService queues it after the commit). Uses the
 * order's snapshots only, so it shows exactly what was bought and where it goes.
 */
class OrderConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your order {$this->order->order_number} is confirmed");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.confirmed', with: [
            'order' => $this->order->loadMissing('items'),
            'ordersUrl' => rtrim((string) config('app.frontend_url'), '/').'/account/orders/'.$this->order->order_number,
        ]);
    }
}
