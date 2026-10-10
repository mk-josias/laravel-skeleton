<?php

namespace Apps\Notifications\Tests\Feature;

use Apps\Notifications\Events\NotificationPushed;
use Apps\Notifications\Mail\NotificationMail;
use Foundation\Common\Broadcasting\PrivateUserChannel;
use Foundation\Iam\Events\IamEvent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\ModuleTestCase;

class DeliveryTest extends ModuleTestCase
{
    protected string $module = 'notifications';

    public function test_a_new_notification_is_pushed_live_on_its_recipient_channel(): void
    {
        Event::fake([NotificationPushed::class]);
        $this->user(1, 'ada');

        $this->receive('notifications', IamEvent::UserRegistered->value, ['id' => 1]);

        Event::assertDispatched(NotificationPushed::class, function (NotificationPushed $event): bool {
            return $event->broadcastOn()[0]->name === 'private-user.1'
                && $event->broadcastAs() === 'user.welcome'
                && $event->broadcastWith()['title'] === 'Welcome, ada!';
        });
    }

    public function test_a_welcome_is_mailed_to_the_address_in_its_copy(): void
    {
        Mail::fake();
        $this->user(1, 'ada');

        $this->receive('notifications', IamEvent::UserRegistered->value, ['id' => 1]);

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('ada@example.com')
            && $mail->envelope()->subject === 'Welcome, ada!');
    }

    public function test_only_its_owner_joins_a_user_channel(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'key', 'secret' => 'secret', 'app_id' => 'app', 'options' => ['host' => 'localhost'],
        ]]);
        Broadcast::forgetDrivers();
        PrivateUserChannel::register();
        $ada = $this->user(1, 'ada');

        $this->postJson('/notifications/api/v1/broadcasting/auth', ['channel_name' => 'private-user.1', 'socket_id' => '1.1'], $ada)->assertOk();
        $this->postJson('/notifications/api/v1/broadcasting/auth', ['channel_name' => 'private-user.2', 'socket_id' => '1.1'], $ada)->assertForbidden();
        $this->postJson('/notifications/api/v1/broadcasting/auth', ['channel_name' => 'private-user.1', 'socket_id' => '1.1'])->assertUnauthorized();
    }
}
