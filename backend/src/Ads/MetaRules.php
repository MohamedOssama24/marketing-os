<?php
declare(strict_types=1);

/**
 * Le regole di Meta per le sponsorizzate, in parole semplici: obiettivi, pulsanti, limiti dei testi,
 * categorie speciali e i controlli che una sponsorizzata deve passare prima di andare su Gestione inserzioni.
 * Le pagine ufficiali da cui vengono sono in MetaPolicyMonitor::SOURCES e sono controllate ogni giorno:
 * quando Meta le cambia, l'agenzia riceve un avviso e qui va rivista la regola collegata.
 */
final class MetaRules
{
    /** Data dell'ultima revisione di queste regole sulle pagine ufficiali. */
    public const VERSION = '2026-10-29';

    /** I sei obiettivi di Gestione inserzioni, con i nomi che si vedono su Meta. */
    public const OBJECTIVES = [
        'awareness' => ['label' => 'Notorietà', 'icon' => 'ti-speakerphone', 'when' => 'Farsi conoscere in zona: mostra l\'inserzione al maggior numero di persone.', 'ctas' => ['LEARN_MORE', 'CALL_NOW', 'GET_DIRECTIONS'], 'needs_url' => false],
        'traffic' => ['label' => 'Traffico', 'icon' => 'ti-world-www', 'when' => 'Portare persone sul sito, su una pagina o su WhatsApp.', 'ctas' => ['LEARN_MORE', 'SHOP_NOW', 'BOOK_NOW', 'WHATSAPP_MESSAGE'], 'needs_url' => true],
        'engagement' => ['label' => 'Interazione', 'icon' => 'ti-heart', 'when' => 'Più like, commenti, messaggi o visualizzazioni dei video.', 'ctas' => ['SEND_MESSAGE', 'WHATSAPP_MESSAGE', 'LEARN_MORE'], 'needs_url' => false],
        'leads' => ['label' => 'Contatti', 'icon' => 'ti-address-book', 'when' => 'Raccogliere richieste: modulo su Meta, chiamate o messaggi.', 'ctas' => ['SIGN_UP', 'GET_QUOTE', 'CONTACT_US', 'CALL_NOW'], 'needs_url' => false],
        'app_promotion' => ['label' => 'Promozione dell\'app', 'icon' => 'ti-device-mobile', 'when' => 'Far scaricare o usare un\'app.', 'ctas' => ['INSTALL_APP', 'DOWNLOAD', 'USE_APP'], 'needs_url' => true],
        'sales' => ['label' => 'Vendite', 'icon' => 'ti-shopping-cart', 'when' => 'Vendere online: serve il Pixel di Meta sul sito per misurare gli acquisti.', 'ctas' => ['SHOP_NOW', 'ORDER_NOW', 'BOOK_NOW', 'GET_OFFER'], 'needs_url' => true],
    ];

    /** Pulsanti di invito all'azione di Meta: codice dell'API e nome in Gestione inserzioni. */
    public const CTAS = [
        'LEARN_MORE' => 'Scopri di più', 'SHOP_NOW' => 'Acquista ora', 'BOOK_NOW' => 'Prenota ora', 'ORDER_NOW' => 'Ordina ora',
        'SIGN_UP' => 'Iscriviti', 'CONTACT_US' => 'Contattaci', 'CALL_NOW' => 'Chiama ora', 'SEND_MESSAGE' => 'Invia messaggio',
        'WHATSAPP_MESSAGE' => 'Invia messaggio WhatsApp', 'GET_QUOTE' => 'Richiedi preventivo', 'GET_OFFER' => 'Ottieni offerta',
        'GET_DIRECTIONS' => 'Indicazioni stradali', 'DOWNLOAD' => 'Scarica', 'INSTALL_APP' => 'Installa ora', 'USE_APP' => 'Usa l\'app',
        'SUBSCRIBE' => 'Abbonati', 'APPLY_NOW' => 'Candidati ora', 'WATCH_MORE' => 'Guarda altro',
    ];

    /** Categorie speciali: vanno dichiarate su Meta e limitano il pubblico. */
    public const SPECIAL_CATEGORIES = [
        'none' => ['label' => 'Nessuna', 'detail' => 'La maggior parte delle attività: negozi, ristoranti, servizi, eventi.'],
        'financial' => ['label' => 'Prodotti e servizi finanziari', 'detail' => 'Prestiti, mutui, carte, assicurazioni, investimenti. Su Meta età, genere e parte degli interessi non si possono scegliere.'],
        'employment' => ['label' => 'Lavoro', 'detail' => 'Offerte di lavoro, stage, corsi che promettono un impiego. Su Meta età, genere e parte degli interessi non si possono scegliere.'],
        'housing' => ['label' => 'Immobili', 'detail' => 'Case in vendita o in affitto, mutui per la casa, assicurazioni sulla casa. Su Meta età, genere e parte degli interessi non si possono scegliere.'],
        'social_issues' => ['label' => 'Temi sociali, elezioni o politica', 'detail' => 'Dall\'ottobre 2025 Meta non pubblica più queste inserzioni nell\'Unione Europea.'],
    ];

