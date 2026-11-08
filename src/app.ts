// Agendamento em etapas. Compile com: npm run build  (gera public/app.js)

interface Professional { id: number; name: string }
interface Service { id: number; name: string; minutes: number; price: number }
interface DateOption { date: string; short: string; dm: string; long: string }
interface Info {
  shop_name: string;
  professionals: Professional[];
  services: Service[];
  dates: DateOption[];
}
interface ApiError { error?: string; fields?: Record<string, string> }
interface SlotsResponse extends ApiError { times?: string[] }
interface BookResponse extends ApiError { ok?: boolean; whatsapp_sent?: boolean; whatsapp_link?: string }

const MONTHS = [
  "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho",
  "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro",
];
const WEEKDAYS = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
const STEPS = ["Seus dados", "Barbeiro", "Serviços", "Data e horário", "Confirmação"];
const LAST = STEPS.length - 1;
const DONE = STEPS.length;

const $ = <T extends HTMLElement>(id: string): T => {
  const el = document.getElementById(id);
  if (!el) throw new Error(`Elemento #${id} não encontrado`);
  return el as T;
};

const stage = $("stage");
const progressEl = $("progress");
const flashEl = $("flash");
const navEl = $("nav");
const backBtn = $<HTMLButtonElement>("back");
const nextBtn = $<HTMLButtonElement>("next");

const state = {
  step: 0,
  name: "",
  phone: "",
  email: "",
  pro: null as Professional | null,
  selected: new Set<number>(),
  date: null as string | null,
  time: null as string | null,
  month: "",
  busy: false,
};
let info: Info;

/* ---------- Utilidades ---------- */

type Child = Node | string | null | false;

function h<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  props: Partial<Record<string, string>> = {},
  ...kids: Child[]
): HTMLElementTagNameMap[K] {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v === undefined) continue;
    if (k === "class") el.className = v;
    else el.setAttribute(k, v);
  }
  for (const c of kids) {
    if (c === null || c === false) continue;
    el.append(c);
  }
  return el;
}

const brl = (v: number): string => v.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
const cap = (s: string): string => s.charAt(0).toUpperCase() + s.slice(1);
const digits = (v: string): string => v.replace(/\D/g, "");
const pad = (n: number): string => String(n).padStart(2, "0");
const initials = (n: string): string => n.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w.charAt(0)).join("").toUpperCase();

const validName = (v: string): boolean => v.trim().length >= 3;
const validPhone = (v: string): boolean => { const n = digits(v).length; return n === 10 || n === 11; };
const validEmail = (v: string): boolean => v === "" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

function maskPhone(value: string): string {
  const d = digits(value).slice(0, 11);
  if (d.length <= 2) return d;
  if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

const chosenServices = (): Service[] => info.services.filter((s) => state.selected.has(s.id));
const totals = (): { minutes: number; price: number } => {
  const c = chosenServices();
  return { minutes: c.reduce((a, s) => a + s.minutes, 0), price: c.reduce((a, s) => a + s.price, 0) };
};
const dateOption = (): DateOption | null => info.dates.find((d) => d.date === state.date) ?? null;

function flash(msg: string): void {
  flashEl.textContent = msg;
}

/* ---------- Navegação ---------- */

function stepValid(): boolean {
  switch (state.step) {
    case 0: return validName(state.name) && validPhone(state.phone) && validEmail(state.email);
    case 1: return state.pro !== null;
    case 2: return state.selected.size > 0;
    case 3: return state.date !== null && state.time !== null;
    case 4: return true;
    default: return false;
  }
}

function updateNav(): void {
  backBtn.disabled = state.step === 0 || state.busy;
  nextBtn.disabled = !stepValid() || state.busy;
  nextBtn.textContent = state.step === LAST ? "Confirmar agendamento" : "Próximo";
}

function renderProgress(): void {
  const label = state.step >= DONE ? "Agendamento concluído" : `Etapa ${state.step + 1} de ${STEPS.length} · ${STEPS[state.step]}`;
  progressEl.replaceChildren(
    h("p", { class: "progress-label" }, label),
    h("div", { class: "bars" }, ...STEPS.map((_, i) => h("span", { class: i <= state.step ? "on" : "" }))),
  );
}

function render(): void {
  renderProgress();
  stage.replaceChildren();
  flash("");
  const builders = [stepData, stepPro, stepServices, stepDate, stepReview];
  builders[state.step]();
  updateNav();
  window.scrollTo({ top: 0 });
}

async function goNext(): Promise<void> {
  if (!stepValid() || state.busy) return;
  if (state.step === LAST) {
    await submit();
    return;
  }
  state.step += 1;
  render();
}

function goBack(): void {
  if (state.step === 0 || state.busy) return;
  state.step -= 1;
  render();
}

backBtn.addEventListener("click", goBack);
nextBtn.addEventListener("click", () => { void goNext(); });
stage.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && (e.target as HTMLElement).tagName === "INPUT") {
    e.preventDefault();
    void goNext();
  }
});

