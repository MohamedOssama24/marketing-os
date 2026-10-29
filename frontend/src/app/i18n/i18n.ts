/**
 * Lingua dell'interfaccia. L'app è scritta in italiano; in inglese i testi vengono tradotti mentre compaiono
 * nella pagina, usando en.json (frase italiana → traduzione, con {0}, {1}… al posto delle parti che cambiano).
 * Così i componenti restano scritti in italiano e i dati dei clienti (nomi, post, testi dell'AI) non vengono toccati.
 * Per aggiornare le frasi: node scripts/i18n-extract.mjs, poi tradurre quelle con valore vuoto in en.json.
 */
export type Lang = 'it' | 'en';

const KEY = 'mos_lang';
const ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
/** Contenuti dei clienti e testi lunghi non si traducono con i modelli: si rischierebbe di cambiare un post. */
const MAX_PATTERN_TEXT = 300;
const MAX_PATTERN_PART = 80;

function readLang(): Lang {
  // Un link con ?lang=en (per esempio il portale mandato a un cliente straniero) sceglie la lingua.
  const fromUrl = new URLSearchParams(location.search).get('lang');
  if (fromUrl === 'it' || fromUrl === 'en') {
    try { localStorage.setItem(KEY, fromUrl); } catch {}
    return fromUrl;
  }
  try {
    const saved = localStorage.getItem(KEY);
    if (saved === 'it' || saved === 'en') return saved;
  } catch {}
  return 'it';
}

export const LANG: Lang = readLang();
/** Locale per date, numeri e valute (pipe di Angular e toLocaleString). */
export const LOCALE = LANG === 'en' ? 'en-GB' : 'it-IT';

/** Cambia lingua: la pagina si ricarica per applicarla anche a date, numeri e valute. */
export function setLang(lang: Lang): void {
  try { localStorage.setItem(KEY, lang); } catch {}
  const url = new URL(location.href);
  url.searchParams.delete('lang');
  location.replace(url.toString());
}

type Pattern = { re: RegExp; out: string; order: number[]; anchor: string; fixed: number };

const exact = new Map<string, string>();
const patterns: Pattern[] = [];
const cache = new Map<string, string | null>();

const normalize = (s: string) => s.replace(/[\s ]+/g, ' ').trim();
const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function load(dict: Record<string, string>): void {
  for (const [key, value] of Object.entries(dict)) {
    if (!value || value === key) continue;
    if (!/\{\d+\}/.test(key)) { exact.set(key, value); continue; }
    const parts = key.split(/(\{\d+\})/);
    const literals = parts.filter((p) => !/^\{\d+\}$/.test(p));
    const anchor = literals.reduce((a, b) => (b.trim().length > a.length ? b.trim() : a), '');
    // Un modello senza parole proprie ("{0} {1}") corrisponderebbe a qualsiasi testo.
    if (!/\p{L}{2}/u.test(anchor)) continue;
    const order: number[] = [];
    const source = parts.map((p) => {
      const m = /^\{(\d+)\}$/.exec(p);
      if (!m) return escape(p);
      order.push(Number(m[1]));
      return '(.*?)';
    }).join('');
    patterns.push({ re: new RegExp(`^${source}$`, 's'), out: value, order, anchor, fixed: literals.join('').trim().length });
  }
  // I modelli più specifici (più testo fisso) vincono.
  patterns.sort((a, b) => b.anchor.length - a.anchor.length);
}

/** Traduzione di un testo, o null se non c'è (dati dei clienti, nomi, numeri…). */
export function translate(text: string): string | null {
  if (LANG === 'it' || !text) return null;
  const core = normalize(text);
  if (!/\p{L}/u.test(core)) return null;
  let result = cache.get(core);
  if (result === undefined) {
    result = exact.get(core) ?? matchPattern(core) ?? joined(core);
    cache.set(core, result);
  }
  if (result === null) return null;
  // Gli spazi intorno al testo restano: separano le parole dagli elementi vicini.
  const lead = /^[\s ]*/.exec(text)![0];
  const trail = /[\s ]*$/.exec(text)![0];
  return lead + result + trail;
}

