/** Tema chiaro o scuro: segue il sistema finché l'utente non ne sceglie uno, poi lo ricorda su questo dispositivo. */
export type Theme = 'light' | 'dark';

const KEY = 'theme';

export function savedTheme(): Theme | null {
  try {
    const value = localStorage.getItem(KEY);
    return value === 'light' || value === 'dark' ? value : null;
  } catch {
    return null;
  }
}

export function currentTheme(): Theme {
  return savedTheme() ?? (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
}

export function applyTheme(theme: Theme | null = savedTheme()): void {
  if (theme) document.documentElement.dataset['theme'] = theme;
  else delete document.documentElement.dataset['theme'];
}

export function toggleTheme(): Theme {
  const next: Theme = currentTheme() === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(KEY, next); } catch { /* archiviazione non disponibile */ }
  applyTheme(next);
  return next;
}