/* ---------- Etapa 1: dados ---------- */

interface FieldOpts {
  id: string;
  label: string;
  optional?: boolean;
  type?: string;
  mode?: string;
  auto?: string;
  ph: string;
  value: string;
  mask?: (v: string) => string;
  hint: (v: string) => string;
  set: (v: string) => void;
}

function field(o: FieldOpts): HTMLElement {
  const input = h("input", {
    id: o.id, name: o.id, type: o.type ?? "text", inputmode: o.mode ?? "text",
    autocomplete: o.auto ?? "off", placeholder: o.ph,
  });
  input.value = o.value;
  const hint = h("small", { class: "hint" });
  const refresh = (): void => { hint.textContent = input.value ? o.hint(input.value) : ""; };
  input.addEventListener("input", () => {
    if (o.mask) input.value = o.mask(input.value);
    o.set(input.value.trim());
    refresh();
    updateNav();
  });
  refresh();
  return h("label", { class: "field", for: o.id },
    h("span", {}, o.label, o.optional ? h("span", { class: "opt-tag" }, " (opcional)") : false),
    input, hint);
}

function stepData(): void {
  stage.append(
    h("h2", {}, "Vamos começar! Como podemos te chamar?"),
    field({
      id: "name", label: "Seu nome", ph: "Nome completo", auto: "name", value: state.name,
      hint: (v) => (validName(v) ? "" : "Informe pelo menos 3 letras."),
      set: (v) => { state.name = v; },
    }),
    field({
      id: "phone", label: "Seu WhatsApp com DDD", ph: "(22) 99999-9999", type: "tel", mode: "tel", auto: "tel",
      value: state.phone, mask: maskPhone,
      hint: (v) => (validPhone(v) ? "" : "Número incompleto. Use DDD + número."),
      set: (v) => { state.phone = v; },
    }),
    field({
      id: "email", label: "Seu e-mail", optional: true, ph: "seu@email.com", type: "email", mode: "email", auto: "email",
      value: state.email,
      hint: (v) => (validEmail(v) ? "" : "Esse e-mail não parece certo."),
      set: (v) => { state.email = v; },
    }),
    h("p", { class: "note" }, "🔒 Seus dados são usados só para marcar o horário."),
  );
}

/* ---------- Etapa 2: barbeiro ---------- */

function stepPro(): void {
  const list = h("div", { class: "picks", role: "radiogroup", "aria-label": "Barbeiro" });
  const buttons: Array<{ b: HTMLButtonElement; p: Professional }> = [];
  for (const p of info.professionals) {
    const b = h("button", {
      type: "button", class: "pick", role: "radio", "aria-checked": String(state.pro?.id === p.id),
    }, h("span", { class: "initials" }, initials(p.name)), h("span", { class: "pick-name" }, p.name));
    b.addEventListener("click", () => {
      if (state.pro?.id !== p.id) state.time = null;
      state.pro = p;
      for (const x of buttons) x.b.setAttribute("aria-checked", String(x.p.id === p.id));
      updateNav();
    });
    buttons.push({ b, p });
    list.append(b);
  }
  stage.append(h("h2", {}, "Com quem você prefere ser atendido(a)?"), list);
}

/* ---------- Etapa 3: serviços ---------- */

function stepServices(): void {
  const total = h("p", { class: "svc-total" });
  const refresh = (): void => {
    const t = totals();
    total.replaceChildren();
    if (!state.selected.size) {
      total.append("Marque pelo menos um serviço.");
    } else {
      total.append(`${state.selected.size} selecionado(s) · ${t.minutes} min · `, h("b", {}, brl(t.price)));
    }
  };

  const list = h("div", { class: "svc-list" });
  for (const s of info.services) {
    const box = h("input", { type: "checkbox", id: `svc-${s.id}` });
    box.checked = state.selected.has(s.id);
    box.addEventListener("change", () => {
      if (box.checked) state.selected.add(s.id);
      else state.selected.delete(s.id);
      state.time = null; // a duração mudou, o horário precisa ser escolhido de novo
      refresh();
      updateNav();
    });
    list.append(h("label", { class: "svc", for: `svc-${s.id}` },
      box,
      h("span", { class: "meta" }, h("b", {}, s.name), h("small", {}, `${s.minutes} min`)),
      h("span", { class: "price" }, brl(s.price)),
    ));
  }
  stage.append(
    h("h2", {}, "Quais serviços você quer fazer?"),
    h("p", { class: "muted" }, "Você pode marcar um ou mais."),
    list,
    total,
  );
  refresh();
}