    /** Limiti consigliati da Meta per i testi: oltre, il testo viene tagliato con "Altro" o nascosto in alcuni posizionamenti. */
    public const TEXT_LIMITS = ['primary_text' => 125, 'headline' => 40, 'description' => 30];

    /** Formati delle immagini e dei video consigliati da Meta. */
    public const SPECS = [
        ['placement' => 'Feed di Facebook e Instagram', 'ratio' => '1:1 o 4:5', 'size' => '1080 × 1080 o 1080 × 1350 px', 'note' => 'Il 4:5 occupa più spazio sul telefono.'],
        ['placement' => 'Storie e Reels', 'ratio' => '9:16', 'size' => '1080 × 1920 px', 'note' => 'Lascia liberi da testo e logo il 14% in alto e il 35% in basso: lì ci sono nome e pulsanti.'],
        ['placement' => 'Colonna di destra e risultati di ricerca', 'ratio' => '1:1', 'size' => '1080 × 1080 px', 'note' => 'Immagine piccola: un solo messaggio, leggibile.'],
        ['placement' => 'Video', 'ratio' => '4:5 o 9:16', 'size' => '1080 px di lato minimo', 'note' => 'I primi 3 secondi decidono: metti subito prodotto e messaggio; aggiungi i sottotitoli.'],
    ];

    /** Budget minimo giornaliero accettato da Meta in euro e quello consigliato per finire la fase di apprendimento. */
    public const MIN_DAILY_BUDGET = 1.0;
    public const SUGGESTED_DAILY_BUDGET = 5.0;

    /**
     * Da dove arriva la musica della sponsorizzata. Nelle inserzioni Meta accetta solo musica con licenza per uso commerciale:
     * la sua libreria è già autorizzata e gratuita, una traccia propria va confermata.
     */
    public const MUSIC_SOURCES = [
        'meta' => ['label' => 'Musica di Meta', 'icon' => 'ti-brand-meta', 'detail' => 'Brani già autorizzati per le inserzioni, gratis. Si aggiunge in Gestione inserzioni, scegliendo il brano o lasciando che Meta scelga quello adatto.'],
        'own' => ['label' => 'Una traccia nostra', 'icon' => 'ti-music', 'detail' => 'Una traccia della libreria del cliente, montata nel video della sponsorizzata. Serve la licenza per uso commerciale.'],
        'none' => ['label' => 'Senza musica', 'icon' => 'ti-volume-off', 'detail' => 'Solo immagine, o video con il suo audio originale.'],
    ];

    /**
     * Atmosfere musicali: parole da cercare nella libreria musicale di Meta (i generi in inglese funzionano ovunque),
     * ritmo indicativo e radici di parole del settore o del tono del cliente per cui sono consigliate.
     */
    public const MUSIC_MOODS = [
        'upbeat' => ['label' => 'Allegra e solare', 'icon' => 'ti-sun', 'feel' => 'Mette di buon umore: locali, turismo, offerte, eventi.', 'search' => 'upbeat, happy, summer pop', 'bpm' => '110-130',
            'match' => ['ristorant', 'pizzer', 'gelat', 'turism', 'viagg', 'vacanz', 'event', 'festa', 'bambin', 'allegr', 'solar', 'amichevol', 'informal']],
        'chill' => ['label' => 'Rilassata', 'icon' => 'ti-leaf', 'feel' => 'Calma e accogliente: benessere, caffè, casa, hotel.', 'search' => 'chill, lo-fi, acoustic calm', 'bpm' => '70-90',
            'match' => ['spa', 'benesser', 'estetic', 'yoga', 'massagg', 'hotel', 'caff', 'arred', 'calm', 'rilass', 'accoglient']],
        'elegant' => ['label' => 'Elegante', 'icon' => 'ti-diamond', 'feel' => 'Raffinata e sobria: moda, gioielli, vino, studi professionali.', 'search' => 'piano, jazz, lounge', 'bpm' => '60-90',
            'match' => ['gioiell', 'moda', 'lusso', 'vino', 'cantin', 'gourmet', 'immobil', 'avvocat', 'notai', 'consulen', 'elegant', 'raffinat', 'professional', 'autorevol']],
        'energetic' => ['label' => 'Energica', 'icon' => 'ti-bolt', 'feel' => 'Ritmo alto: sport, palestre, motori, saldi.', 'search' => 'workout, energetic, dance pop', 'bpm' => '120-140',
            'match' => ['palestr', 'fitness', 'sport', 'crossfit', 'concessionari', 'moto', 'officin', 'energic', 'dinamic', 'grint', 'saldi']],
        'inspiring' => ['label' => 'Emozionante', 'icon' => 'ti-heart', 'feel' => 'Racconta una storia: matrimoni, fotografi, salute, associazioni.', 'search' => 'cinematic, inspiring, emotional', 'bpm' => '80-110',
            'match' => ['matrimon', 'sposi', 'fotograf', 'onlus', 'associazion', 'medic', 'dentist', 'clinic', 'sanit', 'emozion', 'ispira', 'empat']],
        'modern' => ['label' => 'Moderna e tecnologica', 'icon' => 'ti-cpu', 'feel' => 'Pulita e innovativa: tecnologia, software, startup.', 'search' => 'electronic, corporate tech, future bass', 'bpm' => '100-125',
            'match' => ['tecnolog', 'software', 'startup', 'informatic', 'digital', 'marketing', 'innovat', 'modern']],
        'italian' => ['label' => 'Italiana e tradizionale', 'icon' => 'ti-tools-kitchen-2', 'feel' => 'Calore di casa: trattorie, prodotti tipici, artigiani.', 'search' => 'italian, mandolin, accordion folk', 'bpm' => '90-120',
            'match' => ['trattori', 'osteri', 'agritur', 'tipic', 'alimentar', 'pastif', 'panific', 'macelle', 'artigian', 'tradizion', 'genuin', 'familiar']],
        'playful' => ['label' => 'Divertente', 'icon' => 'ti-mood-happy', 'feel' => 'Leggera e simpatica: animali, giochi, contenuti ironici.', 'search' => 'funny, quirky, ukulele', 'bpm' => '100-120',
            'match' => ['animal', 'veterinar', 'toelett', 'giocattol', 'giochi', 'ludot', 'divertent', 'ironic', 'simpatic', 'scherzos']],
    ];

