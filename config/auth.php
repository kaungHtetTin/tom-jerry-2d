<?php

return ['check' => fn () => \App\Services\AdminAuth::user() !== null, 'can' => null, 'login' => 'admin/login'];