/* ---------- Etapa 4: data e horário ---------- */

let slotToken = 0;

function renderCalendar(box: HTMLElement, slots: HTMLElement): void {
  const avail = new Set(info.dates.map((d) => d.date));
  const months = [...new Set(info.dates.map((d) => d.date.slice(0, 7)))];
  const idx = Math.max(0, months.indexOf(state.month));
  const [y, m] = months[idx].split("-").map(Number);
  const firstWeekday = new Date(y, m - 1, 1).getDay();
  const count = new Date(y, m, 0).getDate();

  const prev = h("button", { type: "button", "aria-label": "Mês anterior" }, "‹");
  const next = h("button", { type: "button", "aria-label": "Próximo mês" }, "›");
  prev.disabled = idx === 0;
  next.disabled = idx === months.length - 1;
  prev.addEventListener("click", () => { state.month = months[idx - 1]; renderCalendar(box, slots); });
  next.addEventListener("click", () => { state.month = months[idx + 1]; renderCalendar(box, slots); });

  const grid = h("div", { class: "cal-grid" });
  for (let i = 0; i < firstWeekday; i++) grid.append(h("span"));
  for (let d = 1; d <= count; d++) {
    const key = `${y}-${pad(m)}-${pad(d)}`;
    const b = h("button", { type: "button", class: "day" + (key === state.date ? " selected" : "") }, String(d));
    b.disabled = !avail.has(key);
    b.addEventListener("click", () => {
      state.date = key;
      state.time = null;
      renderCalendar(box, slots);
      updateNav();
      void loadTimes(slots);
    });
    grid.append(b);
  }

  box.replaceChildren(
    h("div", { class: "cal-head" }, prev, h("strong", {}, `${MONTHS[m - 1]} ${y}`), next),
    h("div", { class: "cal-grid weekdays" }, ...WEEKDAYS.map((w) => h("span", {}, w))),
    grid,
  );
}

async function loadTimes(box: HTMLElement): Promise<void> {
  if (!state.date || !state.pro) return;
  const token = ++slotToken;
  box.replaceChildren(h("p", { class: "muted" }, "Buscando horários livres..."));
  const q = new URLSearchParams({
    date: state.date,
    professional: String(state.pro.id),
    services: [...state.selected].join(","),
  });
  try {
    const res = await fetch(`api/slots.php?${q.toString()}`);
    const data = (await res.json()) as SlotsResponse;
    if (token !== slotToken) return;
    if (!res.ok) {
      box.replaceChildren(h("p", { class: "err" }, data.error ?? "Não foi possível buscar os horários."));
      return;
    }
    const times = data.times ?? [];
    if (state.time && !times.includes(state.time)) state.time = null;
    updateNav();
    if (!times.length) {
      box.replaceChildren(h("p", { class: "muted" }, "Esse dia não tem horário livre para esses serviços. Escolha outro dia."));
      return;
    }
    renderTimes(box, times);
  } catch {
    if (token === slotToken) box.replaceChildren(h("p", { class: "err" }, "Falha de conexão. Tente novamente."));
  }
}

function renderTimes(box: HTMLElement, times: string[]): void {
  const opt = dateOption();
  const t = totals();
  const grid = h("div", { class: "times" });
  const buttons: Array<{ b: HTMLButtonElement; time: string }> = [];
  for (const time of times) {
    const b = h("button", { type: "button", class: "time" + (time === state.time ? " selected" : "") }, time);
    b.addEventListener("click", () => {
      state.time = time;
      for (const x of buttons) x.b.classList.toggle("selected", x.time === time);
      updateNav();
    });
    buttons.push({ b, time });
    grid.append(b);
  }
  box.replaceChildren(
    h("h3", { class: "times-title" }, `Horários livres · ${opt ? cap(opt.long) : ""}`),
    h("p", { class: "muted" }, `Duração total do atendimento: ${t.minutes} min`),
    grid,
  );
}

