<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('personal-app.users.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