    /** Atmosfera da proporre quando settore e tono del cliente non ne suggeriscono una. */
    public const MUSIC_BY_OBJECTIVE = ['awareness' => 'upbeat', 'traffic' => 'modern', 'engagement' => 'playful', 'leads' => 'chill', 'app_promotion' => 'modern', 'sales' => 'energetic'];

    /** Nomi di file che fanno pensare a un brano commerciale (scaricato da YouTube o Spotify, remix, versione ufficiale). */
    private const COMMERCIAL_TRACK = '/official|ufficial|remix|feat\.|\bft\.|cover|spotify|youtube|lyrics|radio edit|\bvevo\b|videoclip/u';

    /**
     * Regole controllate sul testo. Gravità: block = Meta la rifiuta o la legge la vieta; warn = rischio o limiti da rispettare; tip = consiglio.
     * I pattern sono espressioni regolari sul testo in minuscolo.
     */
    private const TEXT_RULES = [
        'personal_attributes' => ['severity' => 'block', 'source' => 'personal-attributes', 'title' => 'Attributi personali',
            'why' => 'Meta non accetta testi che dicono o lasciano intendere di sapere qualcosa di personale su chi legge (salute, peso, soldi, religione, orientamento, situazione familiare).',
            'fix' => 'Parla del prodotto o in terza persona: invece di "Sei sovrappeso?" scrivi "Un percorso per chi vuole rimettersi in forma".',
            'patterns' => [
                '/\b(sei|siete)\s+(sovrappeso|grass[oaie]|obes[oaie]|depress[oaie]|ansios[oaie]|calv[oaie]|malat[oaie]|diabetic[oaie]|single|divorziat[oaie]|incinta|gay|lesbica|indebitat[oaie]|disoccupat[oaie]|al verde|stress?at[oaie])\b/u',
                '/\b(soffri|soffrite)\s+(di|d\')/u',
                '/\bhai\s+(debiti|il diabete|l\'ansia|la depressione|i chili di troppo|problemi di (peso|erezione|cuore|pelle|acne|soldi|denaro|alcol))/u',
                '/\b(sei|siete)\s+stanc[oaie]\s+di\s+essere\b/u',
            ]],
        'before_after' => ['severity' => 'block', 'source' => 'health-wellness', 'title' => 'Prima e dopo',
            'why' => 'Le immagini e i testi "prima e dopo" su peso, corpo e trattamenti estetici non sono ammessi.',
            'fix' => 'Mostra il servizio, lo studio o le persone soddisfatte senza confronti sul corpo.',
            'patterns' => ['/prima\s*(e|\/|-)\s*dopo/u', '/before\s*(and|&|\/)\s*after/u']],
        'weight_loss' => ['severity' => 'warn', 'source' => 'health-wellness', 'title' => 'Dimagrimento e salute',
            'why' => 'Prodotti e servizi per perdere peso o per la salute vanno mostrati solo a persone dai 18 anni in su e senza promesse di risultati.',
            'fix' => 'Togli numeri e tempi ("-5 kg in 2 settimane"), punta su benessere e metodo; imposta l\'età minima a 18.',
            'patterns' => ['/\bperd(i|ere|ete)\s+\d+\s*(kg|chili|taglie)/u', '/\bdimagri(sci|re|te)\s+(in|di)\s+\d+/u', '/bruciagrassi|pancia piatta in|perdere peso velocemente|dieta miracol/u']],
        'unrealistic_claims' => ['severity' => 'block', 'source' => 'commercial-practices', 'title' => 'Promesse irrealistiche',
            'why' => 'Guadagni facili, guarigioni o risultati garantiti sono pratiche commerciali vietate da Meta.',
            'fix' => 'Scrivi cosa offre davvero il cliente e come funziona, senza garanzie di risultato.',
            'patterns' => ['/guadagn\w*\s+(fino a\s+)?\d[\d.]*\s*(€|euro)\s*(al|a|ogni)\s*(giorno|settimana|mese)/u', '/diventa(re)? ricc|soldi facili|reddito garantito|guadagno garantito|guarigione garantita|cura definitiva|miracolos|100\s?% garantit|risultati garantiti|senza alcuno sforzo/u']],
        'alcohol' => ['severity' => 'warn', 'source' => 'alcohol', 'title' => 'Alcolici',
            'why' => 'Le inserzioni che parlano di alcolici si possono fare, ma solo per maggiorenni e rispettando la legge italiana (niente inviti all\'abuso, niente minori).',
            'fix' => 'Imposta l\'età minima a 18 anni e non associare l\'alcol a guida, sport o successo.',
            'patterns' => ['/\b(vino|vini|birr[ae]|cocktail|spritz|liquor[ei]|amar[oi]|grapp[ae]|prosecco|aperitiv[oi]|gin|vodka|rum|whisk(e)?y|bollicine|degustazion[ei] di vin)/u']],
        'gambling' => ['severity' => 'block', 'source' => 'gambling', 'title' => 'Giochi e scommesse',
            'why' => 'In Italia la pubblicità del gioco con vincite in denaro è vietata (Decreto Dignità, 2018) e Meta chiede comunque un\'autorizzazione scritta.',
            'fix' => 'Non sponsorizzare giochi, scommesse, slot o lotterie a pagamento.',
            'patterns' => ['/scommess|casin[oò]\b|\bslot\b|gratta e vinci|poker online|bookmaker|\bbingo\b|giri gratis|bonus di benvenuto/u']],
        'tobacco' => ['severity' => 'block', 'source' => 'tobacco', 'title' => 'Tabacco e sigarette elettroniche',
            'why' => 'Meta non accetta inserzioni che vendono o promuovono tabacco, sigarette elettroniche e prodotti con nicotina.',
            'fix' => 'Non sponsorizzare questi prodotti: si possono comunicare solo con i post normali, dove la legge lo consente.',
            'patterns' => ['/sigarett|tabacc|\bsvap|e-cig|sigar[oi]\b|nicotina|tabaccheria/u']],
        'weapons' => ['severity' => 'warn', 'source' => 'weapons', 'title' => 'Armi e munizioni',
            'why' => 'Meta vieta inserzioni che vendono armi, munizioni o esplosivi; sono ammessi solo alcuni accessori, per maggiorenni.',
            'fix' => 'Se il cliente è un\'armeria, sponsorizza solo corsi di sicurezza o accessori consentiti, con età minima 18.',
            'patterns' => ['/\b(armi|arma da fuoco|munizioni|fucil[ei]|pistol[ae] (calibro|semiautomatic)|esplosiv[io])\b/u']],
        'drugs' => ['severity' => 'warn', 'source' => 'drugs', 'title' => 'Farmaci',
            'why' => 'In Italia i farmaci con ricetta non si pubblicizzano al pubblico e Meta chiede un\'autorizzazione per farmacie online e prodotti farmaceutici.',
            'fix' => 'Per le farmacie sponsorizza servizi (misurazione pressione, prenotazioni) e prodotti da banco consentiti.',
            'patterns' => ['/\bfarmac[oi]\b|ricetta medica|senza ricetta|antibiotic|viagra|cialis|ozempic/u']],
        'crypto' => ['severity' => 'warn', 'source' => 'crypto', 'title' => 'Criptovalute',
            'why' => 'Le inserzioni su criptovalute, exchange e trading richiedono un\'autorizzazione scritta di Meta.',
            'fix' => 'Senza autorizzazione, non sponsorizzare prodotti cripto.',
            'patterns' => ['/cripto|crypto|bitcoin|ethereum|\bnft\b|exchange di/u']],
        'dating' => ['severity' => 'warn', 'source' => 'dating', 'title' => 'Incontri',
            'why' => 'I servizi di incontri richiedono un\'autorizzazione scritta di Meta e solo pubblico maggiorenne.',
            'fix' => 'Senza autorizzazione, non sponsorizzare servizi di incontri.',
            'patterns' => ['/app di incontri|siti? di incontri|anima gemella|chat con single|incontra single/u']],
        'financial' => ['severity' => 'warn', 'source' => 'financial', 'title' => 'Servizi finanziari',
            'why' => 'Prestiti, mutui, carte e investimenti sono una categoria speciale: va dichiarata su Meta, altrimenti l\'inserzione viene respinta.',
            'fix' => 'Scegli la categoria speciale "Prodotti e servizi finanziari" e non promettere approvazioni sicure o rendimenti.',
            'special' => 'financial',
            'patterns' => ['/prestit[oi]|mutu[oi]\b|finanziament|carta di credito|cessione del quinto|tasso (zero|agevolato|fisso|variabile)|investiment[oi]|trading|assicurazion[ei]/u']],
        'employment' => ['severity' => 'warn', 'source' => 'special-categories', 'title' => 'Offerte di lavoro',
            'why' => 'Le offerte di lavoro sono una categoria speciale: va dichiarata su Meta e il pubblico non può essere scelto per età o genere.',
            'fix' => 'Scegli la categoria speciale "Lavoro" e scrivi un annuncio aperto a tutti ("cerchiamo una persona", non "cerchiamo ragazza giovane").',
            'special' => 'employment',
            'patterns' => ['/cerchiamo personale|stiamo assumendo|offerta di lavoro|posizion[ie] apert|lavora con noi|assunzion|cerchiamo (un|una|due|tre)\s+\w+\s*(da inserire|per il nostro)/u']],
        'housing' => ['severity' => 'warn', 'source' => 'special-categories', 'title' => 'Immobili',
            'why' => 'Case in vendita o in affitto sono una categoria speciale: va dichiarata su Meta e il pubblico ha opzioni limitate.',
            'fix' => 'Scegli la categoria speciale "Immobili".',
            'special' => 'housing',
            'patterns' => ['/(appartament[oi]|cas[ae]|vill[ae]|bilocal[ei]|trilocal[ei]|monolocal[ei])\s+(in\s+)?(vendita|affitto)|immobiliar|affittasi|vendesi/u']],
        'politics' => ['severity' => 'block', 'source' => 'siep', 'title' => 'Politica e temi sociali',
            'why' => 'Dall\'ottobre 2025 Meta non pubblica più inserzioni su politica, elezioni e temi sociali nell\'Unione Europea.',
            'fix' => 'Pubblica il contenuto come post normale, senza sponsorizzarlo.',
            'special' => 'social_issues',
            'patterns' => ['/elezion|\bvota(te)?\b|candidat[oa] sindaco|\bpartito\b|referendum|campagna elettorale|firma la petizione|consiglio comunale/u']],
        'engagement_bait' => ['severity' => 'warn', 'source' => 'ad-standards', 'title' => 'Richieste di like e condivisioni',
            'why' => 'Chiedere esplicitamente like, tag o commenti è considerato "engagement bait": Meta riduce la diffusione dell\'inserzione.',
            'fix' => 'Invita a fare un\'azione utile (prenotare, scrivere, scoprire) invece di chiedere like.',
            'patterns' => ['/metti (un )?mi piace|lascia un like|condividi (questo|il) post|tagga (un|una|i tuoi|le tue) amic|commenta (con|per|sì|si)\b|scrivi .{0,20} nei commenti/u']],
        'meta_brand' => ['severity' => 'block', 'source' => 'meta-brand', 'title' => 'Marchi di Meta',
            'why' => 'Non si può far credere che Facebook, Instagram o Meta approvino o sponsorizzino il cliente.',
            'fix' => 'Togli frasi come "approvato da Instagram"; scrivi i nomi con l\'iniziale maiuscola e senza modificarne i loghi.',
            'patterns' => ['/(approvat|sponsorizzat|certificat|consigliat|partner ufficiale)\w*\s+(da|di)\s+(facebook|instagram|meta|whatsapp)/u']],
        'profanity' => ['severity' => 'warn', 'source' => 'profanity', 'title' => 'Parolacce',
            'why' => 'Le parolacce, anche camuffate con simboli, possono far respingere l\'inserzione.',
            'fix' => 'Togli o sostituisci le parole volgari.',
            'patterns' => ['/cazz|stronz|vaffanc|\bmerd[ae]\b|minchi|coglion|\bfiga\b|c\*+o\b|m\*+a\b/u']],
        'adult' => ['severity' => 'warn', 'source' => 'adult', 'title' => 'Contenuti per adulti',
            'why' => 'Riferimenti sessuali o nudità non sono ammessi; anche l\'intimo va mostrato senza pose allusive.',
            'fix' => 'Usa un linguaggio neutro e immagini non allusive.',
            'patterns' => ['/\b(sexy|hot|erotic\w*|porno|nud[oaie]|sesso|bollent[ei])\b/u']],
        'crisis' => ['severity' => 'warn', 'source' => 'ad-standards', 'title' => 'Crisi ed eventi tragici',
            'why' => 'Meta vieta di sfruttare commercialmente crisi, disastri o eventi tragici.',
            'fix' => 'Non legare l\'offerta a emergenze, guerre o calamità.',
            'patterns' => ['/covid|coronavirus|pandemia|guerra|terremoto|alluvione|emergenza sanitaria/u']],
    ];

