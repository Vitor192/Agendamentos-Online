# Agendamentos Online

Sistema de agendamento online para barbearias. O cliente escolhe o barbeiro, os serviços, o dia e o horário em poucos passos, e ao confirmar o **WhatsApp do barbeiro abre com os dados do agendamento já escritos**. Um horário reservado fica indisponível para os demais clientes.

O projeto traz exemplos de barbeiros e serviços. Tudo se ajusta em um único arquivo, o `config.php`.

## Tecnologias

| Camada | Tecnologia | Uso |
| --- | --- | --- |
| Backend | **PHP 8.1+** | API em arquivos simples (`public/api/`), regras de agenda e validações |
| Banco de dados | **SQLite** (via PDO) | Guarda os agendamentos em um arquivo local, sem servidor de banco |
| Frontend | **TypeScript** | Interface em etapas, compilada para JavaScript (`src/app.ts` → `public/app.js`) |
| Frontend | **JavaScript, HTML e CSS** | Sem framework: o `app.js` gerado roda direto no navegador |
| Integração | **WhatsApp** (link `wa.me` ou Cloud API da Meta) | Envio da confirmação ao barbeiro |
| Segurança | **Sessões PHP, `password_hash`** | Login do dono, cookie seguro e limite de tentativas |
| Ferramentas | **Node.js + tsc** | Só para recompilar o TypeScript |

## Como funciona o agendamento

O cliente passa por cinco etapas:

1. **Seus dados:** nome, WhatsApp com DDD e e-mail (opcional).
2. **Barbeiro:** escolhe com quem quer ser atendido.
3. **Serviços:** marca um ou mais serviços. Duração e valor total são somados na hora.
4. **Data e horário:** calendário com os dias de atendimento (padrão: segunda a sexta) e os horários livres para aquele barbeiro e aqueles serviços.
5. **Confirmação:** resumo com todos os dados. Ao confirmar, o horário é reservado e o WhatsApp do barbeiro abre com a mensagem pronta.

Na tela final o cliente pode **fazer um novo agendamento** ou **ver os seus agendamentos**.

### Mensagem enviada ao barbeiro

```
✂️ Novo agendamento - Barbearia Exemplo

👤 Cliente: Vitor Souza
💈 Profissional: Rafael Costa
🧾 Serviços: Disfarçado / Degradê + Barba
📅 Data: 09/11/2026 (segunda-feira)
🕒 Horário: 13:30 (85 min)
💰 Total: R$ 65,00
📞 Telefone: (22) 98151-3033
```

O e-mail aparece na mensagem quando o cliente o informa.

### Área do dono

Tela protegida por senha, só para o dono da barbearia, em `/admin/`. Na tela principal de agendamento há o botão **Painel do profissional** (canto superior direito), que abre essa área **dentro da própria página**, em uma tela por cima do agendamento (um `iframe`), sem trocar de endereço. O botão "← Voltar ao agendamento" ou o **Sair** fecham essa tela e voltam ao agendamento; ao reabrir, a senha é pedida de novo. Quem não tem a senha não passa da tela de login. Abrindo `/admin/` direto no navegador, o **Sair** continua levando à página de agendamento.

- **Abre sempre no dia de hoje**, com os clientes na ordem dos horários: nome, serviços, barbeiro, duração, valor, WhatsApp e e-mail.
- Quando é hoje, cada cliente recebe uma etiqueta: **Concluído**, **Em atendimento** ou **Próximo**.
- Mostra o **número de clientes** e o **total do dia**.
- Setas de dia anterior e próximo dia, seletor de data e botão **Ir para hoje** para ver qualquer outro dia, passado ou futuro.
- **Filtro por barbeiro** para ver só a agenda de um deles.
- Botões **WhatsApp** e **Ligar** em cada cliente.
- **Cancelar agendamento** (veja a seção abaixo).
- O botão **Sair** encerra a sessão e leva o dono direto para a **página de agendamento dos clientes**. A tela de senha também tem o link "Ir para a página de agendamento". Para mudar o destino, altere `<meta name="home-url">` em `public/admin/index.html` (vazio = o dono fica na tela de senha).
- Atualiza sozinha a cada minuto, então novos agendamentos aparecem sem recarregar a página.

### Cancelar um agendamento e avisar o cliente

Em cada cliente que ainda não foi atendido há o botão **Cancelar agendamento**:

1. O dono escolhe o **motivo**. Já vêm 3 opções:
   - Compromisso pessoal, hoje não consigo te atender
   - Imprevisto de saúde
   - Imprevisto na barbearia
2. Ao confirmar, o agendamento é cancelado e **o horário volta a ficar livre** para novos clientes.
3. Aparece o botão **Avisar o cliente pelo WhatsApp**, que abre a conversa com o cliente com a mensagem pronta:

