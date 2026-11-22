import { Component, EventEmitter, Input, OnDestroy, OnInit, Output, computed, inject, signal } from '@angular/core';
import { LOCALE } from '../../i18n/i18n';
import { CurrencyPipe, DatePipe, DecimalPipe, NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Observable, Subject, Subscription, debounceTime, map, switchMap, timer, takeWhile } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import {
  AdCheck, AdDraft, AdIssue, AdMusic, AdMusicSource, AdObjective, AdPlan, AdPlanInput, AdVerdict, Asset, Brand, ContentItem, MetaConnection, MetaRulebook, RuleSeverity, apiErrorMessage,
} from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';
import { PostVisualComponent } from '../../shared/post-visual.component';
import { publishableText } from '../../shared/publishing';

const STEPS = ['Obiettivo', 'Pubblico', 'Budget', 'Testi', 'Musica', 'Pronta per Meta'];
/** Indici dei passaggi usati nella logica. */
const TEXTS = 3, MUSIC = 4, READY = 5;
const NO_MUSIC_CHOICE: AdMusic = { source: 'meta', mood: null, meta_picks: true, asset_id: null, track_name: null, rights_confirmed: false };
/** Obiettivi che si pubblicano da qui; gli altri chiedono modulo contatti, Pixel o app configurati in Gestione inserzioni. */
const DIRECT_OBJECTIVES = ['awareness', 'traffic', 'engagement'];
/** Stato della sponsorizzata su Meta (effective_status), in parole. */
const META_STATUS: Record<string, { label: string; badge: string }> = {
  publishing: { label: 'In pubblicazione…', badge: 'in_review' },
  paused: { label: 'Su Meta, in pausa', badge: 'draft' },
  campaign_paused: { label: 'In pausa', badge: 'draft' },
  adset_paused: { label: 'In pausa', badge: 'draft' },
  in_process: { label: 'In attivazione', badge: 'in_review' },
  pending_review: { label: 'In revisione da Meta', badge: 'in_review' },
  active: { label: 'Attiva', badge: 'approved' },
  with_issues: { label: 'Attiva con problemi', badge: 'in_review' },
  disapproved: { label: 'Rifiutata da Meta', badge: 'rejected' },
  pending_billing_info: { label: 'Manca il metodo di pagamento', badge: 'rejected' },
  completed: { label: 'Terminata', badge: 'scheduled' },
  archived: { label: 'Archiviata', badge: 'scheduled' },
  deleted: { label: 'Eliminata su Meta', badge: 'rejected' },
  error: { label: 'Pubblicazione non riuscita', badge: 'rejected' },
};
const SEVERITY: Record<RuleSeverity, { icon: string; label: string }> = {
  block: { icon: 'ti-circle-x', label: 'Da correggere' },
  warn: { icon: 'ti-alert-triangle', label: 'Attenzione' },
  tip: { icon: 'ti-bulb', label: 'Consiglio' },
};
const VERDICT = {
  ok: { icon: 'ti-circle-check', text: 'Rispetta le regole di Meta controllate' },
  warning: { icon: 'ti-alert-triangle', text: 'Si può pubblicare, ma controlla gli avvisi' },
  blocked: { icon: 'ti-circle-x', text: 'Così Meta la respingerebbe: correggi i punti in rosso' },
};

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Procedura guidata per preparare una sponsorizzata su Meta: obiettivo, pubblico, budget e testi,
 * controllati con le regole di Meta mentre si scrive. Alla fine una scheda da copiare in Gestione inserzioni.
 */