    /** Categorie che richiedono un pubblico maggiorenne. */
    private const ADULT_ONLY = ['alcohol', 'weight_loss', 'dating', 'weapons', 'financial'];

    /** Interessi su temi delicati che Meta ha tolto dal pubblico dettagliato nel 2022. */
    private const SENSITIVE_INTERESTS = '/salute|malatti|cancro|diabet|religion|cristian|musulman|ebre|politic|partito|orientamento|gay|lgbt|etnia|razza|sessual/u';

    /** Le regole in forma leggibile, per il pannello "Regole Meta". */
    public static function rulebook(): array
    {
        $rules = [];
        foreach (self::TEXT_RULES as $key => $r) {
            $rules[] = ['key' => $key, 'severity' => $r['severity'], 'title' => $r['title'], 'why' => $r['why'], 'fix' => $r['fix'], 'source' => self::sourceUrl($r['source'])];
        }
        $objectives = [];
        foreach (self::OBJECTIVES as $key => $o) $objectives[] = ['key' => $key, 'label' => $o['label'], 'icon' => $o['icon'], 'when' => $o['when'], 'ctas' => $o['ctas'], 'needs_url' => $o['needs_url']];
        $categories = [];
        foreach (self::SPECIAL_CATEGORIES as $key => $c) $categories[] = ['key' => $key] + $c;
        return [
            'version' => self::VERSION,
            'objectives' => $objectives,
            'ctas' => array_map(fn($code, $label) => ['code' => $code, 'label' => $label], array_keys(self::CTAS), self::CTAS),
            'special_categories' => $categories,
            'text_limits' => self::TEXT_LIMITS,
            'specs' => self::SPECS,
            'budget' => ['min_daily' => self::MIN_DAILY_BUDGET, 'suggested_daily' => self::SUGGESTED_DAILY_BUDGET],
            'music' => [
                'sources' => array_map(fn($key, $s) => ['key' => $key] + $s, array_keys(self::MUSIC_SOURCES), self::MUSIC_SOURCES),
                'moods' => array_map(fn($key, $m) => ['key' => $key] + $m, array_keys(self::MUSIC_MOODS), self::MUSIC_MOODS),
                'by_objective' => self::MUSIC_BY_OBJECTIVE,
            ],
            'rules' => $rules,
        ];
    }