```
Olá, Carlos! Aqui é da Barbearia Exemplo.

Infelizmente preciso cancelar o seu agendamento:
📅 11/11/2026 (quarta-feira)
🕒 14:00
🧾 Disfarçado / Degradê
💈 Rafael Costa

Motivo: surgiu um compromisso pessoal e hoje não consigo te atender.

Peço desculpas pelo transtorno!
```

O cartão cancelado continua na lista, riscado e com o motivo, e **mantém o botão de avisar**. Assim o dono pode avisar o cliente depois, se não fizer na hora. Cancelados não entram na contagem de clientes nem no total do dia. O botão de cancelar aparece em todo agendamento ativo de hoje e dos próximos dias (inclusive de horário que já passou hoje, para o cliente que faltou). Dias anteriores ficam só no histórico.

Os motivos e os textos da mensagem ficam em `cancel_reasons` no `config.php` e podem ser trocados ou ampliados. Se `public_url` estiver preenchido, a mensagem inclui o link para o cliente marcar outro horário.

### Como proteger com senha

A área do dono fica **bloqueada para todos** até você definir uma senha:

```bash
php tools/gerar-senha.php
```

O comando pede a senha (mínimo 8 caracteres) e imprime um *hash*. Cole esse texto em `admin_password_hash` no `config.php`, entre aspas simples. A senha em si nunca é guardada. Também vale a variável de ambiente `ADMIN_PASSWORD_HASH`.

Como o acesso é protegido:

- A senha é conferida com `password_verify` contra o hash. Sem hash configurado, ninguém entra.
- **5 senhas erradas em 10 minutos bloqueiam novas tentativas** daquele IP.
- A sessão usa cookie `HttpOnly` e `SameSite=Strict` (e `Secure` em HTTPS), troca de identificador ao entrar e expira após 8 horas sem uso. O botão "Voltar" do navegador, depois de sair, mostra a tela de senha e não a agenda.
- Os dados da agenda só saem da API para quem está logado. Sem login, a API responde 401.
- A página tem `noindex`, para não aparecer em buscadores.

## Regras da agenda

- Um horário de início só aparece se o **atendimento inteiro cabe** no expediente, fora do almoço e sem encostar em outro agendamento do mesmo barbeiro. Um serviço de 90 min às 08:00 bloqueia a agenda até as 09:30.
- Cada barbeiro tem a **própria agenda**: o mesmo horário pode estar livre com um e ocupado com outro.
- **Duração e preço são calculados no servidor**, nunca confiados ao navegador.
- Datas passadas, fins de semana (ou outros dias sem atendimento) e datas além da janela configurada são recusados pelo servidor.
- Dois clientes que confirmam **ao mesmo tempo** o mesmo horário não conseguem reservá-lo: a gravação roda numa transação do SQLite (`BEGIN IMMEDIATE`) que confere a disponibilidade e grava em seguida. O segundo cliente recebe um aviso e volta para escolher outro horário.

### Meus agendamentos

O cliente informa o **nome e o WhatsApp** usados na reserva e vê os próximos horários: dia, hora, serviços, barbeiro, duração e total.

- A busca só devolve agendamentos em que o telefone **e** o primeiro nome batem (sem diferenciar maiúsculas e acentos).
- E-mail e dados de outros clientes nunca aparecem.
- O navegador lembra nome, WhatsApp e e-mail no mesmo aparelho, então a lista abre sozinha nas próximas visitas. Ao clicar em **Fazer novo agendamento**, o formulário volta vazio e esses dados lembrados são apagados, o que evita que o próximo cliente de um aparelho compartilhado veja dados alheios.

## Arquitetura

```mermaid
sequenceDiagram
    participant C as Cliente (navegador)
    participant API as API PHP
    participant DB as SQLite
    participant W as WhatsApp do barbeiro

    C->>API: GET info.php
    API-->>C: barbeiros, serviços e dias disponíveis
    C->>API: GET slots.php (data, barbeiro, serviços)
    API->>DB: agendamentos do dia
    API-->>C: horários livres
    C->>API: POST book.php
    API->>DB: confere e grava (transação)
    API-->>C: confirmado + link do WhatsApp
    C->>W: abre o WhatsApp com a mensagem pronta
```

### Estrutura de pastas

```
config.php          barbearia, horários, barbeiros, serviços e WhatsApp
lib.php             banco, regras de agenda, mensagens e envio de WhatsApp
public/             única pasta que deve ficar acessível na web
  index.html        página
  style.css         estilos
  app.js            gerado a partir de src/app.ts (já incluído)
  api/info.php      GET   barbeiros, serviços e dias disponíveis
  api/slots.php     GET   horários livres (data + barbeiro + serviços)
  api/book.php      POST  cria o agendamento
  api/my.php        GET   próximos agendamentos (telefone + nome)
  api/admin/        área do dono: session, login, logout, agenda, cancel (exigem senha)
  admin/index.html  tela da área do dono
  admin.css         estilos da área do dono
  admin.js          gerado a partir de src/admin.ts (já incluído)
src/app.ts          TypeScript da página de agendamento
src/admin.ts        TypeScript da área do dono
tools/gerar-senha.php   gera o hash da senha do dono
preview.html        prévia do agendamento, sem PHP (dados simulados)
preview-admin.html  prévia da área do dono, sem PHP (senha de demonstração: demo1234)
data/               banco SQLite, criado automaticamente (fora do Git)
```

