import { Component, ElementRef, HostListener, ViewChild, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { forkJoin } from 'rxjs';
import { ApiService } from '../core/api.service';
import { toggleTheme } from './theme';

interface Command {
  label: string;
  hint: string;
  icon: string;
  group: string;
  run: () => void;
}

/** Ricerca rapida (Ctrl+K o ⌘K): pagine, clienti, campagne, potenziali clienti e azioni. */
@Component({
  selector: 'app-command-palette',
  standalone: true,
  imports: [FormsModule],
  template: `
    @if (open()) {
      <div class="palette-backdrop" (click)="close()">
        <div class="palette" role="dialog" aria-label="Ricerca rapida" (click)="$event.stopPropagation()">
          <div class="palette-input">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input #input [ngModel]="query()" (ngModelChange)="query.set($event); active.set(0)" placeholder="Cerca clienti, campagne, pagine…" aria-label="Cerca"
                   (keydown.arrowDown)="move(1, $event)" (keydown.arrowUp)="move(-1, $event)" (keydown.enter)="choose(results()[active()])" (keydown.escape)="close()" />
            <kbd>Esc</kbd>
          </div>
          <div class="palette-results" role="listbox">
            @for (c of results(); track $index) {
              @if ($index === 0 || results()[$index - 1].group !== c.group) { <p class="palette-group">{{ c.group }}</p> }
              <button class="palette-item" [class.active]="$index === active()" (mouseenter)="active.set($index)" (click)="choose(c)" role="option" [attr.aria-selected]="$index === active()">
                <i class="ti" [class]="'ti ' + c.icon" aria-hidden="true"></i>
                <span class="grow ellipsis">{{ c.label }}</span>
                <small class="muted ellipsis">{{ c.hint }}</small>
              </button>
            } @empty {
              <p class="muted small palette-empty">Nessun risultato per “{{ query() }}”.</p>
            }
          </div>
        </div>
      </div>
    }
  `,
})
export class CommandPaletteComponent {
  private api = inject(ApiService);
  private router = inject(Router);
  @ViewChild('input') input?: ElementRef<HTMLInputElement>;

  open = signal(false);
  query = signal('');
  active = signal(0);
  private items = signal<Command[]>([]);

  readonly results = computed(() => {
    const q = normalize(this.query().trim());
    const list = q ? this.items().filter((c) => normalize(`${c.label} ${c.hint}`).includes(q)) : this.items().filter((c) => c.group !== 'Clienti' && c.group !== 'Campagne' && c.group !== 'Potenziali clienti');
    return list.slice(0, 40);
  });

  @HostListener('document:keydown', ['$event'])
  onKey(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      this.open() ? this.close() : this.show();
    }
  }

  show(): void {
    this.query.set('');
    this.active.set(0);
    this.open.set(true);
    this.items.set(this.staticCommands());
    setTimeout(() => this.input?.nativeElement.focus());
    // Clienti, campagne e potenziali clienti si caricano all'apertura, così sono sempre aggiornati.
    forkJoin({ brands: this.api.brands(), projects: this.api.projects(), prospects: this.api.prospects() }).subscribe(({ brands, projects, prospects }) => {
      this.items.set([
        ...this.staticCommands(),
        ...brands.map((b) => this.go(b.name, b.industry ?? 'Cliente', 'ti-building-store', 'Clienti', ['/clients', b.id])),
        ...projects.map((p) => this.go(p.name, brands.find((b) => b.id === p.brand_profile_id)?.name ?? 'Campagna', 'ti-speakerphone', 'Campagne', ['/campaigns', p.id])),
        ...prospects.map((p) => this.go(p.name || p.url, p.url, 'ti-target-arrow', 'Potenziali clienti', ['/prospects', p.id])),
      ]);
    });
  }

  close(): void {
    this.open.set(false);
  }

  move(step: number, event: Event): void {
    event.preventDefault();
    const count = this.results().length;
    if (count) this.active.set((this.active() + step + count) % count);
  }

  choose(command: Command | undefined): void {
    if (!command) return;
    this.close();
    command.run();
  }

  private staticCommands(): Command[] {
    const page = (label: string, icon: string, path: string) => this.go(label, 'Vai alla pagina', icon, 'Pagine', [path]);
    return [
      this.go('Nuovo audit per un potenziale cliente', 'Analizza un sito', 'ti-file-search', 'Azioni', ['/prospects']),
      this.go('Aggiungi un cliente dal sito', 'Brand kit automatico', 'ti-world-search', 'Azioni', ['/clients']),
      this.go('Segna ore lavorate', 'Timer e ore per cliente', 'ti-clock-plus', 'Azioni', ['/profitability']),
      this.go('Rispondi a una recensione', 'Con l\'AI', 'ti-star', 'Azioni', ['/reviews']),
      { label: 'Cambia tema chiaro/scuro', hint: 'Aspetto', icon: 'ti-moon', group: 'Azioni', run: () => toggleTheme() },
      page('Dashboard', 'ti-layout-dashboard', '/dashboard'),
      page('Clienti', 'ti-building-store', '/clients'),
      page('Potenziali clienti', 'ti-target-arrow', '/prospects'),
      page('Campagne', 'ti-speakerphone', '/campaigns'),
      page('Calendario', 'ti-calendar', '/calendar'),
      page('Approvazioni', 'ti-checks', '/approvals'),
      page('Libreria', 'ti-photo', '/assets'),
      page('Sponsorizzate', 'ti-coin-euro', '/ads'),
      page('Link e clic', 'ti-click', '/links'),
      page('Report clienti', 'ti-report-analytics', '/reports'),
      page('Recensioni', 'ti-star', '/reviews'),
      page('Ore e redditività', 'ti-clock-dollar', '/profitability'),
      page('Fatturazione', 'ti-file-invoice', '/billing'),
      page('Team', 'ti-users', '/team'),
    ];
  }

  private go(label: string, hint: string, icon: string, group: string, path: unknown[]): Command {
    return { label, hint, icon, group, run: () => this.router.navigate(path) };
  }
}

/** Ricerca senza accenti e maiuscole: "redditivita" trova "Redditività". */
function normalize(text: string): string {
  return text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}