    /**
     * Controlla una sponsorizzata prima di caricarla su Meta. Non chiama servizi esterni: è istantaneo.
     * @param array $ad objective, special_category, audience{age_min, age_max, genders, interests, location}, daily_budget, start_date, end_date,
     *                  primary_text, headline, description, cta, url, music{source, asset_id, track_name, rights_confirmed}
     * @return array{verdict: string, issues: list<array{key: string, severity: string, title: string, why: string, fix: string, excerpt: string, source: ?string}>, suggested_category: ?string}
     */
    public static function check(array $ad): array
    {
        $issues = [];
        $add = function (string $key, string $severity, string $title, string $why, string $fix, string $excerpt = '', ?string $source = null) use (&$issues): void {
            $issues[] = compact('key', 'severity', 'title', 'why', 'fix', 'excerpt') + ['source' => $source ? self::sourceUrl($source) : null];
        };
        $primary = trim((string)($ad['primary_text'] ?? ''));
        $text = implode("\n", array_filter([$primary, trim((string)($ad['headline'] ?? '')), trim((string)($ad['description'] ?? ''))]));
        $lower = mb_strtolower($text);
        $audience = is_array($ad['audience'] ?? null) ? $ad['audience'] : [];
        $ageMin = (int)($audience['age_min'] ?? 18);
        $ageMax = (int)($audience['age_max'] ?? 65);
        $special = $ad['special_category'] ?? 'none';
        $objective = $ad['objective'] ?? 'traffic';

        $matched = [];
        $suggested = null;
        foreach (self::TEXT_RULES as $key => $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (!preg_match($pattern, $lower, $m, PREG_OFFSET_CAPTURE)) continue;
                $matched[$key] = true;
                $excerpt = self::excerpt($text, $lower, (int)$m[0][1], strlen($m[0][0]));
                $categoryRule = $rule['special'] ?? null;
                if ($categoryRule && $categoryRule !== 'social_issues') {
                    // Le categorie speciali sono un problema solo se non sono state dichiarate.
                    $suggested ??= $special === 'none' ? $categoryRule : null;
                    if ($special === $categoryRule) break;
                }
                $add($key, $rule['severity'], $rule['title'], $rule['why'], $rule['fix'], $excerpt, $rule['source']);
                break;
            }
        }

