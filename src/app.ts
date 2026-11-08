// Frontend do agendamento. Compile com: npm run build  (gera public/app.js)

interface ShopInfo {
  shop_name: string;
  days_ahead: number;
  today: string; // YYYY-MM-DD, data do servidor
}

interface Slot {
  time: string;
  available: boolean;
}

interface SlotsResponse {
  date: string;
  slots: Slot[];
  error?: string;
}

interface Appointment {
  date: string;
  time: string;
  name: string;
  phone: string;
  email: string;
}

interface BookResponse {
  ok?: boolean;
  error?: string;
  fields?: Record<string, string>;
  appointment?: Appointment;
  whatsapp_sent?: boolean;
  whatsapp_link?: string;
}

const MONTHS = [
  "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho",
  "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro",
];

const $ = <T extends HTMLElement>(id: string): T => {
  const el = document.getElementById(id);
  if (!el) throw new Error(`Elemento #${id} não encontrado`);
  return el as T;
};

const state = {
  info: null as ShopInfo | null,
  viewYear: 0,
  viewMonth: 0, // 0-11
  date: null as string | null,
  time: null as string | null,
};

const pad = (n: number): string => String(n).padStart(2, "0");
const toYmd = (y: number, m: number, d: number): string => `${y}-${pad(m + 1)}-${pad(d)}`;
const parseYmd = (s: string): Date => {
  const [y, m, d] = s.split("-").map(Number);
  return new Date(y, m - 1, d);
};
const formatBr = (s: string): string => {
  const [y, m, d] = s.split("-");
  return `${d}/${m}/${y}`;
};

function show(id: string, visible: boolean): void {
  $(id).hidden = !visible;
}

/* ---------- Calendário ---------- */

function renderCalendar(): void {
  const info = state.info;
  if (!info) return;

  const { viewYear: y, viewMonth: m } = state;
  $("month-label").textContent = `${MONTHS[m]} ${y}`;

  const today = parseYmd(info.today);
  const limit = new Date(today);
  limit.setDate(limit.getDate() + info.days_ahead);

  const first = new Date(y, m, 1);
  const daysInMonth = new Date(y, m + 1, 0).getDate();
  const grid = $("days");
  grid.innerHTML = "";

  for (let i = 0; i < first.getDay(); i++) {
    grid.appendChild(document.createElement("span"));
  }

  for (let d = 1; d <= daysInMonth; d++) {
    const date = new Date(y, m, d);
    const dow = date.getDay();
    const ymd = toYmd(y, m, d);
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "day";
    btn.textContent = String(d);
    btn.disabled = dow === 0 || dow === 6 || date < today || date > limit;
    if (ymd === state.date) btn.classList.add("selected");
    btn.addEventListener("click", () => selectDate(ymd));
    grid.appendChild(btn);
  }

  const prevDisabled = y === today.getFullYear() && m === today.getMonth();
  const nextDisabled = y === limit.getFullYear() && m === limit.getMonth();
  $<HTMLButtonElement>("prev").disabled = prevDisabled;
  $<HTMLButtonElement>("next").disabled = nextDisabled;
}

function changeMonth(delta: number): void {
  const d = new Date(state.viewYear, state.viewMonth + delta, 1);
  state.viewYear = d.getFullYear();
  state.viewMonth = d.getMonth();
  renderCalendar();
}

/* ---------- Horários ---------- */

async function selectDate(ymd: string): Promise<void> {
  state.date = ymd;
  state.time = null;
  show("step-form", false);
  renderCalendar();

  $("slots-title").textContent = `Horários em ${formatBr(ymd)}`;
  const box = $("slots");
  box.textContent = "Carregando...";
  show("step-slots", true);

  try {
    const res = await fetch(`api/slots.php?date=${encodeURIComponent(ymd)}`);
    const data: SlotsResponse = await res.json();
    if (!res.ok) throw new Error(data.error ?? "Erro ao carregar horários.");
    renderSlots(data.slots);
  } catch (e) {
    box.textContent = e instanceof Error ? e.message : "Erro ao carregar horários.";
  }
}

function renderSlots(slots: Slot[]): void {
  const box = $("slots");
  box.innerHTML = "";
  if (slots.every((s) => !s.available)) {
    box.textContent = "Não há horários livres neste dia. Escolha outra data.";
    return;
  }
  for (const s of slots) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "slot";
    btn.textContent = s.time;
    btn.disabled = !s.available;
    btn.title = s.available ? "" : "Indisponível";
    btn.addEventListener("click", () => selectTime(s.time, btn));
    box.appendChild(btn);
  }
}