@Component({
  selector: 'app-ad-wizard',
  standalone: true,
  imports: [AssetPreviewComponent, CurrencyPipe, DatePipe, DecimalPipe, FormsModule, NgTemplateOutlet, PostVisualComponent],
  template: `
    <div class="modal-backdrop" (click)="closed.emit()">
      <div class="modal ad-wizard" (click)="$event.stopPropagation()" role="dialog" aria-label="Sponsorizzata su Meta">
        <div class="modal-head">
          <h2><i class="ti ti-brand-meta" aria-hidden="true"></i> {{ saved() ? saved()!.name : 'Nuova sponsorizzata' }}</h2>
          <span class="muted"></span>
          <button type="button" class="icon-btn" (click)="closed.emit()" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button>
        </div>

        <ol class="wizard-steps">
          @for (s of steps; track s; let i = $index) {
            <li [class.on]="step() === i" [class.done]="step() > i">
              <button type="button" (click)="goTo(i)" [disabled]="!canGoTo(i)"><span class="step-num">{{ i + 1 }}</span>{{ s }}</button>
            </li>
          }
        </ol>

        <div class="modal-body wizard-body">
          @if (error()) { <div class="alert">{{ error() }}</div> }
          @if (!rules()) { <p class="muted">Caricamento delle regole di Meta…</p> }
          @else {
            @switch (step()) {
              @case (0) {
                <div class="wizard-grid">
                  <div class="wizard-main">
                    <label>Cliente
                      <select [(ngModel)]="ad.brand_id" (ngModelChange)="changed()" [disabled]="!!saved()">
                        @for (b of brands; track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
                      </select>
                    </label>
                    @if (content()) {
                      <p class="hint small"><i class="ti ti-photo" aria-hidden="true"></i> Sponsorizzi il post «{{ content()!.title }}»: testo e grafica sono già pronti.</p>
                    }
                    <span class="field-label">Cosa vuoi ottenere?</span>
                    <div class="objective-grid">
                      @for (o of rules()!.objectives; track o.key) {
                        <button type="button" class="payer-option" [class.on]="ad.objective === o.key" (click)="setObjective(o.key)">
                          <strong><i class="ti" [class]="'ti ' + o.icon" aria-hidden="true"></i> {{ o.label }}</strong>
                          <span>{{ o.when }}</span>
                        </button>
                      }
                    </div>
                    <label>Di cosa parla l'inserzione?
                      <select [(ngModel)]="ad.special_category" (ngModelChange)="changed()">
                        @for (c of rules()!.special_categories; track c.key) { <option [ngValue]="c.key">{{ c.label }}</option> }
                      </select>
                    </label>
                    <p class="muted small">{{ categoryDetail() }}</p>
                  </div>
                </div>
              }
              @case (1) {
                <div class="wizard-main narrow-col">
                  <label class="toggle-row">
                    <input type="checkbox" [(ngModel)]="ad.audience.advantage" (ngModelChange)="changed()" />
                    <span><strong>Lascia che Meta trovi le persone giuste</strong> <span class="tag ai">Consigliato</span><br />
                      <small class="muted">Pubblico Advantage+: età e interessi diventano suggerimenti e Meta cerca da sola chi risponde meglio.</small></span>
                  </label>
                  <div class="form-grid">
                    <label>Dove
                      <input [(ngModel)]="ad.audience.location" (ngModelChange)="changed()" placeholder="Es. Pescara, Abruzzo, Italia" />
                    </label>
                    <label>Raggio intorno (km)
                      <input type="number" min="0" max="80" [(ngModel)]="ad.audience.radius_km" (ngModelChange)="changed()" />
                    </label>
                    <label>Età da
                      <input type="number" min="13" max="65" [(ngModel)]="ad.audience.age_min" (ngModelChange)="changed()" [disabled]="lockedAudience()" />
                    </label>
                    <label>Età fino a (65 = 65+)
                      <input type="number" min="13" max="65" [(ngModel)]="ad.audience.age_max" (ngModelChange)="changed()" [disabled]="lockedAudience()" />
                    </label>
                  </div>
                  <span class="field-label">Genere</span>
                  <div class="filters">
                    @for (g of genders; track g.key) {
                      <button type="button" class="chip" [class.active]="ad.audience.genders === g.key" (click)="ad.audience.genders = g.key; changed()" [disabled]="lockedAudience()">{{ g.label }}</button>
                    }
                  </div>
                  @if (lockedAudience()) { <p class="muted small">Con la categoria «{{ categoryLabel() }}» Meta non permette di scegliere età e genere.</p> }
                  <label>Interessi (separati da virgola)
                    <input [(ngModel)]="interests" (ngModelChange)="setInterests($event)" placeholder="Es. pizza, cucina italiana, cene fuori" />
                  </label>
                  <p class="muted small">Passioni e abitudini, non caratteristiche personali: Meta non permette di scegliere il pubblico per salute, religione, politica o orientamento.</p>
                </div>
              }
              @case (2) {
                <div class="wizard-main narrow-col">
                  <div class="form-grid">
                    <label>Budget al giorno (€)
                      <input type="number" min="1" step="1" [(ngModel)]="ad.daily_budget" (ngModelChange)="changed()" />
                    </label>
                    <span></span>
                    <label>Dal
                      <input type="date" [(ngModel)]="ad.start_date" (ngModelChange)="changed()" />
                    </label>
                    <label>Al (vuoto = finché non la fermi)
                      <input type="date" [(ngModel)]="endDate" (ngModelChange)="ad.end_date = $event || null; changed()" [min]="ad.start_date" />
                    </label>
                  </div>
                  <div class="budget-summary">
                    @if (days(); as d) {
                      <strong>{{ ad.daily_budget * d | currency: 'EUR' }}</strong>
                      <span class="muted">spesa massima in {{ d }} {{ d === 1 ? 'giorno' : 'giorni' }}</span>
                    } @else {
                      <strong>{{ ad.daily_budget * 30 | currency: 'EUR' }}</strong>
                      <span class="muted">circa al mese, finché non la fermi</span>
                    }
                  </div>
                  <p class="muted small">Meta accetta da {{ rules()!.budget.min_daily | currency: 'EUR' }} al giorno. Con almeno {{ rules()!.budget.suggested_daily | currency: 'EUR' : 'symbol' : '1.0-0' }} al giorno per 7 giorni
                    finisce prima la fase di apprendimento e i risultati costano meno.</p>
                </div>
              }
              @case (3) {
                <div class="wizard-grid">
                  <div class="wizard-main">
                    <label>Testo principale <span class="counter" [class.over]="ad.primary_text.length > rules()!.text_limits.primary_text">{{ ad.primary_text.length }}/{{ rules()!.text_limits.primary_text }}</span>
                      <textarea rows="5" [(ngModel)]="ad.primary_text" (ngModelChange)="changed()" placeholder="Cosa offre il cliente e perché sceglierlo, in 1-3 frasi."></textarea>
                    </label>
                    <div class="form-grid">
                      <label>Titolo <span class="counter" [class.over]="ad.headline.length > rules()!.text_limits.headline">{{ ad.headline.length }}/{{ rules()!.text_limits.headline }}</span>
                        <input [(ngModel)]="ad.headline" (ngModelChange)="changed()" />
                      </label>
                      <label>Descrizione <span class="counter" [class.over]="ad.description.length > rules()!.text_limits.description">{{ ad.description.length }}/{{ rules()!.text_limits.description }}</span>
                        <input [(ngModel)]="ad.description" (ngModelChange)="changed()" />
                      </label>
                      <label>Pulsante
                        <select [(ngModel)]="ad.cta" (ngModelChange)="changed()">
                          @for (c of ctaOptions(); track c.code) { <option [ngValue]="c.code">{{ c.label }}</option> }
                        </select>
                      </label>
                      <label>Link{{ objective()?.needs_url ? '' : ' (facoltativo)' }}
                        <input [(ngModel)]="ad.url" (ngModelChange)="changed()" placeholder="https://" />
                      </label>
                    </div>
                  </div>
                  <aside class="wizard-side"><ng-container *ngTemplateOutlet="previewTpl" /></aside>
                </div>
              }
              @case (4) {
                <div class="wizard-grid">
                  <div class="wizard-main">
                    <span class="field-label">Che musica mettiamo?</span>
                    <div class="objective-grid">
                      @for (s of rules()!.music.sources; track s.key) {
                        <button type="button" class="payer-option" [class.on]="ad.music.source === s.key" (click)="setMusicSource(s.key)">
                          <strong><i [class]="'ti ' + s.icon" aria-hidden="true"></i> {{ s.label }}@if (s.key === 'meta') { <span class="tag ai">Consigliato</span> }</strong>
                          <span>{{ s.detail }}</span>
                        </button>
                      }
                    </div>
                    @switch (ad.music.source) {
                      @case ('meta') {
                        <span class="field-label">Atmosfera</span>
                        <div class="mood-grid">
                          @for (m of rules()!.music.moods; track m.key) {
                            <button type="button" class="payer-option" [class.on]="ad.music.mood === m.key" (click)="ad.music.mood = m.key; changed()">
                              <strong><i [class]="'ti ' + m.icon" aria-hidden="true"></i> {{ m.label }}</strong>
                              @if (m.key === suggestedMood()) { <span class="tag ai">Adatta a {{ brand()?.name }}</span> }
                              <span>{{ m.feel }}</span>
                            </button>
                          }
                        </div>
                        @if (mood(); as m) {
                          <div class="music-search">
                            <i class="ti ti-search" aria-hidden="true"></i>
                            <span>Cerca nella libreria di Meta: <strong>{{ m.search }}</strong><br /><small class="muted">Ritmo {{ m.bpm }} battiti al minuto</small></span>
                            <span class="grow"></span>
                            <button type="button" class="btn ghost small" (click)="copy(m.search, 'search')"><i class="ti" [class]="copied() === 'search' ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i> {{ copied() === 'search' ? 'Copiato' : 'Copia' }}</button>
                          </div>
                        }
                        <label class="toggle-row">
                          <input type="checkbox" [(ngModel)]="ad.music.meta_picks" (ngModelChange)="changed()" />
                          <span><strong>Lascia che Meta scelga il brano</strong><br />
                            <small class="muted">Meta abbina all'inserzione un brano della sua libreria con questa atmosfera; prima di pubblicare puoi ascoltarlo e cambiarlo.</small></span>
                        </label>
                        <p class="muted small">I brani della libreria di Meta sono già autorizzati per le inserzioni: niente diritti da pagare e nessun rischio che l'audio venga tolto.</p>
                      }
                      @case ('own') {
                        <label>Traccia
                          <select [(ngModel)]="ad.music.asset_id" (ngModelChange)="setTrack($event)">
                            <option [ngValue]="null">— Scegli una traccia —</option>
                            @for (a of tracks(); track a.id) { <option [ngValue]="a.id">{{ a.original_name }}</option> }
                          </select>
                        </label>
                        @if (auth.canEdit()) {
                          <button type="button" class="link-btn" (click)="musicInput.click()" [disabled]="uploadingMusic()">
                            @if (uploadingMusic()) { <span class="spinner small"></span> Carico… } @else { <i class="ti ti-upload" aria-hidden="true"></i> Carica una traccia (MP3, M4A, WAV, OGG) }
                          </button>
                          <input #musicInput type="file" hidden accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg" (change)="uploadMusic($event)" />
                        }
                        @if (ad.music.asset_id; as id) { <div class="music-player"><app-asset-preview [assetId]="id" kind="audio" /></div> }
                        <label class="toggle-row">
                          <input type="checkbox" [(ngModel)]="ad.music.rights_confirmed" (ngModelChange)="changed()" />
                          <span><strong>Abbiamo la licenza per usarla nella pubblicità</strong><br />
                            <small class="muted">Brano composto per il cliente, comprato con licenza commerciale o libero da diritti anche per la pubblicità. Le canzoni famose non vanno bene.</small></span>
                        </label>
                        @if (content()) {
                          <div class="music-search">
                            <i class="ti ti-movie" aria-hidden="true"></i>
                            @if (videoBusy()) {
                              <span><span class="spinner small"></span> Monto il video con la musica… di solito 10-20 secondi.</span>
                            } @else {
                              <span>Video verticale 9:16 per Reels e Storie, con la grafica del post, il logo e questa traccia.</span>
                              <span class="grow"></span>
                              <button type="button" class="btn primary small" (click)="makeVideo()" [disabled]="!ad.music.asset_id || !ad.music.rights_confirmed || !auth.canEdit()">
                                <i class="ti ti-movie" aria-hidden="true"></i> {{ content()!.video_asset_id ? 'Rifai il video' : 'Crea il video' }}</button>
                            }
                          </div>
                        } @else {
                          <p class="muted small">Per montare qui il video con la musica, crea la sponsorizzata partendo da un post. Altrimenti aggiungi la traccia al tuo video prima di caricarlo su Meta.</p>
                        }
                      }
                      @case ('none') {
                        <p class="muted small">L'inserzione userà l'immagine, oppure il video con il suo audio originale.</p>
                      }
                    }
                  </div>
                  <aside class="wizard-side">
                    @if (ad.music.source === 'own' && content()?.video_asset_id) {
                      @if (content()!.video_asset_id; as v) { <div class="ad-video"><app-asset-preview [assetId]="v" kind="video" fit="contain" /></div> }
                    } @else {
                      <ng-container *ngTemplateOutlet="previewTpl" />
                    }
                  </aside>
                </div>
              }
              @case (5) {
                @if (saved(); as p) {
                  <div class="wizard-grid">
                    <div class="wizard-main">
                      @if (metaPanel()) {
                        <section class="meta-publish" aria-live="polite">
                          <h3><i class="ti ti-brand-meta" aria-hidden="true"></i> {{ p.meta.published ? 'Su Meta' : 'Pubblica su Facebook e Instagram' }}
                            @if (p.meta.sample) { <span class="tag">Esempio</span> }</h3>
                          @if (meta(); as m) {
                            <div class="meta-where">
                              <span><i class="ti ti-credit-card" aria-hidden="true"></i>{{ m.ad_account?.name }}</span>
                              <span><i class="ti ti-brand-facebook" aria-hidden="true"></i>{{ m.page?.name }}</span>
                              @if (m.instagram) { <span><i class="ti ti-brand-instagram" aria-hidden="true"></i>&#64;{{ m.instagram.username }}</span> }
                            </div>
                          }

                          @if (!p.meta.ad_id) {
                            @if (p.meta.job_status === 'queued' || p.meta.job_status === 'running') {
                              <div class="rewrite-progress"><span class="spinner"></span> Creo campagna, gruppo di inserzioni e inserzione su Meta…</div>
                            } @else {
                              @if (!directObjective()) {
                                <p class="hint small"><i class="ti ti-info-circle" aria-hidden="true"></i> L'obiettivo «{{ objective()?.label }}» richiede modulo contatti, Pixel o app configurati su Meta:
                                  per ora pubblicala con la scheda qui sotto, oppure scegli Notorietà, Traffico o Interazione.</p>
                              } @else {
                                <span class="field-label">Immagine dell'inserzione</span>
                                @if (photos().length) {
                                  <div class="image-pick" role="radiogroup" aria-label="Immagine dell'inserzione">
                                    @for (a of photos(); track a.id) {
                                      <button type="button" role="radio" [class.on]="imageId() === a.id" [attr.aria-checked]="imageId() === a.id" (click)="setImage(a.id)"
                                        [disabled]="busy() || !auth.canEdit()" [title]="a.original_name">
                                        <app-asset-preview [assetId]="a.id" kind="image" />
                                      </button>
                                    }
                                  </div>
                                  <p class="muted small">Formato consigliato 1080 × 1080 o 1080 × 1350 px. Si carica la foto senza i testi della grafica: titolo e testo li aggiunge Meta.</p>
                                } @else {
                                  <p class="muted small">Carica le foto del cliente nella libreria per sceglierne una.</p>
                                }
                                @if (p.meta.error) { <div class="alert small">{{ p.meta.error }}</div> }
                                @if (auth.canEdit()) {
                                  <button type="button" class="btn primary" (click)="publish()" [disabled]="busy() || !imageId()">
                                    <i class="ti ti-upload" aria-hidden="true"></i> {{ p.meta.campaign_id ? 'Riprova la pubblicazione' : 'Pubblica su Meta in pausa' }}</button>
                                  <p class="muted small">Si crea tutto in pausa: non si spende nulla finché non premi «Attiva».</p>
                                }
                              }
                            }
                          } @else {
                            <div class="meta-state">
                              <span class="badge" [attr.data-status]="metaStatus(p.meta.status).badge">{{ metaStatus(p.meta.status).label }}</span>
                              @if (p.meta.synced_at) { <small class="muted">aggiornato {{ p.meta.synced_at | date: 'd MMM HH:mm' }}</small> }
                              <button type="button" class="link-btn small" (click)="syncMeta()" [disabled]="busy()"><i class="ti ti-refresh" aria-hidden="true"></i> Aggiorna</button>
                              <a class="link-btn small" [href]="p.ads_manager_url" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i> Vedi su Meta</a>
                            </div>
                            @for (f of p.meta.feedback; track $index) {
                              <div class="rule-issue" data-severity="block"><i class="ti ti-circle-x" aria-hidden="true"></i><div><p>{{ f }}</p></div></div>
                            }
                            @if (p.meta.results; as r) {
                              <div class="meta-results">
                                <div><span>Speso</span><strong>{{ r.spent | currency: 'EUR' }}</strong></div>
                                <div><span>Visualizzazioni</span><strong>{{ r.impressions | number }}</strong></div>
                                <div><span>Persone raggiunte</span><strong>{{ r.reach | number }}</strong></div>
                                <div><span>Clic sul link</span><strong>{{ r.clicks | number }}</strong></div>
                                <div><span>Costo per clic</span><strong>{{ r.clicks ? (r.spent / r.clicks | currency: 'EUR') : '—' }}</strong></div>
                              </div>
                              <p class="muted small">La spesa compare in Sponsorizzate e nelle fatture del cliente, aggiornata da Meta ogni 6 ore.</p>
                            } @else if (p.meta.activated_at) {
                              <p class="muted small">I primi risultati arrivano qualche ora dopo l'attivazione.</p>
                            }

                            @if (auth.canEdit() && p.status !== 'ended') {
                              <div class="budget-edit">
                                <label>Budget giornaliero (€)
                                  <input type="number" min="1" step="0.5" [max]="meta()?.max_daily_budget ?? 100" [ngModel]="newBudget()" (ngModelChange)="newBudget.set($event)" />
                                </label>
                                @if (newBudget() !== p.daily_budget) {
                                  <button type="button" class="btn ghost small" (click)="changeBudget()" [disabled]="busy() || !newBudget()">Aggiorna su Meta</button>
                                }
                              </div>
                            }

                            @if (confirming()) {
                              <div class="activate-confirm" role="alertdialog" aria-label="Conferma attivazione">
                                <p><strong>Stai per attivare la sponsorizzata.</strong> Meta spenderà fino a <strong>{{ p.daily_budget | currency: 'EUR' }} al giorno</strong>
                                  dall'account «{{ meta()?.ad_account?.name }}»@if (p.end_date) {, fino al {{ p.end_date | date: 'd MMMM' }} (massimo {{ maxSpend() | currency: 'EUR' }})} @else {, senza data di fine}.
                                  Prima di andare in onda Meta la controlla: di solito entro 24 ore.</p>
                                <label class="toggle-row"><input type="checkbox" [ngModel]="confirmChecked()" (ngModelChange)="confirmChecked.set($event)" />
                                  <span>Confermo budget e date: il cliente è d'accordo.</span></label>
                                <div class="actions start">
                                  <button type="button" class="btn success" (click)="activate()" [disabled]="busy() || !confirmChecked()"><i class="ti ti-rocket" aria-hidden="true"></i> Attiva ora</button>
                                  <button type="button" class="btn ghost" (click)="confirming.set(false)">Annulla</button>
                                </div>
                              </div>
                            }
                            @if (!auth.canManage() && isPaused()) {
                              <p class="muted small"><i class="ti ti-lock" aria-hidden="true"></i> L'attivazione spende soldi veri: la può fare solo il titolare o un amministratore.</p>
                            }
                          }
                        </section>
                      } @else if (meta()?.mode) {
                        <section class="meta-publish">
                          <h3><i class="ti ti-brand-meta" aria-hidden="true"></i> Pubblica su Facebook e Instagram</h3>
                          <p class="hint small"><i class="ti ti-plug-connected-x" aria-hidden="true"></i>
                            @if (!meta()!.connected) {
                              Questo cliente non è ancora collegato a Facebook, quindi da qui la sponsorizzata non arriva su Meta.
                            } @else if (meta()!.expired || meta()!.error) {
                              Il collegamento a Facebook di questo cliente è scaduto o non funziona più.
                            } @else {
                              Il cliente è collegato a Facebook, ma mancano l'account pubblicitario o la pagina.
                            }
                          </p>
                          <p class="muted small">Apri la scheda del cliente, nel riquadro Meta premi «Collega Facebook e Instagram» e scegli account pubblicitario e pagina.
                            Poi torna qui: comparirà «Pubblica su Meta in pausa». «Segna come terminata» non pubblica nulla su Meta.</p>
                        </section>
                      }

                      <details class="sheet-toggle" [open]="!direct()">
                      <summary>{{ direct() ? 'Preferisci farlo a mano? Scheda per Gestione inserzioni' : 'Scheda per Gestione inserzioni' }}</summary>
                      <div class="sheet">
                        <div class="strategy-head"><h3>Scheda per Gestione inserzioni</h3>
                          <a class="btn primary small" [href]="p.ads_manager_url" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i> Apri Gestione inserzioni</a></div>
                        <p class="muted small">Su Meta clicca «Crea» e compila i campi in quest'ordine: con <i class="ti ti-copy" aria-hidden="true"></i> copi ogni valore.
                          @if (!p.ads_manager_url.includes('act=')) { Inserisci l'ID dell'account pubblicitario nella scheda del cliente per aprire direttamente il suo account. }</p>
                        @for (group of sheet(); track group.title) {
                          <h4>{{ group.title }}</h4>
                          <dl class="sheet-rows">
                            @for (row of group.rows; track row.label) {
                              <div><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd>
                                <button type="button" class="icon-btn" (click)="copy(row.value, row.label)" [attr.aria-label]="'Copia ' + row.label" [title]="copied() === row.label ? 'Copiato' : 'Copia'">
                                  <i class="ti" [class]="copied() === row.label ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i></button></div>
                            }
                          </dl>
                        }
                        @if (p.music.source === 'own' && content()?.video_asset_id) {
                          <h4>Video con la musica</h4>
                          <p class="muted small">Scaricalo dal lettore e caricalo su Meta come contenuto multimediale dell'inserzione.</p>
                          @if (content()!.video_asset_id; as v) { <div class="ad-video"><app-asset-preview [assetId]="v" kind="video" fit="contain" /></div> }
                        }
                        <p class="muted small">Il link ha i parametri UTM: in Analytics vedi le visite arrivate da questa sponsorizzata.</p>
                      </div>
                      </details>
                    </div>
                    <aside class="wizard-side">
                      @if (p.review_status === 'queued' || p.review_status === 'running') {
                        <div class="rewrite-progress"><span class="spinner"></span> L'AI sta controllando l'inserzione e la pagina di destinazione…</div>
                      } @else if (!p.review && auth.canEdit()) {
                        <button type="button" class="btn ghost full-width" (click)="review()" [disabled]="busy()"><i class="ti ti-sparkles" aria-hidden="true"></i> Controllo approfondito con l'AI</button>
                        <p class="muted small">L'AI rilegge testi e pagina di destinazione come un revisore di Meta e propone una versione corretta.</p>
                      }
                      @if (p.review && p.review_status !== 'queued' && p.review_status !== 'running') {
                        @if (p.review; as r) {
                        <div class="verdict" [attr.data-verdict]="r.verdict"><i class="ti" [class]="'ti ' + verdictInfo[r.verdict].icon" aria-hidden="true"></i>
                          <span><strong>Revisione AI:</strong> {{ r.summary }}</span></div>
                        @if (r.landing_ok === false) { <p class="muted small"><i class="ti ti-link-off" aria-hidden="true"></i> La pagina del link non si è aperta: controllala prima di pubblicare.</p> }
                        @for (i of r.issues; track $index) { <ng-container *ngTemplateOutlet="issueTpl; context: { $implicit: i }" /> }
                        @if (r.improved; as imp) {
                          <div class="improved">
                            <strong>Testi corretti dall'AI</strong>
                            <p>{{ imp.primary_text }}</p>
                            <small><strong>{{ imp.headline }}</strong>@if (imp.description) { · {{ imp.description }}}</small>
                            @if (auth.canEdit()) { <button type="button" class="btn ghost small" (click)="useImproved()" [disabled]="busy()">Usa questi testi</button> }
                          </div>
                        }
                        @if (r.audience_tip) { <p class="hint small"><i class="ti ti-users" aria-hidden="true"></i> {{ r.audience_tip }}</p> }
                        }
                      }
                      @if (p.review_error) { <div class="alert small">{{ p.review_error }}</div> }
                      @if (p.review && auth.canEdit()) { <button type="button" class="link-btn small" (click)="review()" [disabled]="busy()">Ripeti il controllo</button> }
                    </aside>
                  </div>
                }
              }
            }

            @if (step() < READY) {
              <section class="rule-check" aria-live="polite">
                <div class="verdict" [attr.data-verdict]="stepVerdict()">
                  <i class="ti" [class]="'ti ' + (checking() ? 'ti-loader-2' : verdictInfo[stepVerdict()].icon)" aria-hidden="true"></i>
                  <span><strong>Regole Meta:</strong> {{ checking() ? 'controllo…' : (stepVerdict() === 'ok' && visibleIssues().length ? 'nessun problema, solo consigli' : step() < 3 && stepVerdict() === 'ok' ? 'niente da segnalare in questo passaggio' : verdictInfo[stepVerdict()].text) }}</span>
                </div>
                @if (check()?.suggested_category && ad.special_category === 'none') {
                  <button type="button" class="btn ghost small" (click)="ad.special_category = check()!.suggested_category!; changed()">
                    Dichiara la categoria «{{ categoryLabel(check()!.suggested_category!) }}»</button>
                }
                @for (i of visibleIssues(); track $index) { <ng-container *ngTemplateOutlet="issueTpl; context: { $implicit: i }" /> }
              </section>
            } @else {
              @if (saved()?.checks; as c) {
                <section class="rule-check">
                  <div class="verdict" [attr.data-verdict]="c.verdict"><i class="ti" [class]="'ti ' + verdictInfo[c.verdict].icon" aria-hidden="true"></i>
                    <span><strong>Regole Meta:</strong> {{ verdictInfo[c.verdict].text }}</span></div>
                  @for (i of c.issues; track $index) { <ng-container *ngTemplateOutlet="issueTpl; context: { $implicit: i }" /> }
                </section>
              }
            }
          }
        </div>

        <div class="modal-foot">
          @if (step() > 0) { <button type="button" class="btn ghost" (click)="goTo(step() - 1)">Indietro</button> }
          <span class="grow"></span>
          @if (step() < MUSIC) {
            <button type="button" class="btn primary" (click)="goTo(step() + 1)" [disabled]="!canGoTo(step() + 1)">Avanti</button>
          } @else if (step() === MUSIC && auth.canEdit()) {
            <button type="button" class="btn primary" (click)="save()" [disabled]="busy() || videoBusy()"><i class="ti ti-check" aria-hidden="true"></i> {{ saved() ? 'Salva le modifiche' : 'Salva e prepara la scheda' }}</button>
          } @else if (step() === READY && saved()?.meta?.ad_id) {
            @if (isPaused() && auth.canManage() && saved()?.status !== 'ended' && !confirming()) {
              <button type="button" class="btn success" (click)="startActivation()" [disabled]="busy()"><i class="ti ti-rocket" aria-hidden="true"></i> Attiva su Meta</button>
            } @else if (isRunning() && auth.canEdit()) {
              <button type="button" class="btn ghost" (click)="pause()" [disabled]="busy()"><i class="ti ti-player-pause" aria-hidden="true"></i> Metti in pausa</button>
            }
          } @else if (step() === READY && auth.canEdit() && !direct()) {
            @if (saved()?.status === 'live') {
              <button type="button" class="btn ghost" (click)="setStatus('ended')" [disabled]="busy()">Segna come terminata</button>
            } @else if (saved()?.status !== 'ended') {
              <button type="button" class="btn success" (click)="setStatus('live')" [disabled]="busy()"><i class="ti ti-rocket" aria-hidden="true"></i> L'ho pubblicata su Meta</button>
            }
          }
        </div>
      </div>
    </div>

    <ng-template #previewTpl>
      <div class="ad-preview">
        <div class="ad-preview-head">
          <span class="avatar" [style.background]="brand()?.primary_color ?? '#5B4BDB'"></span>
          <span><strong>{{ brand()?.name }}</strong><br />
            @if (step() >= MUSIC && musicLine()) { <small class="ad-preview-music"><i class="ti ti-music" aria-hidden="true"></i> {{ musicLine() }}</small> }
            @else { <small class="muted">Sponsorizzato</small> }</span>
        </div>
        <p class="ad-preview-text">{{ previewText() }}@if (ad.primary_text.length > rules()!.text_limits.primary_text) {<span class="muted">… Altro</span>}</p>
        @if (content()?.visual) {
          <app-post-visual [visual]="content()!.visual" [brand]="brand()" />
        } @else {
          <div class="ad-preview-media" [style.background]="brand()?.primary_color ?? '#5B4BDB'"><span>{{ ad.headline || 'La tua immagine' }}</span></div>
        }
        <div class="ad-preview-foot">
          <span><small class="muted">{{ host() }}</small><br /><strong>{{ ad.headline || 'Titolo' }}</strong>@if (ad.description) {<br /><small class="muted">{{ ad.description }}</small>}</span>
          <span class="btn ghost small">{{ ctaLabel(ad.cta) }}</span>
        </div>
      </div>
    </ng-template>

    <ng-template #issueTpl let-i>
      <div class="rule-issue" [attr.data-severity]="i.severity">
        <i class="ti" [class]="'ti ' + severityIcon(i.severity)" aria-hidden="true"></i>
        <div>
          <strong>{{ i.title }}</strong>@if (i.excerpt) { <q>{{ i.excerpt }}</q> }
          <p>{{ i.why }}</p>
          <p class="fix"><i class="ti ti-arrow-right" aria-hidden="true"></i> {{ i.fix }}</p>
          @if (i.source) { <a class="small" [href]="i.source" target="_blank" rel="noopener">Regola ufficiale di Meta</a> }
        </div>
      </div>
    </ng-template>
  `,
})
export class AdWizardComponent implements OnInit, OnDestroy {
  @Input({ required: true }) brands: Brand[] = [];
  @Input() plan: AdPlan | null = null;
  @Input() contentId: number | null = null;
  @Input() brandId: number | null = null;
  /** Testi di partenza, per esempio la sponsorizzata proposta per battere un concorrente. */
  @Input() draft: AdDraft | null = null;
  @Output() closed = new EventEmitter<void>();
  @Output() changedPlan = new EventEmitter<AdPlan>();

  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly steps = STEPS;
  readonly MUSIC = MUSIC;
  readonly READY = READY;
  readonly verdictInfo = VERDICT;
  readonly genders = [{ key: 'all' as const, label: 'Tutti' }, { key: 'female' as const, label: 'Donne' }, { key: 'male' as const, label: 'Uomini' }];