        if ($special === 'social_issues' && !isset($matched['politics'])) {
            $r = self::TEXT_RULES['politics'];
            $add('politics', 'block', $r['title'], $r['why'], $r['fix'], '', $r['source']);
        }
        if ($ageMin < 18) {
            foreach (self::ADULT_ONLY as $key) {
                if (!isset($matched[$key])) continue;
                $add("{$key}_age", 'block', 'Età minima 18 anni', 'Questo tipo di inserzione si può mostrare solo a maggiorenni.', 'Nel pubblico imposta l\'età minima a 18.', '', self::TEXT_RULES[$key]['source']);
                break;
            }
            $add('teen_targeting', 'tip', 'Pubblico sotto i 18 anni', 'Per i minorenni Meta usa solo età e posizione: interessi e genere vengono ignorati.', 'Se il prodotto non è per ragazzi, parti da 18 anni.', '', 'ad-standards');
        }
        if (in_array($special, ['financial', 'employment', 'housing'], true)) {
            $genders = $audience['genders'] ?? 'all';
            if ($ageMin !== 18 || $ageMax < 65 || $genders !== 'all') {
                $add('special_audience', 'warn', 'Pubblico non modificabile', 'Con una categoria speciale Meta non permette di scegliere età e genere.', 'Lascia età 18-65+ e tutti i generi: Meta li imposterà comunque così.', '', 'special-categories');
            }
        }
        $interests = mb_strtolower(implode(', ', array_map('strval', (array)($audience['interests'] ?? []))));
        if ($interests !== '' && preg_match(self::SENSITIVE_INTERESTS, $interests, $m)) {
            $add('sensitive_interests', 'warn', 'Interessi su temi delicati', 'Dal 2022 Meta non permette di scegliere il pubblico per salute, religione, politica, orientamento sessuale o origine.', 'Togli questi interessi: scegli passioni e abitudini (es. "cucina", "running") oppure lascia fare a Meta (Advantage+).', $m[0], 'personal-attributes');
        }