function matchPattern(core: string): string | null {
  if (core.length > MAX_PATTERN_TEXT) return null;
  for (const p of patterns) {
    if (!core.includes(p.anchor)) continue;
    // Un modello con poche parole fisse ("{0} su {1}") corrisponderebbe anche alle frasi dei clienti: deve coprire buona parte del testo.
    if (core.length > 30 && p.fixed < core.length * 0.2) continue;
    const m = p.re.exec(core);
    if (!m) continue;
    const values = m.slice(1);
    if (values.some((v) => v.length > MAX_PATTERN_PART)) continue;
    return p.out.replace(/\{(\d+)\}/g, (_, i) => {
      const v = values[p.order.indexOf(Number(i))] ?? '';
      // Le parti variabili possono essere a loro volta frasi note (uno stato, un'etichetta).
      return exact.get(v.trim()) ?? v;
    });
  }
  return null;
}

/** Testi composti da frasi note unite da " — " o " · " (per esempio "Halloween — Contenuto giocoso…"). */
function joined(core: string): string | null {
  const parts = core.split(/( — | · )/);
  if (parts.length < 3) return null;
  let found = false;
  const out = parts.map((p) => {
    const tr = exact.get(p) ?? null;
    if (tr) found = true;
    return tr ?? p;
  });
  return found ? out.join('') : null;
}

/** Per i testi costruiti nel codice: traduce se c'è una traduzione, altrimenti restituisce il testo com'è. */
export function t(text: string): string {
  return translate(text) ?? text;
}

const written = new WeakMap<Node, string>();

function translateText(node: Text): void {
  const value = node.nodeValue;
  if (!value || written.get(node) === value || skip(node.parentElement)) return;
  const tr = translate(value);
  if (tr !== null && tr !== value) {
    written.set(node, tr);
    node.nodeValue = tr;
  }
}

function translateAttr(el: Element, name: string): void {
  const value = el.getAttribute(name);
  if (!value) return;
  const tr = translate(value);
  if (tr !== null && tr !== value) el.setAttribute(name, tr);
}

function skip(el: Element | null): boolean {
  return !el || el.closest('script, style, textarea, [contenteditable="true"], [translate="no"]') !== null;
}

function walk(root: Node): void {
  if (root.nodeType === Node.TEXT_NODE) return translateText(root as Text);
  if (root.nodeType !== Node.ELEMENT_NODE || skip(root as Element)) return;
  const el = root as Element;
  for (const a of ATTRS) translateAttr(el, a);
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    if (n.nodeType === Node.TEXT_NODE) translateText(n as Text);
    else for (const a of ATTRS) translateAttr(n as Element, a);
  }
}

/** Avvia la traduzione della pagina (prima che Angular disegni, così non si vede mai l'italiano). */
export function startTranslation(dict: Record<string, string>): void {
  load(dict);
  document.documentElement.lang = 'en';
  walk(document.body);
  new MutationObserver((mutations) => {
    for (const m of mutations) {
      if (m.type === 'characterData') translateText(m.target as Text);
      else if (m.type === 'attributes') translateAttr(m.target as Element, m.attributeName!);
      else m.addedNodes.forEach(walk);
    }
  }).observe(document.documentElement, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRS });

  // Le finestre di conferma del browser usano testi scritti nel codice.
  const { confirm, alert, prompt } = window;
  window.confirm = (message?: string) => confirm.call(window, message === undefined ? message : t(String(message)));
  window.alert = (message?: unknown) => alert.call(window, message === undefined ? message : t(String(message)));
  window.prompt = (message?: string, value?: string) => prompt.call(window, message === undefined ? message : t(message), value);
  const title = document.querySelector('title');
  if (title) new MutationObserver(() => {
    const tr = translate(document.title);
    if (tr && tr !== document.title) document.title = tr;
  }).observe(title, { childList: true, characterData: true, subtree: true });
}