function stepDate(): void {
  if (!info.dates.length) {
    stage.append(h("h2", {}, "Sem datas disponíveis"), h("p", { class: "muted" }, "Não há dias livres para agendar agora. Tente novamente mais tarde."));
    return;
  }
  const months = [...new Set(info.dates.map((d) => d.date.slice(0, 7)))];
  if (!months.includes(state.month)) state.month = state.date ? state.date.slice(0, 7) : months[0];
  if (state.date && !info.dates.some((d) => d.date === state.date)) {
    state.date = null;
    state.time = null;
  }

  const cal = h("div", { class: "cal" });
  const slots = h("div", { class: "slots", style: "display:grid;gap:10px" });
  stage.append(
    h("h2", {}, "Qual dia e horário ficam melhores?"),
    cal,
    h("p", { class: "muted" }, "Os dias sem atendimento ficam desativados."),
    slots,
  );
  renderCalendar(cal, slots);
  if (state.date) void loadTimes(slots);
}

/* ---------- Etapa 5: confirmação ---------- */

function summaryCard(): HTMLElement {
  const t = totals();
  const opt = dateOption();
  const rows: Array<[string, string, string?]> = [
    ["Cliente", state.name],
    ["WhatsApp", state.phone],
  ];
  if (state.email) rows.push(["E-mail", state.email]);
  rows.push(
    ["Barbeiro", state.pro?.name ?? ""],
    ["Serviços", chosenServices().map((s) => s.name).join(" + ")],
    ["Data", `${opt ? cap(opt.long) : ""} às ${state.time ?? ""}`],
    ["Duração", `${t.minutes} min`],
    ["Total", brl(t.price), "total"],
  );
  return h("dl", { class: "review", style: "margin:0" },
    ...rows.map(([k, v, cls]) => h("div", { class: "row" + (cls ? ` ${cls}` : "") }, h("dt", {}, k), h("dd", {}, v))));
}

function stepReview(): void {
  stage.append(
    h("h2", {}, "Revise os dados e confirme"),
    summaryCard(),
    h("p", { class: "note" }, "Ao confirmar, o horário é reservado e o WhatsApp do barbeiro abre com os dados do agendamento."),
  );
}

async function submit(): Promise<void> {
  state.busy = true;
  nextBtn.textContent = "Confirmando...";
  updateNav();
  flash("");
  try {
    const res = await fetch("api/book.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: state.name,
        phone: state.phone,
        email: state.email,
        professional_id: state.pro?.id,
        service_ids: [...state.selected],
        date: state.date,
        time: state.time,
      }),
    });
    const data = (await res.json()) as BookResponse;
    state.busy = false;

    if (res.status === 409) {
      // Outro cliente reservou antes: volta para escolher outro horário.
      state.time = null;
      state.step = 3;
      render();
      flash(data.error ?? "Esse horário acabou de ser reservado. Escolha outro.");
      return;
    }
    if (!res.ok || !data.ok) {
      updateNav();
      flash(data.error ?? "Não foi possível concluir o agendamento. Tente novamente.");
      return;
    }
    showDone(data);
  } catch {
    state.busy = false;
    updateNav();
    flash("Falha de conexão. Tente novamente.");
  }
}

function showDone(data: BookResponse): void {
  const card = summaryCard();
  const link = data.whatsapp_link ?? "";
  state.step = DONE;
  renderProgress();
  flash("");
  navEl.hidden = true;
  stage.replaceChildren(
    h("div", { class: "done" },
      h("div", { class: "check", "aria-hidden": "true" }, "✓"),
      h("h2", {}, "Agendamento confirmado"),
      h("p", { class: "muted" }, data.whatsapp_sent
        ? "O barbeiro já foi avisado pelo WhatsApp. Te esperamos!"
        : "Falta só enviar a confirmação ao barbeiro pelo WhatsApp."),
      card,
      !data.whatsapp_sent && link
        ? h("a", { class: "wa-btn", href: link, target: "_blank", rel: "noopener" }, "Abrir WhatsApp do barbeiro")
        : false,
    ),
  );
  window.scrollTo({ top: 0 });

  // Em tela cheia, abre o WhatsApp sozinho. Dentro de um quadro (pré-visualização) só mostra o botão.
  if (!data.whatsapp_sent && link && window.top === window.self) {
    window.setTimeout(() => window.location.assign(link), 900);
  }
}

/* ---------- Início ---------- */

async function init(): Promise<void> {
  const res = await fetch("api/info.php");
  info = (await res.json()) as Info;
  $("shop-name").textContent = info.shop_name;
  $("avatar").textContent = initials(info.shop_name);
  document.title = `Agendar - ${info.shop_name}`;
  render();
}

init().catch(() => {
  $("shop-name").textContent = "Não foi possível carregar o sistema.";
  navEl.hidden = true;
});
