<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Leads dos anúncios (Pluga): o código vai no endereço do webhook; sem ele o recebimento fica desligado.
    'leads' => [
        'token' => env('LEADS_WEBHOOK_TOKEN'),
        'franchise_code' => env('LEADS_FRANCHISE_CODE', 'CENTRAL'),
        // Aviso de lead novo no celular pelo ntfy (app gratuito). Sem tópico, nada é enviado. O tópico funciona como senha:
        // quem o conhece lê os avisos, por isso fica só no .env.
        'alert' => [
            'server' => env('LEADS_NTFY_SERVER', 'https://ntfy.sh'),
            'topic' => env('LEADS_NTFY_TOPIC'),
            // Horas de aviso por dia da semana (1 = segunda … 7 = domingo), no horário de São Paulo, como [início, fim).
            // A noite das 18h às 5h fica 18–24 num dia e 0–5 no seguinte.
            'hours' => [
                1 => [[0, 5], [11, 17], [18, 24]],
                2 => [[0, 5], [11, 17], [18, 24]],
                3 => [[0, 5], [11, 17], [18, 24]],
                4 => [[0, 5], [11, 17], [18, 24]],
                5 => [[0, 5], [11, 17], [18, 24]],
                6 => [[0, 5], [14, 24]],
                7 => [[0, 24]],
            ],
        ],
    ],

];
