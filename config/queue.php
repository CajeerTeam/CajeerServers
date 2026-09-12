<?php
return ['default' => env('QUEUE_CONNECTION', 'redis'), 'drivers' => ['redis', 'pgsql', 'rabbitmq']];