  rules = signal<MetaRulebook | null>(null);
  content = signal<ContentItem | null>(null);
  saved = signal<AdPlan | null>(null);
  check = signal<AdCheck | null>(null);
  checking = signal(false);
  step = signal(0);
  busy = signal(false);
  error = signal<string | null>(null);
  copied = signal<string | null>(null);
  /** Tracce audio della libreria: prima quelle del cliente, poi le altre. */
  tracks = signal<Asset[]>([]);
  uploadingMusic = signal(false);
  videoBusy = signal(false);
  /** Collegamento del cliente a Meta e foto tra cui scegliere l'immagine dell'inserzione. */
  meta = signal<MetaConnection | null>(null);
  photos = signal<Asset[]>([]);
  confirming = signal(false);
  confirmChecked = signal(false);
  newBudget = signal<number | null>(null);

  /** Pannello di Meta: cliente collegato e pronto, oppure sponsorizzata già pubblicata da qui. */
  metaPanel = computed(() => !!this.saved()?.meta?.published || !!this.meta()?.ready);
  directObjective = computed(() => (this.version(), DIRECT_OBJECTIVES.includes(this.saved()?.objective ?? this.ad.objective)));
  /** Si pubblica da qui: la scheda da copiare resta chiusa, come alternativa. */
  direct = computed(() => !!this.saved()?.meta?.published || (!!this.meta()?.ready && this.directObjective()));
  /** L'immagine scelta, altrimenti la foto del post sponsorizzato. */
  imageId = computed(() => this.saved()?.image_asset_id ?? this.content()?.visual?.asset_id ?? null);
  isPaused = computed(() => ['paused', 'campaign_paused', 'adset_paused'].includes(this.saved()?.meta?.status ?? ''));
  isRunning = computed(() => ['active', 'in_process', 'pending_review', 'with_issues'].includes(this.saved()?.meta?.status ?? ''));
  maxSpend = computed(() => {
    const p = this.saved();
    if (!p?.end_date) return null;
    const days = Math.round((new Date(p.end_date).getTime() - new Date(Math.max(Date.now(), new Date(p.start_date).getTime())).getTime()) / 86400000) + 1;
    return Math.max(days, 1) * p.daily_budget;
  });
  /** Cambia a ogni modifica, per ricalcolare anteprima e riepiloghi che leggono il modulo. */
  private version = signal(0);