function selectTime(time: string, btn: HTMLButtonElement): void {
  state.time = time;
  document.querySelectorAll(".slot.selected").forEach((el) => el.classList.remove("selected"));
  btn.classList.add("selected");
  $("summary").textContent = `${formatBr(state.date ?? "")} às ${time}`;
  clearErrors();
  show("step-form", true);
  $("step-form").scrollIntoView({ behavior: "smooth", block: "nearest" });
}

/* ---------- Formulário ---------- */

function clearErrors(): void {
  document.querySelectorAll<HTMLElement>(".err").forEach((el) => (el.textContent = ""));
}

function maskPhone(value: string): string {
  const d = value.replace(/\D/g, "").slice(0, 11);
  if (d.length <= 2) return d;
  if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

async function submitForm(ev: Event): Promise<void> {
  ev.preventDefault();
  if (!state.date || !state.time) return;

  const form = ev.target as HTMLFormElement;
  const data = new FormData(form);
  const phoneDigits = String(data.get("phone") ?? "").replace(/\D/g, "");
  const payload = {
    date: state.date,
    time: state.time,
    name: String(data.get("name") ?? ""),
    // O backend espera DDD + número; acrescenta o DDI do Brasil se faltar.
    phone: phoneDigits.length <= 11 ? `55${phoneDigits}` : phoneDigits,
    email: String(data.get("email") ?? ""),
  };

  clearErrors();
  const submit = $<HTMLButtonElement>("submit");
  submit.disabled = true;

  try {
    const res = await fetch("api/book.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const body: BookResponse = await res.json();

    if (res.status === 409) {
      // Outro cliente reservou esse horário primeiro: recarrega a lista.
      $("form-error").textContent = body.error ?? "Horário indisponível.";
      show("step-form", false);
      await selectDate(state.date);
      return;
    }
    if (!res.ok || !body.ok || !body.appointment) {
      for (const [field, msg] of Object.entries(body.fields ?? {})) {
        const el = document.querySelector<HTMLElement>(`.err[data-for="${field}"]`);
        if (el) el.textContent = msg;
      }
      $("form-error").textContent = body.error ?? "Não foi possível agendar.";
      return;
    }
    showDone(body);
  } catch {
    $("form-error").textContent = "Falha de conexão. Tente novamente.";
  } finally {
    submit.disabled = false;
  }
}

function showDone(body: BookResponse): void {
  const a = body.appointment as Appointment;
  $("done-text").textContent =
    `${a.name}, seu horário está reservado para ${formatBr(a.date)} às ${a.time}.`;

  const link = $<HTMLAnchorElement>("wa-link");
  // Se o servidor não conseguiu enviar pela API, oferece o link com a mensagem pronta.
  if (!body.whatsapp_sent && body.whatsapp_link) {
    link.href = body.whatsapp_link;
    link.hidden = false;
  } else {
    link.hidden = true;
  }

  show("step-calendar", false);
  show("step-slots", false);
  show("step-form", false);
  show("step-done", true);
}

function reset(): void {
  state.date = null;
  state.time = null;
  $<HTMLFormElement>("form").reset();
  show("step-done", false);
  show("step-calendar", true);
  renderCalendar();
}

/* ---------- Inicialização ---------- */

async function init(): Promise<void> {
  const res = await fetch("api/info.php");
  const info: ShopInfo = await res.json();
  state.info = info;
  $("shop-name").textContent = info.shop_name;
  document.title = `Agendar - ${info.shop_name}`;

  const today = parseYmd(info.today);
  state.viewYear = today.getFullYear();
  state.viewMonth = today.getMonth();

  $("prev").addEventListener("click", () => changeMonth(-1));
  $("next").addEventListener("click", () => changeMonth(1));
  $("form").addEventListener("submit", submitForm);
  $("again").addEventListener("click", reset);
  $<HTMLFormElement>("form").querySelector<HTMLInputElement>('input[name="phone"]')
    ?.addEventListener("input", (e) => {
      const input = e.target as HTMLInputElement;
      input.value = maskPhone(input.value);
    });

  renderCalendar();
}

init().catch(() => {
  $("shop-name").textContent = "Não foi possível carregar o sistema.";
});