### API

| Rota | Método | Parâmetros | Resposta |
| --- | --- | --- | --- |
| `api/info.php` | GET | nenhum | `shop_name`, `professionals`, `services`, `dates` |
| `api/slots.php` | GET | `date`, `professional`, `services` (ids separados por vírgula) | `times`: horários de início livres |
| `api/book.php` | POST (JSON) | `name`, `phone`, `email`, `professional_id`, `service_ids`, `date`, `time` | `booking`, `whatsapp_sent`, `whatsapp_link`. Erro 409 se o horário foi ocupado |
| `api/my.php` | GET | `phone`, `name` | `bookings`: próximos agendamentos |
| `api/admin/session.php` | GET | nenhum | `configured`, `logged`, `today` |
| `api/admin/login.php` | POST (JSON) | `password` | `ok`. Erro 401 se a senha estiver errada e 429 após muitas tentativas |
| `api/admin/logout.php` | POST | nenhum | `ok` |
| `api/admin/agenda.php` | GET | `date` (opcional, padrão hoje) | `bookings` (inclui cancelados), `totals`, `professionals`, `cancel_reasons`. Erro 401 sem login |
| `api/admin/cancel.php` | POST (JSON) | `id`, `reason` | `ok`, `notify_link` (WhatsApp do cliente). Erros: 401 sem login, 409 se já cancelado, 422 se for de um dia anterior |

## Como rodar

Requisitos: **PHP 8.1+** com as extensões `pdo_sqlite` e `curl`. Node.js só é necessário para editar o TypeScript.

```bash
php -S localhost:8000 -t public
```

Abra `http://localhost:8000`. O banco é criado sozinho em `data/agenda.sqlite`.

Para alterar a interface:

```bash
npm install
npm run build      # recompila src/app.ts para public/app.js
npm run watch      # recompila a cada alteração
```

Para só ver a interface, sem PHP, abra o `preview.html` (agendamento) ou o `preview-admin.html` (área do dono) no navegador. Eles usam dados simulados.

## Configuração (`config.php`)

| Opção | O que faz |
| --- | --- |
| `shop_name` | Nome da barbearia |
| `open`, `close` | Horário de funcionamento |
| `lunch` | Intervalo sem atendimento (`null` desativa) |
| `work_days` | Dias de atendimento (1 = segunda … 7 = domingo) |
| `days_ahead` | Quantos dias à frente o cliente pode agendar |
| `slot_step` | De quanto em quanto tempo um atendimento pode começar, em minutos |
| `professionals` | Barbeiros. Cada um pode ter o próprio `whatsapp`; com `null` usa o padrão |
| `services` | Serviços com nome, duração (min) e preço. A lista incluída é um exemplo |
| `cancel_reasons` | Motivos de cancelamento e o texto que vai na mensagem ao cliente |
| `public_url` | Endereço do site (opcional), incluído na mensagem de cancelamento |
| `admin_password_hash` | Hash da senha do dono. Vazio = área do dono bloqueada |
| `barber_whatsapp` | WhatsApp padrão: DDI + DDD + número, só dígitos (ex.: `5522999999999`). Aceita a variável `BARBER_WHATSAPP` |

## WhatsApp: dois modos

1. **Padrão (funciona sem configurar):** depois de confirmar, o navegador abre o WhatsApp do barbeiro com a mensagem pronta, e o cliente só toca em enviar. Há também o botão "Abrir WhatsApp do barbeiro" como reserva.
2. **Automático (WhatsApp Cloud API da Meta):** defina as variáveis `WA_TOKEN` e `WA_PHONE_ID` e o servidor envia a mensagem sozinho. A Meta só aceita texto livre se o barbeiro falou com o número comercial nas últimas 24 horas. Fora disso é preciso um *template* aprovado (ajuste `send_whatsapp()` em `lib.php`).

## Publicação

Aponte o domínio para a pasta `public/`, assim `config.php`, `lib.php` e `data/` ficam fora do acesso web. A pasta `data/` precisa de permissão de escrita pelo PHP.

## Limitações conhecidas

- O cliente não cancela nem remarca sozinho pelo site. Isso é feito pelo dono, na área do dono.
- A consulta "Meus agendamentos" não limita o número de tentativas. Vale adicionar um limite se o site ficar público.
- O dono cancela, mas não remarca: depois de cancelar, o cliente agenda outro horário pelo site.
- O aviso de cancelamento ao cliente é enviado pelo próprio WhatsApp do dono, ao tocar no botão. O sistema não envia sozinho.
- O bloqueio por tentativas é por IP. Atrás de um proxy ou CDN, todos podem aparecer com o mesmo IP.
- Há uma única senha de dono, sem contas separadas por barbeiro.