  ad: AdPlanInput = {
    brand_id: 0, content_id: null, name: '', objective: 'traffic', special_category: 'none',
    audience: { location: 'Italia', radius_km: 0, age_min: 18, age_max: 65, genders: 'all', interests: [], advantage: true },
    daily_budget: 5, start_date: isoDate(new Date()), end_date: null,
    primary_text: '', headline: '', description: '', cta: 'LEARN_MORE', url: '', music: { ...NO_MUSIC_CHOICE },
  };
  interests = '';
  endDate = '';

  private checks$ = new Subject<void>();
  private subs = new Subscription();

  objective = computed(() => (this.version(), this.rules()?.objectives.find((o) => o.key === this.ad.objective) ?? null));
  brand = computed(() => (this.version(), this.brands.find((b) => b.id === this.ad.brand_id) ?? null));
  lockedAudience = computed(() => (this.version(), ['financial', 'employment', 'housing'].includes(this.ad.special_category)));
  categoryDetail = computed(() => (this.version(), this.rules()?.special_categories.find((c) => c.key === this.ad.special_category)?.detail ?? ''));
  /** Pulsanti consigliati per l'obiettivo in cima, poi tutti gli altri. */
  ctaOptions = computed(() => {
    const all = this.rules()?.ctas ?? [];
    const first = this.objective()?.ctas ?? [];
    return [...first.map((code) => all.find((c) => c.code === code)!).filter(Boolean), ...all.filter((c) => !first.includes(c.code))];
  });
  days = computed(() => {
    this.version();
    if (!this.ad.end_date || !this.ad.start_date) return null;
    const d = Math.round((new Date(this.ad.end_date).getTime() - new Date(this.ad.start_date).getTime()) / 86400000) + 1;
    return d > 0 ? d : null;
  });
  previewText = computed(() => (this.version(), this.ad.primary_text.slice(0, this.rules()?.text_limits.primary_text ?? 125) || 'Il testo della sponsorizzata'));
  host = computed(() => {
    this.version();
    try { return new URL(this.ad.url).host.replace(/^www\./, '').toUpperCase(); } catch { return this.brand()?.website_url ? this.brand()!.website_url!.replace(/^https?:\/\/(www\.)?/, '').split('/')[0].toUpperCase() : ''; }
  });
  /**
   * Atmosfera adatta al cliente: quella con più parole in comune con settore, tono e descrizione;
   * se nessuna corrisponde, quella consigliata per l'obiettivo.
   */
  suggestedMood = computed(() => {
    this.version();
    const music = this.rules()?.music;
    if (!music) return null;
    const b = this.brand();
    const text = [b?.industry, b?.tone_of_voice, b?.summary, b?.products, b?.keywords].filter(Boolean).join(' ').toLowerCase();
    let best: string | null = null;
    let bestScore = 0;
    for (const m of music.moods) {
      const score = m.match.filter((stem) => text.includes(stem)).length;
      if (score > bestScore) { best = m.key; bestScore = score; }
    }
    return best ?? music.by_objective[this.ad.objective] ?? null;
  });
  mood = computed(() => (this.version(), this.rules()?.music.moods.find((m) => m.key === this.ad.music.mood) ?? null));
  /** La riga sotto il nome nell'anteprima, come nei Reels sponsorizzati. */
  musicLine = computed(() => {
    this.version();
    const m = this.ad.music;
    if (m.source === 'own') return m.track_name ? m.track_name.replace(/\.[a-z0-9]{2,4}$/i, '') : null;
    if (m.source === 'meta') return `Sponsorizzato · musica di Meta${this.mood() ? ', ' + this.mood()!.label.toLowerCase() : ''}`;
    return null;
  });
  /** Nei primi passaggi si mostrano solo i problemi che riguardano quel passaggio, gli altri arrivano dopo. */
  visibleIssues = computed(() => {
    const issues = this.check()?.issues ?? [];
    const step = this.step();
    const byStep: Record<number, (i: AdIssue) => boolean> = {
      0: (i) => ['politics', 'gambling', 'tobacco', 'special_audience'].includes(i.key ?? ''),
      1: (i) => /age|audience|interests|teen/.test(i.key ?? ''),
      2: (i) => /budget|short_run/.test(i.key ?? ''),
      [TEXTS]: (i) => !(i.key ?? '').startsWith('music_'),
    };
    return step === MUSIC ? issues : issues.filter(byStep[step] ?? (() => true));
  });
  stepVerdict = computed((): AdVerdict => {
    const severities = this.visibleIssues().map((i) => i.severity);
    return severities.includes('block') ? 'blocked' : severities.includes('warn') ? 'warning' : 'ok';
  });
  sheet = computed(() => {
    const p = this.saved();
    const r = this.rules();
    if (!p || !r) return [];
    const a = p.audience;
    const money = (n: number) => n.toLocaleString(LOCALE, { style: 'currency', currency: 'EUR' });
    const date = (d: string) => new Date(d).toLocaleDateString(LOCALE);
    return [
      { title: '1. Campagna', rows: [
        { label: 'Obiettivo', value: r.objectives.find((o) => o.key === p.objective)?.label ?? p.objective },
        { label: 'Categoria speciale', value: this.categoryLabel(p.special_category) },
        { label: 'Nome della campagna', value: `${p.brand_name} · ${p.name}` },
      ] },
      { title: '2. Gruppo di inserzioni', rows: [
        { label: 'Budget giornaliero', value: money(p.daily_budget) },
        { label: 'Programmazione', value: `Dal ${date(p.start_date)}` + (p.end_date ? ` al ${date(p.end_date)}` : ', senza data di fine') },
        { label: 'Luoghi', value: a.location + (a.radius_km ? ` + ${a.radius_km} km` : '') },
        { label: 'Età', value: `${a.age_min} - ${a.age_max === 65 ? '65+' : a.age_max}` },
        { label: 'Genere', value: this.genders.find((g) => g.key === a.genders)?.label ?? 'Tutti' },
        { label: 'Pubblico', value: (a.advantage ? 'Pubblico Advantage+ attivo' : 'Pubblico manuale') + (a.interests.length ? ` · interessi: ${a.interests.join(', ')}` : '') },
        { label: 'Posizionamenti', value: 'Posizionamenti Advantage+ (consigliato)' },
      ] },
      { title: '3. Inserzione', rows: [
        { label: 'Testo principale', value: p.primary_text },
        ...(p.headline ? [{ label: 'Titolo', value: p.headline }] : []),
        ...(p.description ? [{ label: 'Descrizione', value: p.description }] : []),
        { label: 'Invito all\'azione', value: this.ctaLabel(p.cta) },
        ...(p.url ? [{ label: 'URL del sito web', value: this.utm(p.url, p) }] : []),
      ] },
      { title: '4. Musica', rows: this.musicRows(p, r) },
    ];
  });

