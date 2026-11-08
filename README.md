# Agendamento online para barbearia

PHP (backend + SQLite) · TypeScript compilado para JavaScript (frontend).

## Fluxo do cliente

1. **Seus dados:** nome, WhatsApp com DDD e e-mail (opcional).
2. **Barbeiro:** escolhe com quem quer ser atendido.
3. **Serviços:** marca um ou mais serviços (duração e preço somados na hora).
4. **Data e horário:** calendário de dias de atendimento (padrão seg-sex) e horários livres.
5. **Confirmação:** resumo com todos os dados. Ao confirmar, o horário é reservado e o **WhatsApp do barbeiro abre com a mensagem pronta**.

Na tela final há os botões **Fazer novo agendamento** e **Ver meus agendamentos**. A consulta também está na primeira tela.

## Meus agendamentos

O cliente informa o **nome e o WhatsApp** usados na reserva e vê os próximos horários dele. A busca só devolve agendamentos em que o telefone e o primeiro nome (sem diferenciar maiúsculas e acentos) batem. E-mail e dados de outros clientes nunca aparecem. O navegador também lembra nome, WhatsApp e e-mail, então no mesmo aparelho a lista já abre sozinha.

## Regras da agenda

- O horário de início só aparece se o atendimento inteiro cabe no expediente, fora do almoço e sem encostar em outro agendamento do mesmo barbeiro.
- Cada barbeiro tem a própria agenda: o mesmo horário pode estar livre com um e ocupado com outro.
- Duração e preço são calculados no servidor, nunca confiados ao navegador.
- A reserva roda dentro de uma transação (`BEGIN IMMEDIATE`), então dois clientes que confirmam ao mesmo tempo não conseguem pegar o mesmo horário. O segundo recebe um aviso e volta para escolher outro.

## Estrutura

```
config.php          barbearia, horários, barbeiros, serviços, WhatsApp
lib.php             banco, regras de agenda, mensagem e envio WhatsApp
public/             única pasta que deve ficar acessível na web
  index.html, style.css
  app.js            gerado a partir de src/app.ts (já incluído)
  api/info.php      GET  barbeiros, serviços e dias disponíveis
  api/slots.php     GET  horários livres (data + barbeiro + serviços)
  api/book.php      POST cria o agendamento
  api/my.php        GET  próximos agendamentos (telefone + nome)
src/app.ts          código TypeScript do frontend
preview.html        pré-visualização sem PHP (dados simulados no navegador)
data/               banco SQLite, criado automaticamente
```

## Rodar localmente

Requisitos: PHP 8.1+ com `pdo_sqlite` e `curl`. Node.js só para editar o TypeScript.

```bash
php -S localhost:8000 -t public     # abra http://localhost:8000
```

Para alterar o frontend: `npm install` e depois `npm run build` (recompila `src/app.ts` para `public/app.js`).

Para só ver a interface, abra `preview.html` no navegador.

## Configurar para cada barbearia (`config.php`)

- `shop_name`, `open`, `close`, `lunch`, `work_days`, `days_ahead`, `slot_step` (de quanto em quanto tempo um atendimento pode começar).
- `professionals`: lista de barbeiros. Cada um pode ter o próprio `whatsapp`; com `null`, usa `barber_whatsapp`.
- `services`: nome, duração em minutos e preço. A lista que vem no projeto é um exemplo.
- `barber_whatsapp`: DDI + DDD + número, só dígitos (ex.: `5522999999999`). Também aceita a variável de ambiente `BARBER_WHATSAPP`.

## WhatsApp: dois modos

1. **Padrão (funciona sem configurar):** depois de confirmar, o navegador abre o WhatsApp do barbeiro com a mensagem pronta (com o botão "Abrir WhatsApp do barbeiro" como reserva). O cliente só precisa tocar em enviar.
2. **Automático (WhatsApp Cloud API da Meta):** defina `WA_TOKEN` e `WA_PHONE_ID` e o servidor envia sozinho. A Meta só aceita texto livre se o barbeiro falou com o número comercial nas últimas 24h; fora disso é preciso um *template* aprovado (ajuste `send_whatsapp()` em `lib.php`).

## Publicação

Aponte o domínio para a pasta `public/`, assim `config.php`, `lib.php` e `data/` ficam fora do acesso web. A pasta `data/` precisa de permissão de escrita pelo PHP.
