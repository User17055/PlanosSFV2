<?php

return [
    'enabled'=>(bool)env('SITE_API_ENABLED',false),
    'key'=>env('SITE_API_KEY'),
    'public_url'=>rtrim(env('SITE_PUBLIC_URL','https://saofrancisco.vet.br'),'/'),
    'token_hours'=>(int)env('SITE_TOKEN_HOURS',24),
];
