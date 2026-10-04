<?php

namespace App\Tests\Validation;

use App\Validation\Notification;

final class NotificationTest
{
    public function testItCollects(): void
    {
        (new Notification())->add('code');
    }
}
