# Sistema de agendamento para barbearia

PHP (backend + SQLite) · TypeScript compilado para JavaScript (frontend).

## Como funciona

- Calendário com **segunda a sexta** (fins de semana e dias passados ficam bloqueados).
- Ao escolher o dia, o cliente vê os horários do estabelecimento (padrão 08:00–18:00, 1h de atendimento, almoço 12:00–13:00).
- Horário já reservado aparece **riscado e indisponível** para os demais clientes.
- O servidor impede reserva duplicada (índice único `data + hora` no banco), mesmo se dois clientes confirmarem ao mesmo tempo.
- O cliente informa **nome, telefone e e-mail**; ao confirmar, a mensagem com data, hora e dados do cliente vai para o **WhatsApp do barbeiro**.

## Estrutura

```
config.php          configurações (horários, WhatsApp, nome da barbearia)
lib.php             banco, regras de horários, mensagem e envio WhatsApp
public/             única pasta que deve ficar acessível na web
  index.html, style.css
  app.js            gerado a partir de src/app.ts (já incluído)
  api/slots.php     GET  horários livres/ocupados de uma data
  api/book.php      POST cria o agendamento
  api/info.php      GET  dados públicos para o calendário
src/app.ts          código TypeScript do frontend
data/               banco SQLite (criado automaticamente, fora de public/)
```

## Rodar localmente

Requisitos: PHP 8.1+ com extensões `pdo_sqlite` e `curl`; Node.js só se for editar o TypeScript.

```bash
php -S localhost:8000 -t public     # abra http://localhost:8000
```

Para alterar o frontend:

```bash
npm install
npm run build      # recompila src/app.ts -> public/app.js
```

## Configurar para cada barbeiro

Edite `config.php`: `shop_name`, `open`, `close`, `slot_minutes`, `lunch`, `days_ahead` e `barber_whatsapp` (DDI + DDD + número, só dígitos, ex.: `5522999999999`). Também dá para usar a variável de ambiente `BARBER_WHATSAPP`.

## WhatsApp: dois modos

1. **Automático (WhatsApp Cloud API da Meta):** defina `WA_TOKEN` e `WA_PHONE_ID`. O servidor envia a mensagem sozinho. Atenção: a API da Meta só aceita texto livre se o barbeiro tiver falado com o número comercial nas últimas 24h; fora disso é preciso um *template* aprovado na Meta (ajuste `send_whatsapp()` em `lib.php`).
2. **Sem configuração (padrão):** após confirmar, aparece o botão "Enviar confirmação ao barbeiro no WhatsApp", que abre o WhatsApp com a mensagem pronta. Funciona de imediato, mas depende do cliente tocar no botão.

## Publicação

Aponte o domínio/virtual host para a pasta `public/` (assim `config.php`, `lib.php` e `data/` ficam inacessíveis pela web). A pasta `data/` precisa de permissão de escrita pelo PHP.