  ngOnInit(): void {
    this.subs.add(this.checks$.pipe(debounceTime(400), switchMap(() => { this.checking.set(true); return this.api.checkAd(this.ad); }))
      .subscribe({ next: (c) => { this.check.set(c); this.checking.set(false); }, error: () => this.checking.set(false) }));
    this.subs.add(this.api.metaRules().subscribe({ next: (r) => { this.rules.set(r); this.changed(); }, error: (e) => this.error.set(apiErrorMessage(e)) }));

    if (this.plan) {
      this.load(this.plan);
      this.step.set(READY);
    } else {
      this.ad.brand_id = this.brandId ?? this.brands[0]?.id ?? 0;
      if (this.contentId) this.prefill(this.contentId);
      else this.ad.url = this.brand()?.website_url ?? '';
      if (this.draft) {
        this.ad.primary_text = this.draft.primary_text;
        this.ad.headline = this.draft.headline.slice(0, 40);
        this.changed();
      }
    }
  }

  ngOnDestroy(): void {
    this.subs.unsubscribe();
  }

  changed(): void {
    this.version.update((v) => v + 1);
    this.checks$.next();
  }

  canGoTo(i: number): boolean {
    if (i === READY) return !!this.saved();
    return i >= 0 && i <= MUSIC && !!this.ad.brand_id;
  }

