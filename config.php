<?php
// Configurações da barbearia. Ajuste conforme o cliente.
return [
    'shop_name'       => 'Barbearia Exemplo',
    'timezone'        => 'America/Sao_Paulo',

    // Horário de funcionamento (segunda a sexta)
    'open'            => '08:00',
    'close'           => '18:00',
    'slot_minutes'    => 60,              // duração de cada atendimento
    'lunch'           => ['12:00', '13:00'], // intervalo sem atendimento; use null para desativar

    // Quantos dias à frente o cliente pode agendar
    'days_ahead'      => 30,

    // WhatsApp do barbeiro: DDI + DDD + número, só dígitos (ex.: 5522999999999)
    'barber_whatsapp' => getenv('BARBER_WHATSAPP') ?: '5522999999999',

    // Envio automático via WhatsApp Cloud API (Meta). Se vazio, o sistema
    // devolve um link wa.me com a mensagem pronta como alternativa.
    'whatsapp_cloud'  => [
        'token'           => getenv('WA_TOKEN') ?: '',
        'phone_number_id' => getenv('WA_PHONE_ID') ?: '',
    ],

    'db_path'         => __DIR__ . '/data/agenda.sqlite',
];
