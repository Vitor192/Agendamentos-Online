// Área do dono. Compile com: npm run build  (gera public/admin.js)
const API = "../api/admin/";
const REFRESH_MS = 60000;
const root = document.getElementById("root");
const logoutBtn = document.getElementById("logout");
const state = {
    today: "",
    date: "",
    filter: 0, // 0 = todos os barbeiros
    agenda: null,
    openId: null, // cartão com o painel de cancelamento aberto
    notice: null, // último cancelamento, com o link para avisar o cliente
};
let seq = 0;
let timer;
/* ---------- Utilidades ---------- */
function h(tag, props = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
        if (v === undefined)
            continue;
        if (k === "class")
            el.className = v;
        else
            el.setAttribute(k, v);
    }
    for (const c of kids) {
        if (c === null || c === false)
            continue;
        el.append(c);
    }
    return el;
}
const pad = (n) => String(n).padStart(2, "0");
const brl = (v) => v.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
const digits = (v) => v.replace(/\D/g, "");
const initials = (n) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w.charAt(0)).join("").toUpperCase();
const clock = () => new Date().toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" });
function formatPhone(phone) {
    const d = phone.startsWith("55") ? phone.slice(2) : phone;
    if (d.length === 11)
        return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
    if (d.length === 10)
        return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return phone;
}
function shiftDay(ymd, n) {
    const [y, m, d] = ymd.split("-").map(Number);
    const t = new Date(y, m - 1, d + n);
    return `${t.getFullYear()}-${pad(t.getMonth() + 1)}-${pad(t.getDate())}`;
}
async function api(path, body) {
    const res = await fetch(API + path, {
        method: body === undefined ? "GET" : "POST",
        credentials: "same-origin",
        headers: body === undefined ? {} : { "Content-Type": "application/json" },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: res.status, data: (await res.json()) };
}
function stopRefresh() {
    window.clearInterval(timer);
    timer = undefined;
}
/* ---------- Telas de acesso ---------- */
function showSetup() {
    stopRefresh();
    logoutBtn.hidden = true;
    root.replaceChildren(h("div", { class: "panel" }, h("h2", {}, "Falta definir a senha do dono"), h("p", { class: "muted" }, "Por segurança, a área do dono fica bloqueada até você criar uma senha."), h("ol", { class: "steps" }, h("li", {}, "No terminal, dentro da pasta do projeto, rode:"), h("li", {}, h("pre", {}, "php tools/gerar-senha.php")), h("li", {}, "Copie o texto gerado e cole em ", h("b", {}, "admin_password_hash"), " no arquivo ", h("b", {}, "config.php"), ", entre aspas simples."), h("li", {}, "Recarregue esta página."))));
}
function showLogin(message = "") {
    stopRefresh();
    logoutBtn.hidden = true;
    const pw = h("input", { id: "pw", name: "password", type: "password", autocomplete: "current-password", required: "" });
    const error = h("p", { class: "err", role: "alert" }, message);
    const submit = h("button", { type: "submit", class: "primary" }, "Entrar");
    const form = h("form", { class: "panel", novalidate: "" }, h("h2", {}, "Entrar na área do dono"), h("label", { class: "field", for: "pw" }, h("span", {}, "Senha"), pw), error, submit);
    form.addEventListener("submit", (ev) => {
        ev.preventDefault();
        if (!pw.value) {
            error.textContent = "Digite a senha.";
            return;
        }
        submit.disabled = true;
        error.textContent = "";
        void (async () => {
            try {
                const { status, data } = await api("login.php", { password: pw.value });
                if (status === 200 && data.ok) {
                    await showAgenda();
                    return;
                }
                error.textContent = data.error ?? "Não foi possível entrar.";
            }
            catch {
                error.textContent = "Falha de conexão. Tente novamente.";
            }
            pw.value = "";
            pw.focus();
            submit.disabled = false;
        })();
    });
    root.replaceChildren(form);
    pw.focus();
}
/* ---------- Agenda do dia ---------- */
function statusOf(b, live, now) {
    if (b.status === "cancelled")
        return "cancelled";
    if (now === null)
        return "later";
    if (b.end_min <= now)
        return "done";
    if (b.start_min <= now)
        return "now";
    const upcoming = live.filter((x) => x.start_min > now).sort((a, c) => a.start_min - c.start_min)[0];
    return upcoming && upcoming.start_min === b.start_min ? "next" : "later";
}
const STATUS_LABEL = {
    done: "Concluído", now: "Em atendimento", next: "Próximo", cancelled: "Cancelado",
};
async function doCancel(b, reason, err, btn) {
    if (!reason) {
        err.textContent = "Escolha o motivo do cancelamento.";
        return;
    }
    btn.disabled = true;
    err.textContent = "";
    try {
        const { status, data } = await api("cancel.php", { id: b.id, reason });
        if (status === 401) {
            showLogin("Sua sessão terminou. Entre novamente.");
            return;
        }
        if (status === 200 && data.ok && data.notify_link) {
            state.openId = null;
            state.notice = { name: b.name, link: data.notify_link };
            await load();
            document.getElementById("notice")?.scrollIntoView({ behavior: "smooth", block: "center" });
            return;
        }
        err.textContent = data.error ?? "Não foi possível cancelar.";
    }
    catch {
        err.textContent = "Falha de conexão. Tente novamente.";
    }
    btn.disabled = false;
}
/** Painel dentro do cartão: o dono escolhe o motivo e confirma. */
function cancelPanel(b, a, box) {
    let chosen = "";
    const err = h("p", { class: "err", role: "alert" });
    const confirmBtn = h("button", { type: "button", class: "danger", disabled: "" }, "Confirmar cancelamento");
    const back = h("button", { type: "button", class: "ghost" }, "Voltar");
    const group = h("div", { class: "reasons", role: "radiogroup", "aria-label": "Motivo do cancelamento" });
    for (const r of a.cancel_reasons) {
        const id = `reason-${b.id}-${r.key}`;
        const radio = h("input", { type: "radio", name: `reason-${b.id}`, id, value: r.key });
        radio.addEventListener("change", () => {
            chosen = r.key;
            confirmBtn.disabled = false;
            err.textContent = "";
        });
        group.append(h("label", { class: "reason", for: id }, radio, h("span", {}, r.label)));
    }
    back.addEventListener("click", () => { state.openId = null; renderContent(box); });
    confirmBtn.addEventListener("click", () => { void doCancel(b, chosen, err, confirmBtn); });
    return h("div", { class: "cancel-panel" }, h("strong", {}, `Cancelar o horário de ${b.name}?`), h("p", { class: "muted" }, `${b.time} · ${b.services.join(" + ")}. O horário volta a ficar livre.`), h("p", { class: "muted" }, "Qual é o motivo? Ele vai na mensagem de aviso ao cliente."), group, err, h("div", { class: "actions" }, back, confirmBtn));
}
function bookingCard(b, a, box) {
    const live = a.bookings.filter((x) => x.status === "confirmed");
    const status = statusOf(b, live, a.now_min);
    const cancelled = status === "cancelled";
    const call = h("a", { class: "btn", href: `tel:+${digits(b.phone)}` }, "Ligar");
    let actions;
    if (cancelled) {
        actions = h("div", { class: "actions" }, b.notify_link
            ? h("a", { class: "btn wa", href: b.notify_link, target: "_blank", rel: "noopener" }, "Avisar o cliente pelo WhatsApp")
            : false, call);
    }
    else if (state.openId === b.id) {
        actions = cancelPanel(b, a, box);
    }
    else {
        const cancelBtn = h("button", { type: "button", class: "btn warn" }, "Cancelar agendamento");
        cancelBtn.addEventListener("click", () => { state.openId = b.id; renderContent(box); });
        actions = h("div", { class: "actions" }, h("a", { class: "btn", href: `https://wa.me/${digits(b.phone)}`, target: "_blank", rel: "noopener" }, "WhatsApp"), call, status === "done" ? false : cancelBtn);
    }
    return h("article", { class: "bk", "data-status": status }, h("div", { class: "when" }, h("strong", {}, b.time), h("small", {}, `até ${b.end_time}`)), h("div", { class: "what" }, status !== "later" ? h("span", { class: `pill ${status}` }, STATUS_LABEL[status]) : false, h("h3", {}, b.name), h("p", {}, b.services.join(" + ")), h("p", { class: "meta" }, `${b.professional} · ${b.minutes} min · `, h("b", {}, brl(b.total))), h("p", { class: "meta" }, formatPhone(b.phone), b.email ? ` · ${b.email}` : ""), cancelled && b.cancel_reason ? h("p", { class: "meta" }, `Motivo: ${b.cancel_reason}`) : false, actions));
}
function noticeBanner(box) {
    const n = state.notice;
    const close = h("button", { type: "button", class: "ghost" }, "Fechar");
    close.addEventListener("click", () => { state.notice = null; renderContent(box); });
    return h("div", { class: "notice", id: "notice", role: "status" }, h("strong", {}, `Agendamento de ${n.name} cancelado.`), h("p", { class: "muted" }, "O horário foi liberado. Falta avisar o cliente:"), h("div", { class: "actions" }, h("a", { class: "primary", href: n.link, target: "_blank", rel: "noopener" }, "Avisar o cliente pelo WhatsApp"), close));
}
function renderContent(box) {
    const a = state.agenda;
    if (!a)
        return;
    const isToday = a.date === a.today;
    const visible = a.bookings.filter((b) => state.filter === 0 || b.professional_id === state.filter);
    const liveVisible = visible.filter((b) => b.status === "confirmed");
    const cancelledVisible = visible.length - liveVisible.length;
    const revenue = liveVisible.reduce((s, b) => s + b.total, 0);
    const liveCount = (id) => a.bookings.filter((b) => b.status === "confirmed" && (id === 0 || b.professional_id === id)).length;
    const chips = h("div", { class: "chips", role: "group", "aria-label": "Filtrar por barbeiro" });
    const options = [
        { id: 0, label: `Todos (${liveCount(0)})` },
        ...a.professionals.map((p) => ({ id: p.id, label: `${p.name} (${liveCount(p.id)})` })),
    ];
    for (const o of options) {
        const chip = h("button", { type: "button", class: "chip", "aria-pressed": String(state.filter === o.id) }, o.label);
        chip.addEventListener("click", () => { state.filter = o.id; state.openId = null; renderContent(box); });
        chips.append(chip);
    }
    const list = visible.length
        ? h("div", { class: "list" }, ...visible.map((b) => bookingCard(b, a, box)))
        : h("div", { class: "empty" }, h("strong", {}, "Nenhum cliente agendado"), h("p", { class: "muted" }, isToday ? "Quando alguém agendar para hoje, aparece aqui." : "Não há agendamentos neste dia."));
    const refresh = h("button", { type: "button", class: "ghost" }, "Atualizar");
    refresh.addEventListener("click", () => { void load(); });
    const parts = [];
    if (state.notice)
        parts.push(noticeBanner(box));
    parts.push(h("div", { class: "tiles" }, h("div", { class: "tile" }, h("small", {}, "Clientes"), h("b", {}, String(liveVisible.length)), cancelledVisible ? h("span", { class: "sub" }, `${cancelledVisible} cancelado(s)`) : false), h("div", { class: "tile money" }, h("small", {}, "Total do dia"), h("b", {}, brl(revenue)))));
    if (a.professionals.length > 1)
        parts.push(chips);
    parts.push(list, h("div", { class: "foot" }, h("span", { class: "muted" }, `Atualizado às ${clock()}`), refresh));
    box.replaceChildren(...parts);
}
async function load() {
    const token = ++seq;
    const content = document.getElementById("content");
    const heading = document.getElementById("day-title");
    if (!content)
        return;
    try {
        const { status, data } = await api(`agenda.php?date=${encodeURIComponent(state.date)}`);
        if (token !== seq)
            return;
        if (status === 401) {
            showLogin("Sua sessão terminou. Entre novamente.");
            return;
        }
        if (status !== 200) {
            content.replaceChildren(h("p", { class: "err" }, data.error ?? "Não foi possível carregar a agenda."));
            return;
        }
        state.agenda = data;
        state.today = data.today;
        if (heading) {
            heading.textContent = data.long.charAt(0).toUpperCase() + data.long.slice(1);
            if (data.date === data.today)
                heading.append(h("span", { class: "badge" }, "Hoje"));
        }
        renderContent(content);
    }
    catch {
        if (token === seq)
            content.replaceChildren(h("p", { class: "err" }, "Falha de conexão. Tente atualizar."));
    }
}
async function showAgenda() {
    logoutBtn.hidden = false;
    state.agenda = null;
    state.filter = 0;
    state.openId = null;
    state.notice = null;
    state.date = state.today; // sempre abre no dia de hoje
    const input = h("input", { id: "day", type: "date", "aria-label": "Escolher dia" });
    input.value = state.date;
    const prev = h("button", { type: "button", class: "ghost", "aria-label": "Dia anterior" }, "‹");
    const next = h("button", { type: "button", class: "ghost", "aria-label": "Próximo dia" }, "›");
    const todayBtn = h("button", { type: "button", class: "ghost" }, "Ir para hoje");
    const go = (ymd) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd))
            return;
        state.date = ymd;
        state.filter = 0;
        state.openId = null;
        state.notice = null;
        input.value = ymd;
        todayBtn.hidden = ymd === state.today;
        void load();
    };
    prev.addEventListener("click", () => go(shiftDay(state.date, -1)));
    next.addEventListener("click", () => go(shiftDay(state.date, 1)));
    input.addEventListener("change", () => go(input.value));
    todayBtn.addEventListener("click", () => go(state.today));
    todayBtn.hidden = true;
    root.replaceChildren(h("div", { class: "datebar" }, h("div", { class: "dayrow" }, prev, input, next), h("div", { class: "dayhead" }, h("h2", { id: "day-title" }, "Carregando..."), todayBtn)), h("div", { id: "content", class: "main", style: "padding:0" }, h("p", { class: "muted" }, "Buscando a agenda...")));
    await load();
    // Atualiza sozinha para o dono ver novos agendamentos, sem apagar um cancelamento em andamento.
    stopRefresh();
    timer = window.setInterval(() => {
        if (document.visibilityState === "visible" && state.openId === null)
            void load();
    }, REFRESH_MS);
}
/* ---------- Início ---------- */
logoutBtn.addEventListener("click", () => {
    void (async () => {
        try {
            await api("logout.php", {});
        }
        finally {
            showLogin();
        }
    })();
});
async function boot() {
    const { data } = await api("session.php");
    const name = data.shop_name;
    document.getElementById("shop-name").textContent = name;
    document.getElementById("avatar").textContent = initials(name);
    document.title = `Área do dono - ${name}`;
    state.today = data.today;
    if (!data.configured)
        showSetup();
    else if (!data.logged)
        showLogin();
    else
        await showAgenda();
}
boot().catch(() => {
    root.replaceChildren(h("p", { class: "err" }, "Não foi possível carregar a área do dono."));
});
export {};