        // Testi: quello principale è obbligatorio, gli altri hanno lunghezze consigliate.
        if ($primary === '') {
            $add('primary_missing', 'block', 'Manca il testo', 'Senza il testo principale l\'inserzione non si può creare.', 'Scrivi 1-3 frasi su cosa offre il cliente e perché sceglierlo.');
        } elseif (mb_strlen($primary) > self::TEXT_LIMITS['primary_text']) {
            $add('primary_long', 'tip', 'Testo lungo', 'Oltre i ' . self::TEXT_LIMITS['primary_text'] . ' caratteri Meta taglia il testo con "Altro": la parte importante deve stare all\'inizio.', 'Metti offerta e motivo per cliccare nella prima frase.');
        }
        foreach (['headline' => 'Titolo', 'description' => 'Descrizione'] as $field => $label) {
            $len = mb_strlen(trim((string)($ad[$field] ?? '')));
            if ($len > self::TEXT_LIMITS[$field]) {
                $add("{$field}_long", 'tip', "{$label} lungo", "Oltre i " . self::TEXT_LIMITS[$field] . " caratteri può essere tagliato su telefono.", 'Accorcialo a poche parole chiare.');
            }
        }
        if (preg_match('/\[[^\]\n]{2,40}\]/u', $text, $m)) {
            $add('placeholder', 'block', 'Segnaposto da completare', 'Nel testo c\'è un segnaposto lasciato dall\'AI.', 'Sostituiscilo con il dato vero o toglilo.', $m[0]);
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $text);
        $upper = preg_replace('/[^\p{Lu}]/u', '', $text);
        if (mb_strlen($letters) >= 20 && mb_strlen($upper) / mb_strlen($letters) > 0.4 || preg_match('/[!?]{3,}|€{2,}/u', $text)) {
            $add('shouting', 'tip', 'Troppe maiuscole o punti esclamativi', 'Meta penalizza i testi che "urlano" e possono sembrare spam.', 'Usa le maiuscole solo a inizio frase e un solo punto esclamativo.');
        }
        if (preg_match_all('/#[\p{L}\d_]+/u', $text) > 3) {
            $add('hashtags', 'tip', 'Troppi hashtag', 'Nelle sponsorizzate gli hashtag portano via le persone dall\'inserzione.', 'Tienine al massimo 2-3, o nessuno.');
        }

        // Link, obiettivo e pulsante.
        $url = trim((string)($ad['url'] ?? ''));
        if ($url === '' && (self::OBJECTIVES[$objective]['needs_url'] ?? false)) {
            $add('url_missing', 'block', 'Manca il link', 'Con l\'obiettivo "' . self::OBJECTIVES[$objective]['label'] . '" serve la pagina dove arrivano le persone.', 'Aggiungi il link del sito o della pagina prodotto.');
        } elseif ($url !== '' && !str_starts_with(mb_strtolower($url), 'https://')) {
            $add('url_http', 'warn', 'Link non sicuro', 'Le pagine senza https mostrano "Non sicuro" e convertono meno.', 'Usa l\'indirizzo che inizia con https://.', $url);
        }
        if ($objective === 'sales') {
            $add('pixel', 'tip', 'Pixel di Meta', 'Per l\'obiettivo Vendite Meta deve vedere gli acquisti sul sito.', 'Controlla nell\'analisi del sito del cliente che il Pixel di Meta sia installato.');
        }
        $cta = (string)($ad['cta'] ?? '');
        if ($cta !== '' && !isset(self::CTAS[$cta])) {
            $add('cta_unknown', 'block', 'Pulsante non valido', 'Meta accetta solo i pulsanti del suo elenco.', 'Scegli un pulsante dall\'elenco.');
        }

