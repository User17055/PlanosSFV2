<?php

return [
    'vindi' => [
        // Opt-in only. Credentials and commercial mapping require homologation.
        'enabled' => env('VINDI_ENABLED', false),
        'base_url' => env('VINDI_BASE_URL', 'https://sandbox-app.vindi.com.br/api/v1'),
        'api_key' => env('VINDI_API_KEY'),
        'provider_account' => env('VINDI_ACCOUNT', 'central'),
        'webhook_user' => env('VINDI_WEBHOOK_USER'),
        'webhook_password' => env('VINDI_WEBHOOK_PASSWORD'),
        'timeout' => (int) env('VINDI_TIMEOUT', 15),
        // Subscription creation stays disabled until the exact account/product flow is approved.
        'allow_creation' => env('VINDI_ALLOW_CREATION', false),
        'payment_method_code' => env('VINDI_PAYMENT_METHOD_CODE', 'bank_slip'),
        // Methods the tutor can choose at checkout, as "vindi_code:Label" pairs. Codes are the ones registered in the
        // Vindi account (Configurações → Métodos de pagamento); the choice is used for the entry and every monthly bill.
        'payment_methods' => collect(explode(',', (string) env('VINDI_PAYMENT_METHODS', '')))->map(fn ($item) => array_map('trim', explode(':', $item, 2)))
            ->filter(fn ($pair) => preg_match('/^[a-z0-9_-]{1,60}$/D', $pair[0]))->mapWithKeys(fn ($pair) => [$pair[0] => ($pair[1] ?? '') ?: $pair[0]])->all(),
        'checkout_enabled' => env('VINDI_CHECKOUT_ENABLED', false),
        'code_prefix' => env('VINDI_CODE_PREFIX', 'sao-francisco'),
        // Keyed by the immutable local product_version_id, not product name.
        'checkout_plans' => json_decode(env('VINDI_CHECKOUT_PLANS', '{}'), true) ?: [],
        'payment_hosts' => array_filter(array_map('trim',explode(',',env('VINDI_PAYMENT_HOSTS','app.vindi.com.br,sandbox-app.vindi.com.br')))),
    ],
    'simplesvet' => ['enabled' => false, 'mode' => 'manual', 'reason' => 'Documentação e autorização de escrita não fornecidas.'],
    'tudoprapet' => ['enabled' => false, 'mode' => 'manual', 'reason' => 'API de clientes e benefícios comerciais não homologada.'],
    'contracts' => [
        'disk' => 'local',
        'maximum_upload_kb' => (int) env('CONTRACT_MAX_UPLOAD_KB', 10240),
        'require_antivirus' => env('CONTRACT_REQUIRE_ANTIVIRUS', false),
        'clamd_socket' => env('CONTRACT_CLAMD_SOCKET', 'tcp://127.0.0.1:3310'),
    ],
];
