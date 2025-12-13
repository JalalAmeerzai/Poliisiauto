<?php

namespace App\Listeners;

use App\Events\MessageCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendNewMessageNotification
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  \App\Events\MessageCreated  $event
     * @return void
     */
    public function handle(MessageCreated $event)
    {
        $message = $event->message;
        $fcm = new \App\Services\FCMService();

        // Single Tenant Mode: Send to global 'teachers' topic
        $topic = "/topics/teachers";

        $title = "New Message in Case: " . $message->report->case->name;
        $body = $message->type === 'audio' ? "Audio message received." : substr($message->content, 0, 50);

        $fcm->sendNotification($topic, $title, $body, ['message_id' => (string) $message->id]);
    }
}
