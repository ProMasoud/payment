<?php

namespace Shetabit\Payment\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Shetabit\Payment\Contracts\DriverInterface;
use Shetabit\Payment\Invoice;

class InvoiceVerifiedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $driver;
    public $invoice;

    /**
     * InvoiceVerifiedEvent constructor.
     *
     * @param DriverInterface $driver
     * @param Invoice $invoice
     */
    public function __construct(DriverInterface $driver, Invoice $invoice)
    {
        $this->driver = $driver;
        $this->invoice = $invoice;
    }
}
