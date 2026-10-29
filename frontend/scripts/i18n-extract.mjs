// Raccoglie i testi italiani dell'interfaccia (template Angular, stringhe TypeScript e messaggi delle API PHP)
// e aggiorna src/app/i18n/en.json: le frasi nuove compaiono con valore "" da tradurre, quelle sparite vengono tolte.
// Uso: node scripts/i18n-extract.mjs [--check]   (--check esce con errore se mancano traduzioni)
import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const appDir = join(root, 'src/app');
const backendDir = join(root, '../backend');
const out = join(appDir, 'i18n/en.json');

const walk = (dir, ext) => readdirSync(dir).flatMap((f) => {
  const p = join(dir, f);
  return statSync(p).isDirectory() ? walk(p, ext) : p.endsWith(ext) ? [p] : [];
});

const found = new Map();
const add = (text, file) => {
  text = normalize(text);
  if (!looksLikeUiText(text)) return;
  if (!found.has(text)) found.set(text, file);
};

export function normalize(text) {
  return text.replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#39;/g, "'").replace(/&quot;/g, '"')
    .replace(/[\s\u00a0]+/g, ' ').trim();
}

/** Una frase da tradurre: lettere vere, non un identificativo, una classe, un indirizzo o un formato di data. */
function looksLikeUiText(t) {
  const letters = t.replace(/\{\d+\}/g, '');
  if (!/\p{L}{2}/u.test(letters)) return false;
  if (t.length > 1500) return false;
  if (/^(ti|ti-|https?:|\/|#|\.|app-|mailto:|data:|[a-z]+:\/\/)/.test(t)) return false;
  if (/^[a-z0-9_.\-\/:#@?=&%*]+$/.test(t)) return false; // chiavi, classi, percorsi
  if (/^[A-Z0-9_]+$/.test(t)) return false; // costanti
  if (/^[dMyHhmsEaLz\s.,:\/'-]+$/.test(t) && /[dMy]/.test(t) && t.length < 25) return false; // formati di data
  if (/\b(SELECT|UPDATE|INSERT|DELETE FROM|WHERE|JOIN|VALUES|CREATE TABLE)\b/.test(t)) return false;
  if (/^[\w-]+(\s[\w-]+)*$/.test(t) && !/[A-ZÀ-ÿ]/.test(t) && !t.includes(' ')) return false;
  if (/[{};]\s*$/.test(t) && /[:;]/.test(t) && !/\{\d+\}/.test(t)) return false; // CSS
  if (/^\(?[\w.]+\)?$/.test(t) && !/[À-ÿ]/.test(t) && /[a-z][A-Z]/.test(t)) return false; // camelCase
  return true;
}

// ---------- Espressioni: stringhe, concatenazioni e template literal ----------

const LIT = String.raw`'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"`;
const EXPR = String.raw`[\w$.!\[\]()?]+`;

function unquote(lit) {
  const body = lit.slice(1, -1);
  return body.replace(/\\(['"\\])/g, '$1').replace(/\\n/g, '\n');
}

/** Stringhe in codice TypeScript o in espressioni di template; le concatenazioni diventano modelli con {0}, {1}… */
function extractExpressions(code, file, concat = '+') {
  const op = concat === '+' ? String.raw`\+` : String.raw`\.(?!\d)`;
  const chain = new RegExp(String.raw`(?:(?:${LIT}|${EXPR})\s*${op}\s*)+(?:${LIT}|${EXPR})`, 'g');
  const consumed = [];
  for (const m of code.matchAll(chain)) {
    const parts = m[0].split(new RegExp(String.raw`\s*${op}\s*(?=(?:${LIT}|${EXPR}))`));
    if (!parts.some((p) => /^['"]/.test(p))) continue;
    let n = 0;
    const text = parts.map((p) => (/^['"]/.test(p) ? unquote(p) : `{${n++}}`)).join('');
    add(text, file);
    consumed.push([m.index, m.index + m[0].length]);
  }
  for (const m of code.matchAll(new RegExp(LIT, 'g'))) add(unquote(m[0]), file);
  return consumed;
}

/** Template literal: `Ciao ${nome}` diventa "Ciao {0}". */
function extractTemplateLiteral(body, file) {
  let n = 0, depth = 0, text = '', expr = '';
  for (let i = 0; i < body.length; i++) {
    if (depth === 0 && body[i] === '$' && body[i + 1] === '{') { depth = 1; i++; expr = ''; continue; }
    if (depth > 0) {
      if (body[i] === '{') depth++;
      if (body[i] === '}' && --depth === 0) { text += `{${n++}}`; extractExpressions(expr, file); continue; }
      expr += body[i];
      continue;
    }
    text += body[i];
  }
  add(text.replace(/\\n/g, '\n').replace(/\\`/g, '`'), file);
}

// ---------- Template Angular ----------

/** Divide un template in tag e testo, rispettando le virgolette dentro i tag. */
function splitTemplate(tpl) {
  const parts = [];
  let i = 0, text = '';
  while (i < tpl.length) {
    if (tpl[i] === '<' && /[a-zA-Z\/!]/.test(tpl[i + 1] ?? '')) {
      if (tpl.startsWith('<!--', i)) { const end = tpl.indexOf('-->', i); i = end < 0 ? tpl.length : end + 3; parts.push({ text }); text = ''; continue; }
      let j = i + 1, quote = null;
      for (; j < tpl.length; j++) {
        const c = tpl[j];
        if (quote) { if (c === quote) quote = null; } else if (c === '"' || c === "'") quote = c; else if (c === '>') break;
      }
      parts.push({ text });
      parts.push({ tag: tpl.slice(i, j + 1) });
      text = '';
      i = j + 1;
      continue;
    }
    text += tpl[i++];
  }
  parts.push({ text });
  return parts;
}

const TEXT_ATTRS = ['placeholder', 'title', 'aria-label', 'alt', 'label'];

function extractTag(tag, file) {
  for (const m of tag.matchAll(/([\[\(]?[\w.\-@]+[\]\)]?)\s*=\s*"([^"]*)"/g)) {
    const [, name, value] = m;
    if (name.startsWith('(')) continue; // eventi: niente testo visibile
    if (name.startsWith('[')) {
      const prop = name.slice(1, -1).replace(/^attr\./, '');
      if (TEXT_ATTRS.includes(prop)) extractExpressions(value, file);
      continue;
    }
    if (TEXT_ATTRS.includes(name)) {
      extractInterpolated(value, file);
    }
  }
}

/** Testo con {{ … }}: le interpolazioni diventano {0}, {1}… e le stringhe al loro interno si estraggono a parte. */
function extractInterpolated(text, file) {
  let n = 0;
  const pattern = text.replace(/\{\{([\s\S]*?)\}\}/g, (_, expr) => { extractExpressions(expr.split(/(?<!\|)\|(?!\|)/)[0], file); return `{${n++}}`; });
  add(pattern, file);
}

/** Toglie il controllo di flusso (@if, @for, } …) e restituisce i pezzi di testo che restano tra un blocco e l'altro. */
function textSegments(text) {
  const segments = [];
  let current = '', i = 0;
  while (i < text.length) {
    if (text.startsWith('{{', i)) { const end = text.indexOf('}}', i); current += text.slice(i, end + 2); i = end + 2; continue; }
    if (text[i] === '@' && /^(if|else|for|switch|case|default|empty|defer|placeholder|loading|let)\b/.test(text.slice(i + 1))) {
      segments.push(current); current = '';
      let j = i + 1, depth = 0;
      for (; j < text.length; j++) {
        if (text[j] === '(') depth++;
        else if (text[j] === ')') depth--;
        else if ((text[j] === '{' || text[j] === ';') && depth === 0) break;
      }
      i = j + 1;
      continue;
    }
    if (text[i] === '}' || text[i] === '{') { segments.push(current); current = ''; i++; continue; }
    current += text[i++];
  }
  segments.push(current);
  return segments;
}

function extractTemplate(tpl, file) {
  for (const part of splitTemplate(tpl)) {
    if (part.tag) { extractTag(part.tag, file); continue; }
    for (const seg of textSegments(part.text)) extractInterpolated(seg, file);
  }
}

// ---------- File TypeScript ----------

for (const file of walk(appDir, '.ts')) {
  if (file.includes('i18n')) continue;
  // Template inline: estratti come HTML e tolti dal codice prima dei commenti ("image/*" non è un commento).
  let code = readFileSync(file, 'utf8').replace(/template:\s*`([\s\S]*?)`/g, (_, tpl) => {
    // Il template è un template literal: "\\'" nel sorgente è "\'" nell'HTML.
    extractTemplate(tpl.replace(/\\\\/g, '\\'), file);
    return 'template: ``';
  });
  code = code.replace(/^\s*\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
  code = code.replace(/(?<!\w)import\s[^;]+;/g, '');
  code = code.replace(/`((?:[^`\\]|\\.)*)`/g, (_, body) => { extractTemplateLiteral(body, file); return '``'; });
  extractExpressions(code, file);
}

// ---------- Messaggi delle API (PHP) ----------

const phpFiles = [...walk(join(backendDir, 'src'), '.php'), join(backendDir, 'bin/worker.php'), join(backendDir, 'public/index.php')]
  .filter((f) => !/ClaudeMarketingAi|FakeMarketingAi|Database\.php|bootstrap/.test(f));
for (const file of phpFiles) {
  const code = readFileSync(file, 'utf8')
    .replace(/<<<'?(\w+)'?[\s\S]*?\n\s*\1;/g, '') // heredoc: istruzioni per l'AI, non testo dell'interfaccia
    .replace(/^\s*(\/\/|#|\*|\/\*\*).*$/gm, '')
    .replace(/(error_log|preg_\w+|str_\w+|json_\w+|getenv|define)\s*\((?:[^()]|\([^()]*\))*\)/g, '');
  // "testo {$variabile}" → "testo {0}"
  const php = code.replace(/"((?:[^"\\]|\\.)*)"/g, (m, body) => {
    if (!/\{\$|\$\w/.test(body)) return m;
    let n = 0;
    const text = body.replace(/\{\$[^}]+\}|\$\w+(?:\[[^\]]+\]|->\w+)*/g, () => `{${n++}}`);
    return "'" + text.replace(/'/g, "\\'") + "'";
  });
  extractExpressions(php, file, '.');
}

// ---------- Unione con le traduzioni esistenti ----------

const existing = existsSync(out) ? JSON.parse(readFileSync(out, 'utf8')) : {};
const result = {};
for (const key of [...found.keys()].sort((a, b) => a.localeCompare(b, 'it'))) result[key] = existing[key] ?? '';
writeFileSync(out, JSON.stringify(result, null, 1) + '\n');
const missing = Object.values(result).filter((v) => v === '').length;
console.log(`${Object.keys(result).length} frasi, ${missing} da tradurre → ${out}`);
if (process.argv.includes('--check') && missing) process.exit(1);