  goTo(i: number): void {
    if (!this.canGoTo(i)) return;
    if (i === MUSIC) {
      if (this.ad.music.source === 'meta' && !this.ad.music.mood) this.ad.music.mood = this.suggestedMood();
      this.loadTracks();
      this.changed();
    }
    this.step.set(i);
  }

  setMusicSource(source: AdMusicSource): void {
    // Tornando alla musica di Meta si riparte dalla scelta consigliata: Meta sceglie il brano.
    if (source === 'meta' && this.ad.music.source !== 'meta') this.ad.music.meta_picks = true;
    this.ad.music.source = source;
    if (source === 'meta' && !this.ad.music.mood) this.ad.music.mood = this.suggestedMood();
    this.changed();
  }

  setTrack(id: number | null): void {
    this.ad.music.track_name = this.tracks().find((a) => a.id === id)?.original_name ?? null;
    this.changed();
  }

  uploadMusic(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    this.uploadingMusic.set(true);
    this.error.set(null);
    this.api.uploadAsset(file, this.ad.brand_id || undefined).subscribe({
      next: (asset) => {
        this.uploadingMusic.set(false);
        if (asset.kind !== 'audio') {
          this.error.set('Il file caricato non è una traccia audio.');
          return;
        }
        this.tracks.update((list) => [asset, ...list]);
        this.ad.music.asset_id = asset.id;
        this.setTrack(asset.id);
      },
      error: (e) => { this.uploadingMusic.set(false); this.error.set(apiErrorMessage(e)); },
    });
  }

