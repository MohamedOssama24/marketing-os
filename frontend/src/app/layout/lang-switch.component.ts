import { Component } from '@angular/core';
import { LANG, setLang } from '../i18n/i18n';

/** Pulsante per passare dall'italiano all'inglese e viceversa (scritto nella lingua di arrivo, non si traduce). */
@Component({
  selector: 'app-lang-switch',
  standalone: true,
  template: `
    <button class="icon-btn lang-switch" translate="no" (click)="switchLang()" [title]="next.title" [attr.aria-label]="next.title">
      {{ next.code }}
    </button>
  `,
})
export class LangSwitchComponent {
  readonly next = LANG === 'it' ? { lang: 'en' as const, code: 'EN', title: 'Switch to English' } : { lang: 'it' as const, code: 'IT', title: 'Passa all\'italiano' };

  switchLang(): void {
    setLang(this.next.lang);
  }
}
