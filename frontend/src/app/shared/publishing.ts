import { Brand, ContentItem } from '../core/models';
import { DEFAULT_PRIMARY, textOn } from './color';

/** Testo pronto da pubblicare: senza le note per il revisore che l'AI aggiunge in fondo. */
export function publishableText(body: string): string {
  return body.replace(/\n*-{3,}\s*\nNote per il revisore:[\s\S]*$/u, '').trim();
}

function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

function safeUrl(url: string | null | undefined): string | null {
  return url && /^https?:\/\//i.test(url) ? url : null;
}

/** Legge un blob (ad esempio il logo) come data URL, da incorporare nella newsletter. */
export function blobToDataUrl(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

/**
 * Newsletter HTML compatibile con i programmi di posta: tabelle, stili in linea, larghezza 600 px.
 * Grafica e logo sono incorporati come data URL, così il file è autonomo.
 */
/** Segnaposto del link di disiscrizione per i servizi email più usati. */
export const UNSUBSCRIBE_PLACEHOLDERS = {
  brevo: { label: 'Brevo', value: '{{ unsubscribe }}' },
  mailchimp: { label: 'Mailchimp', value: '*|UNSUB|*' },
  other: { label: 'Altro', value: '#' },
} as const;
export type EmailService = keyof typeof UNSUBSCRIBE_PLACEHOLDERS;

export function buildNewsletterHtml(opts: {
  brand: Brand | null;
  item: ContentItem;
  subject: string;
  imageDataUrl: string | null;
  logoDataUrl: string | null;
  service: EmailService;
}): string {
  const { brand, item, subject, imageDataUrl, logoDataUrl } = opts;
  const unsubscribe = UNSUBSCRIBE_PLACEHOLDERS[opts.service].value;
  const primary = brand?.primary_color ?? DEFAULT_PRIMARY;
  const onPrimary = textOn(primary);
  const name = escapeHtml(brand?.name ?? '');
  const site = safeUrl(brand?.website_url);
  const text = publishableText(item.body);

  // Separa l'invito all'azione (riga con 👉) e gli hashtag dal corpo del messaggio.
  const lines = text.split('\n');
  const cta = lines.find((l) => l.trim().startsWith('👉'))?.replace('👉', '').trim() ?? '';
  const paragraphs = text
    .split(/\n{2,}/)
    .filter((p) => !p.trim().startsWith('👉') && !/^(#\S+\s*)+$/.test(p.trim()))
    .map((p) => `<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#2b2d3a;">${escapeHtml(p.trim()).replace(/\n/g, '<br>')}</p>`)
    .join('\n');

  const socials = ([['Instagram', brand?.instagram_url], ['Facebook', brand?.facebook_url], ['LinkedIn', brand?.linkedin_url]] as const)
    .filter(([, url]) => safeUrl(url))
    .map(([label, url]) => `<a href="${escapeHtml(url!)}" style="color:${primary};text-decoration:none;font-weight:600;margin:0 8px;">${label}</a>`)
    .join(' ');

  return `<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(subject)}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f8;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f8;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;">
  <tr><td style="background:${primary};padding:20px 28px;" align="left">
    ${logoDataUrl ? `<img src="${logoDataUrl}" alt="${name}" height="40" style="display:block;height:40px;max-width:200px;background:#ffffff;border-radius:8px;padding:6px;">` : `<span style="font-size:20px;font-weight:bold;color:${onPrimary};">${name}</span>`}
  </td></tr>
  ${imageDataUrl ? `<tr><td><img src="${imageDataUrl}" alt="${escapeHtml(item.visual?.headline ?? subject)}" width="600" style="display:block;width:100%;height:auto;"></td></tr>` : ''}
  <tr><td style="padding:32px 28px 8px;">
    <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:#17182a;">${escapeHtml(item.title)}</h1>
    ${paragraphs}
  </td></tr>
  ${cta && site ? `<tr><td style="padding:0 28px 32px;" align="left">
    <a href="${escapeHtml(site)}" style="display:inline-block;background:${primary};color:${onPrimary};text-decoration:none;font-weight:bold;font-size:16px;padding:14px 26px;border-radius:10px;">${escapeHtml(cta)}</a>
  </td></tr>` : ''}
  <tr><td style="padding:20px 28px;border-top:1px solid #e6e6ef;font-size:13px;color:#6b6e85;" align="center">
    ${socials ? `<p style="margin:0 0 10px;">${socials}</p>` : ''}
    <p style="margin:0;">${name}${site ? ` · <a href="${escapeHtml(site)}" style="color:#6b6e85;">${escapeHtml(site.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, ''))}</a>` : ''}</p>
    <p style="margin:8px 0 0;font-size:12px;">Ricevi questa email perché sei iscritto alle comunicazioni di ${name}. <a href="${unsubscribe}" style="color:#6b6e85;">Annulla l'iscrizione</a></p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>`;
}