  /** Monta il video verticale del post con la traccia scelta (lo stesso montaggio del pulsante "Crea video" del post). */
  makeVideo(): void {
    const c = this.content();
    if (!c || !this.ad.music.asset_id) return;
    this.videoBusy.set(true);
    this.error.set(null);
    this.api.createVideo(c.id, 'story', this.ad.music.asset_id).pipe(
      switchMap((job) => this.api.waitForJob(job.id)),
    ).subscribe({
      next: (job) => {
        if (job.status === 'failed') {
          this.videoBusy.set(false);
          this.error.set(job.error ?? 'Montaggio del video non riuscito.');
          return;
        }
        this.api.contentItem(c.id).subscribe({
          next: (updated) => { this.content.set(updated); this.videoBusy.set(false); },
          error: (e) => { this.videoBusy.set(false); this.error.set(apiErrorMessage(e)); },
        });
      },
      error: (e) => { this.videoBusy.set(false); this.error.set(apiErrorMessage(e)); },
    });
  }

  setObjective(key: AdObjective): void {
    this.ad.objective = key;
    const ctas = this.rules()?.objectives.find((o) => o.key === key)?.ctas ?? [];
    if (!ctas.includes(this.ad.cta)) this.ad.cta = ctas[0] ?? 'LEARN_MORE';
    this.changed();
  }

  setInterests(text: string): void {
    this.ad.audience.interests = text.split(',').map((s) => s.trim()).filter(Boolean);
    this.changed();
  }

  severityIcon(severity: string): string {
    return SEVERITY[severity as RuleSeverity]?.icon ?? 'ti-info-circle';
  }

  categoryLabel(key = this.ad.special_category): string {
    return this.rules()?.special_categories.find((c) => c.key === key)?.label ?? key;
  }

  ctaLabel(code: string): string {
    return this.rules()?.ctas.find((c) => c.code === code)?.label ?? code;
  }