        // Musica: nelle inserzioni Meta accetta solo brani con licenza per uso commerciale.
        $music = is_array($ad['music'] ?? null) ? $ad['music'] : [];
        $source = $music['source'] ?? 'meta';
        if ($source === 'own') {
            $track = trim((string)($music['track_name'] ?? ''));
            if (empty($music['asset_id'])) {
                $add('music_missing', 'block', 'Manca la traccia', 'Hai scelto una traccia vostra ma non l\'hai ancora selezionata.', 'Scegli o carica una traccia, oppure usa la musica di Meta.');
            } elseif (preg_match(self::COMMERCIAL_TRACK, mb_strtolower($track))) {
                $add('music_commercial', 'warn', 'Sembra un brano commerciale', 'Dal nome del file sembra una canzone famosa o scaricata da YouTube o Spotify: Meta riconosce questi brani e toglie l\'audio o blocca l\'inserzione.', 'Usa un brano libero da diritti o la musica di Meta, già autorizzata.', $track);
            }
            if (empty($music['rights_confirmed'])) {
                $add('music_rights', 'block', 'Diritti della musica da confermare', 'Nelle inserzioni Meta accetta solo musica con licenza per uso commerciale: senza, toglie l\'audio o rifiuta l\'inserzione e il titolare dei diritti può segnalarla.', 'Conferma di avere la licenza (o che il brano è libero da diritti), oppure scegli la musica di Meta.');
            }
        } elseif ($source === 'none' && in_array($objective, ['awareness', 'engagement'], true)) {
            $add('music_none', 'tip', 'Senza musica', 'Nei Reels e nelle Storie quasi tutti guardano con l\'audio acceso: con la musica l\'inserzione si fa notare di più.', 'Scegli «Musica di Meta»: è gratis e già autorizzata.');
        }

        // Budget e durata.
        $budget = (float)($ad['daily_budget'] ?? 0);
        if ($budget < self::MIN_DAILY_BUDGET) {
            $add('budget_min', 'block', 'Budget troppo basso', 'Meta chiede almeno ' . self::MIN_DAILY_BUDGET . ' € al giorno.', 'Alza il budget giornaliero.');
        } elseif ($budget < self::SUGGESTED_DAILY_BUDGET) {
            $add('budget_low', 'tip', 'Budget basso', 'Con meno di ' . (int)self::SUGGESTED_DAILY_BUDGET . ' € al giorno Meta fatica a finire la fase di apprendimento (circa 50 risultati in una settimana).', 'Meglio meno giorni con un budget più alto, oppure obiettivo Notorietà.');
        }
        if (!empty($ad['start_date']) && !empty($ad['end_date'])) {
            $days = (int)((strtotime($ad['end_date']) - strtotime($ad['start_date'])) / 86400) + 1;
            if ($days > 0 && $days < 4) {
                $add('short_run', 'tip', 'Durata breve', 'Nei primi giorni Meta sta ancora imparando a chi mostrare l\'inserzione.', 'Se puoi, lasciala attiva almeno 7 giorni.');
            }
        }

        $order = ['block' => 0, 'warn' => 1, 'tip' => 2];
        usort($issues, fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);
        $severities = array_column($issues, 'severity');
        $verdict = in_array('block', $severities, true) ? 'blocked' : (in_array('warn', $severities, true) ? 'warning' : 'ok');
        return ['verdict' => $verdict, 'issues' => $issues, 'suggested_category' => $suggested, 'version' => self::VERSION];
    }

    /** Le regole del controllo in testo, da dare all'AI per la revisione approfondita. */
    public static function promptText(): string
    {
        $lines = [];
        foreach (self::TEXT_RULES as $r) $lines[] = "- {$r['title']} (" . ['block' => 'vietato', 'warn' => 'limitato', 'tip' => 'consiglio'][$r['severity']] . "): {$r['why']}";
        foreach (self::SPECIAL_CATEGORIES as $key => $c) if ($key !== 'none') $lines[] = "- Categoria speciale «{$c['label']}»: {$c['detail']}";
        $lines[] = '- Testi consigliati: testo principale fino a ' . self::TEXT_LIMITS['primary_text'] . ' caratteri, titolo fino a ' . self::TEXT_LIMITS['headline'] . ', descrizione fino a ' . self::TEXT_LIMITS['description'] . '.';
        $lines[] = '- Nessun riferimento a qualità personali di chi legge, nessuna promessa irrealistica, nessuna immagine prima/dopo, nessun uso dei marchi di Meta come approvazione.';
        $lines[] = '- La pagina di destinazione deve funzionare, corrispondere all\'inserzione e non essere ingannevole.';
        $lines[] = '- La musica deve avere la licenza per uso commerciale: va bene la libreria musicale di Meta, non le canzoni famose senza licenza.';
        return implode("\n", $lines);
    }

    private static function sourceUrl(string $slug): ?string
    {
        return MetaPolicyMonitor::SOURCES[$slug]['url'] ?? null;
    }

    /** Il pezzo di testo intorno a ciò che ha fatto scattare la regola, con le maiuscole originali. */
    private static function excerpt(string $text, string $lower, int $byteOffset, int $byteLength): string
    {
        // Le posizioni sono in byte sul testo minuscolo: si convertono in caratteri per tagliare il testo originale.
        $start = mb_strlen(substr($lower, 0, $byteOffset));
        $length = mb_strlen(substr($lower, $byteOffset, $byteLength));
        $from = max(0, $start - 25);
        $snippet = mb_substr($text, $from, $length + 50);
        return trim(($from > 0 ? '…' : '') . preg_replace('/\s+/u', ' ', $snippet) . ($from + $length + 50 < mb_strlen($text) ? '…' : ''));
    }
}
