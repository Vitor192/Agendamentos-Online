<?php
// Configurações da barbearia. Ajuste conforme o cliente.
return [
    'shop_name'       => 'Barbearia Exemplo',
    'timezone'        => 'America/Sao_Paulo',

    // Horário de funcionamento
    'open'            => '08:00',
    'close'           => '18:00',
    'slot_step'       => 30,                 // de quanto em quanto tempo um atendimento pode começar (minutos)
    'lunch'           => ['12:00', '13:00'], // intervalo sem atendimento; use null para desativar
    'work_days'       => [1, 2, 3, 4, 5],    // 1 = segunda ... 7 = domingo

    // Quantos dias à frente o cliente pode agendar
    'days_ahead'      => 30,

    // WhatsApp padrão do barbeiro: DDI + DDD + número, só dígitos (ex.: 5522999999999)
    'barber_whatsapp' => getenv('BARBER_WHATSAPP') ?: '5522999999999',

    // Profissionais. Cada um pode ter o próprio WhatsApp ('whatsapp' => '5522...');
    // com null, a confirmação vai para o número padrão acima.
    'professionals'   => [
        ['id' => 1, 'name' => 'Rafael Costa',  'whatsapp' => null],
        ['id' => 2, 'name' => 'Diego Martins', 'whatsapp' => null],
    ],

    // Serviços de exemplo: duração em minutos e preço em reais. Troque pelos do cliente.
    'services'        => [
        ['id' => 1,  'name' => 'Disfarçado / Degradê',          'minutes' => 45,  'price' => 35.00],
        ['id' => 2,  'name' => 'Cabelo e barba',                'minutes' => 90,  'price' => 65.00],
        ['id' => 3,  'name' => 'Tesoura',                       'minutes' => 45,  'price' => 35.00],
        ['id' => 4,  'name' => 'Barba',                         'minutes' => 40,  'price' => 30.00],
        ['id' => 5,  'name' => 'Sobrancelha',                   'minutes' => 5,   'price' => 5.00],
        ['id' => 6,  'name' => 'Limpeza na cera quente',        'minutes' => 20,  'price' => 10.00],
        ['id' => 7,  'name' => 'Pigmentação',                   'minutes' => 30,  'price' => 20.00],
        ['id' => 8,  'name' => 'Luzes',                         'minutes' => 120, 'price' => 35.00],
        ['id' => 9,  'name' => 'Limpeza de pele',               'minutes' => 40,  'price' => 30.00],
        ['id' => 10, 'name' => 'Corte infantil',                'minutes' => 45,  'price' => 40.00],
        ['id' => 11, 'name' => 'Corte + pigmentação',           'minutes' => 60,  'price' => 50.00],
        ['id' => 12, 'name' => 'Hidratação',                    'minutes' => 30,  'price' => 30.00],
        ['id' => 13, 'name' => 'Platinado',                     'minutes' => 120, 'price' => 100.00],
        ['id' => 14, 'name' => 'Acabamento (pezinho)',          'minutes' => 10,  'price' => 10.00],
        ['id' => 15, 'name' => 'Máquina 1 pente ou raspar cabeça', 'minutes' => 30, 'price' => 25.00],
        ['id' => 16, 'name' => 'Relaxamento',                   'minutes' => 30,  'price' => 30.00],
        ['id' => 17, 'name' => 'Depilação nariz e orelha',      'minutes' => 10,  'price' => 20.00],
        ['id' => 18, 'name' => 'Camuflagem pelos brancos',      'minutes' => 20,  'price' => 30.00],
    ],

    // Endereço público do site (opcional). Se preenchido, o aviso de cancelamento
    // enviado ao cliente inclui o link para ele agendar outro horário.
    'public_url'      => getenv('PUBLIC_URL') ?: '',

    // Motivos que o dono pode escolher ao cancelar um agendamento.
    // 'label' aparece para o dono; 'text' entra na mensagem enviada ao cliente
    // ("Motivo: ..."). A chave (pessoal, saude, ...) é gravada no banco.
    'cancel_reasons'  => [
        'pessoal' => [
            'label' => 'Compromisso pessoal, hoje não consigo te atender',
            'text'  => 'surgiu um compromisso pessoal e hoje não consigo te atender',
        ],
        'saude' => [
            'label' => 'Imprevisto de saúde',
            'text'  => 'tive um imprevisto de saúde e não consigo te atender nesse horário',
        ],
        'barbearia' => [
            'label' => 'Imprevisto na barbearia',
            'text'  => 'tivemos um imprevisto na barbearia e não conseguiremos atender nesse horário',
        ],
    ],

    // Área do dono (/admin/). Guarde aqui o HASH da senha, nunca a senha em texto.
    // Gere com:  php tools/gerar-senha.php   (também vale a variável ADMIN_PASSWORD_HASH).
    // Enquanto estiver vazio, a área do dono fica bloqueada para todos.
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',

    // Envio automático via WhatsApp Cloud API (Meta). Se vazio, o sistema
    // devolve um link wa.me com a mensagem pronta como alternativa.
    'whatsapp_cloud'  => [
        'token'           => getenv('WA_TOKEN') ?: '',
        'phone_number_id' => getenv('WA_PHONE_ID') ?: '',
    ],

    'db_path'         => __DIR__ . '/data/agenda.sqlite',
];