  save(): void {
    this.busy.set(true);
    this.error.set(null);
    const p = this.saved();
    const request = p ? this.api.updateAdPlan(p.id, this.ad) : this.api.createAdPlan(this.ad);
    request.subscribe({
      next: (plan) => { this.load(plan); this.step.set(READY); this.busy.set(false); this.changedPlan.emit(plan); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  review(): void {
    const p = this.saved();
    if (!p) return;
    this.busy.set(true);
    this.api.reviewAdPlan(p.id).subscribe({
      next: (plan) => { this.saved.set(plan); this.busy.set(false); this.pollReview(plan.id); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  useImproved(): void {
    const imp = this.saved()?.review?.improved;
    if (!imp) return;
    this.ad.primary_text = imp.primary_text;
    this.ad.headline = imp.headline;
    this.ad.description = imp.description;
    this.save();
  }

  setStatus(status: 'live' | 'ended'): void {
    const p = this.saved();
    if (!p) return;
    this.busy.set(true);
    this.api.updateAdPlan(p.id, { status }).subscribe({
      next: (plan) => { this.saved.set(plan); this.busy.set(false); this.changedPlan.emit(plan); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  metaStatus(status: string | null): { label: string; badge: string } {
    return META_STATUS[status ?? ''] ?? { label: status ?? 'Sconosciuto', badge: 'draft' };
  }

  setImage(assetId: number): void {
    const p = this.saved();
    if (!p || p.image_asset_id === assetId) return;
    this.busy.set(true);
    this.error.set(null);
    this.api.updateAdPlan(p.id, { ...this.ad, image_asset_id: assetId }).subscribe({
      next: (plan) => { this.saved.set(plan); this.busy.set(false); this.changedPlan.emit(plan); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  /** Crea la sponsorizzata su Meta in pausa (lo fa il worker) e aspetta che sia pronta. */
  publish(): void {
    const p = this.saved();
    if (!p) return;
    this.planCall(this.api.publishAdPlan(p.id), (plan) => this.pollMeta(plan.id));
  }

  startActivation(): void {
    this.confirmChecked.set(false);
    this.confirming.set(true);
  }

  activate(): void {
    const p = this.saved();
    if (!p) return;
    this.planCall(this.api.activateAdPlan(p.id, p.daily_budget), () => this.confirming.set(false));
  }

  pause(): void {
    const p = this.saved();
    if (p) this.planCall(this.api.pauseAdPlan(p.id));
  }

  syncMeta(): void {
    const p = this.saved();
    if (p) this.planCall(this.api.syncAdPlan(p.id));
  }

  changeBudget(): void {
    const p = this.saved();
    const budget = this.newBudget();
    if (!p || !budget) return;
    this.planCall(this.api.updateAdPlan(p.id, { daily_budget: budget }), (plan) => (this.ad.daily_budget = plan.daily_budget));
  }

  copy(value: string, label: string): void {
    navigator.clipboard?.writeText(value).then(() => {
      this.copied.set(label);
      setTimeout(() => this.copied() === label && this.copied.set(null), 1500);
    });
  }

  private pollReview(id: number): void {
    this.subs.add(timer(1000, 1000).pipe(
      switchMap(() => this.api.adPlan(id)),
      takeWhile((p) => p.review_status === 'queued' || p.review_status === 'running', true),
    ).subscribe((plan) => { this.saved.set(plan); this.changedPlan.emit(plan); }));
  }

  private pollMeta(id: number): void {
    this.subs.add(timer(1000, 1000).pipe(
      switchMap(() => this.api.adPlan(id)),
      takeWhile((p) => p.meta.job_status === 'queued' || p.meta.job_status === 'running', true),
    ).subscribe((plan) => { this.saved.set(plan); this.changedPlan.emit(plan); }));
  }

  /** Operazione sulla sponsorizzata che restituisce la sponsorizzata aggiornata. */
  private planCall(request: Observable<AdPlan>, after?: (plan: AdPlan) => void): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (plan) => {
        this.saved.set(plan);
        this.newBudget.set(plan.daily_budget);
        this.busy.set(false);
        this.changedPlan.emit(plan);
        after?.(plan);
      },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  private load(plan: AdPlan): void {
    this.saved.set(plan);
    this.newBudget.set(plan.daily_budget);
    // Collegamento a Meta e foto del cliente, per pubblicare da qui.
    this.api.metaConnection(plan.brand_profile_id).subscribe({ next: (m) => this.meta.set(m), error: () => this.meta.set(null) });
    // Prima le foto del cliente, poi le altre della libreria.
    this.api.assets().subscribe({
      next: (list) => this.photos.set(list.filter((a) => a.kind === 'image')
        .sort((a, b) => Number(b.brand_profile_id === plan.brand_profile_id) - Number(a.brand_profile_id === plan.brand_profile_id))),
      error: () => {},
    });
    if (plan.meta.job_status === 'queued' || plan.meta.job_status === 'running') this.pollMeta(plan.id);
    this.ad = {
      brand_id: plan.brand_profile_id, content_id: plan.content_item_id, name: plan.name, objective: plan.objective, special_category: plan.special_category,
      audience: { ...plan.audience, interests: [...plan.audience.interests] }, daily_budget: plan.daily_budget, start_date: plan.start_date, end_date: plan.end_date,
      primary_text: plan.primary_text, headline: plan.headline, description: plan.description, cta: plan.cta, url: plan.url ?? '',
      music: { ...(plan.music ?? NO_MUSIC_CHOICE) },
    };
    this.interests = plan.audience.interests.join(', ');
    this.endDate = plan.end_date ?? '';
    if (plan.content_item_id) this.api.contentItem(plan.content_item_id).subscribe({ next: (c) => this.content.set(c), error: () => {} });
    this.changed();
  }

  /** Dal post: testo senza note per il revisore né hashtag, titolo della grafica e link del sito. */
  private prefill(contentId: number): void {
    this.ad.content_id = contentId;
    this.api.contentItem(contentId).pipe(
      switchMap((c) => this.api.project(c.project_id).pipe(map((p) => ({ c, brandId: p.brand_profile_id })))),
    ).subscribe({
      next: ({ c, brandId }) => {
        this.content.set(c);
        if (brandId) this.ad.brand_id = brandId;
        const text = publishableText(c.body).replace(/(\n\s*)?(#[\p{L}\d_]+\s*)+$/u, '').replace(/👉\s*/u, '').trim();
        this.ad.primary_text = text;
        this.ad.headline = (c.visual?.headline || c.title).slice(0, 40);
        this.ad.url = this.brands.find((b) => b.id === this.ad.brand_id)?.website_url ?? '';
        this.changed();
      },
      error: (e) => this.error.set(apiErrorMessage(e)),
    });
  }

  private loadTracks(): void {
    const brandId = this.ad.brand_id;
    this.api.assets().subscribe({
      next: (assets) => {
        const audio = assets.filter((a) => a.kind === 'audio');
        this.tracks.set([...audio.filter((a) => a.brand_profile_id === brandId), ...audio.filter((a) => a.brand_profile_id !== brandId)]);
      },
      error: () => {},
    });
  }

  /** Righe della scheda per la musica, nell'ordine in cui si fa su Gestione inserzioni. */
  private musicRows(p: AdPlan, r: MetaRulebook): { label: string; value: string }[] {
    const m = p.music;
    if (m.source === 'own') {
      return [
        { label: 'Musica', value: this.content()?.video_asset_id
          ? 'Traccia vostra già montata nel video: carica il video come contenuto multimediale dell\'inserzione.'
          : 'Traccia vostra: aggiungila al video prima di caricarlo come contenuto multimediale dell\'inserzione.' },
        { label: 'Traccia', value: m.track_name ?? '—' },
        { label: 'Licenza', value: m.rights_confirmed ? 'Confermata per uso commerciale' : 'Da confermare prima di pubblicare' },
      ];
    }
    if (m.source === 'none') return [{ label: 'Musica', value: 'Nessuna' }];
    const mood = r.music.moods.find((x) => x.key === m.mood);
    return [
      { label: 'Musica', value: m.meta_picks
        ? 'Nell\'inserzione, alla voce Musica, lascia che Meta scelga il brano adatto; prima di pubblicare puoi ascoltarlo e cambiarlo.'
        : 'Nell\'inserzione, alla voce Musica, scegli un brano dalla libreria di Meta.' },
      ...(mood ? [
        { label: 'Atmosfera', value: `${mood.label} · ${mood.bpm} battiti al minuto` },
        { label: 'Cerca nella libreria', value: mood.search },
      ] : []),
    ];
  }

  /** Aggiunge i parametri UTM, così le visite dalla sponsorizzata si riconoscono in Analytics. */
  private utm(url: string, p: AdPlan): string {
    try {
      const u = new URL(url);
      const slug = `${p.brand_name}-${p.name}`.toLowerCase().normalize('NFD').replace(/[^\x00-\x7f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      if (!u.searchParams.has('utm_source')) u.searchParams.set('utm_source', 'meta');
      if (!u.searchParams.has('utm_medium')) u.searchParams.set('utm_medium', 'paid_social');
      if (!u.searchParams.has('utm_campaign')) u.searchParams.set('utm_campaign', slug.slice(0, 60));
      return u.toString();
    } catch {
      return url;
    }
  }
}
