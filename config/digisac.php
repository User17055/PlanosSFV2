<?php

return [
    'base_url' => env('DIGISAC_BASE_URL', 'https://saofranciscopet.digisac.chat'),
    // Domínios próprios devem ser autorizados pelo administrador do servidor.
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', env('DIGISAC_ALLOWED_HOSTS', 'saofranciscopet.digisac.chat'))))),
];
