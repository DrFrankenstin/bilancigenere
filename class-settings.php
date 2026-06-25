<?php
/**
 * Pagina Settings del plugin Vulcanica Comuni Manager
 *
 * Sezioni configurabili:
 *  - Google Gemini API (chiave, modello, parametri generazione)
 *  - Opzioni generali (form ID, CPT slug)
 *
 * Opzioni salvate in wp_options:
 *  vulcanica_gemini_api_key       string
 *  vulcanica_gemini_model         string  (default: gemini-3-flash-preview)
 *  vulcanica_gemini_max_tokens    int     (default: 14000)
 *  vulcanica_gemini_temperature   float   (default: 0.7)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VulcanicaSettings {

    const OPTION_GROUP   = 'vulcanica_settings';
    const PAGE_SLUG      = 'vulcanica-settings';
    const CAPABILITY     = 'manage_options';

    // Modelli Gemini disponibili (aggiornato 2026-05)
    const MODELS = [
        // ── Gemini 3.1 (Ultima generazione) ────────────────────────────────
        'gemini-3.1-pro-preview'      => 'Gemini 3.1 Pro Preview (massima qualità)',
        'gemini-3-flash-preview'      => 'Gemini 3.1 Flash Preview (equilibrato)',
        'gemini-3.1-flash-lite'       => 'Gemini 3.1 Flash Lite (veloce, economico)',

        // ── Gemini 2.5 (Precedente) ────────────────────────────────────────
        'gemini-2.5-pro'              => 'Gemini 2.5 Pro (qualità, multimodale)',
        'gemini-2.5-flash'            => 'Gemini 2.5 Flash (veloce, bilancato)',
        'gemini-2.5-flash-lite'       => 'Gemini 2.5 Flash Lite (velocissimo, leggero)',
    ];

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );
        add_action( 'wp_ajax_vulcanica_test_api', [ __CLASS__, 'ajax_test_api' ] );
    }

    // =========================================================================
    // MENU
    // =========================================================================

    public static function add_menu_page() {
        add_submenu_page(
            'vulcanica_menu',                    // slug del menu padre (già registrato dal plugin)
            'Vulcanica — Impostazioni',          // titolo pagina
            'Impostazioni',                      // voce nel sottomenu
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // =========================================================================
    // REGISTRAZIONE SETTINGS (WordPress Settings API)
    // =========================================================================

    public static function register_settings() {

        // ── Sezione: Gemini API ───────────────────────────────────────────────
        add_settings_section(
            'vulcanica_section_gemini',
            'Google Gemini API',
            [ __CLASS__, 'render_section_gemini' ],
            self::PAGE_SLUG
        );

        // API Key
        register_setting( self::OPTION_GROUP, 'vulcanica_gemini_api_key', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_api_key' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_gemini_api_key',
            'Chiave API',
            [ __CLASS__, 'render_field_api_key' ],
            self::PAGE_SLUG,
            'vulcanica_section_gemini'
        );

        // Modello
        register_setting( self::OPTION_GROUP, 'vulcanica_gemini_model', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_model' ],
            'default'           => 'gemini-3-flash-preview',  // Equilibrato e veloce per Gemini 3.1
        ] );
        add_settings_field(
            'vulcanica_gemini_model',
            'Modello',
            [ __CLASS__, 'render_field_model' ],
            self::PAGE_SLUG,
            'vulcanica_section_gemini'
        );

        // Max tokens
        register_setting( self::OPTION_GROUP, 'vulcanica_gemini_max_tokens', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
            'default'           => 14000,
        ] );
        add_settings_field(
            'vulcanica_gemini_max_tokens',
            'Token massimi risposta',
            [ __CLASS__, 'render_field_max_tokens' ],
            self::PAGE_SLUG,
            'vulcanica_section_gemini'
        );

        // Temperature
        register_setting( self::OPTION_GROUP, 'vulcanica_gemini_temperature', [
            'type'              => 'number',
            'sanitize_callback' => [ __CLASS__, 'sanitize_temperature' ],
            'default'           => 0.7,
        ] );
        add_settings_field(
            'vulcanica_gemini_temperature',
            'Temperatura (creatività)',
            [ __CLASS__, 'render_field_temperature' ],
            self::PAGE_SLUG,
            'vulcanica_section_gemini'
        );

        // ── Sezione: Prompt AI ────────────────────────────────────────────────
        add_settings_section(
            'vulcanica_section_prompts',
            'Configurazione Prompt AI',
            [ __CLASS__, 'render_section_prompts' ],
            self::PAGE_SLUG
        );

        // System prompt
        register_setting( self::OPTION_GROUP, 'vulcanica_system_prompt', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_system_prompt',
            'System Prompt (istruzioni di sistema)',
            [ __CLASS__, 'render_field_system_prompt' ],
            self::PAGE_SLUG,
            'vulcanica_section_prompts'
        );

        // Build prompt (istruzioni di analisi)
        register_setting( self::OPTION_GROUP, 'vulcanica_build_prompt', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_build_prompt',
            'Istruzioni per l\'Analisi',
            [ __CLASS__, 'render_field_build_prompt' ],
            self::PAGE_SLUG,
            'vulcanica_section_prompts'
        );

        // PDF Context prompt (istruzioni per contesto storico)
        register_setting( self::OPTION_GROUP, 'vulcanica_pdf_context_prompt', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_pdf_context_prompt',
            'Istruzioni Contesto Storico (PDF)',
            [ __CLASS__, 'render_field_pdf_context_prompt' ],
            self::PAGE_SLUG,
            'vulcanica_section_prompts'
        );

        // Prompt analisi preprocessing (usato da "Analizza PDF Storici")
        register_setting( self::OPTION_GROUP, 'vulcanica_preprocessing_prompt', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_preprocessing_prompt',
            'Prompt Analisi Storica (Preprocessing)',
            [ __CLASS__, 'render_field_preprocessing_prompt' ],
            self::PAGE_SLUG,
            'vulcanica_section_prompts'
        );

        // Prompt pre-analisi CSV dati quantitativi (allegato form)
        register_setting( self::OPTION_GROUP, 'vulcanica_csv_pre_analysis_prompt', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_csv_pre_analysis_prompt',
            'Prompt Pre-analisi CSV (Dati Personale)',
            [ __CLASS__, 'render_field_csv_pre_analysis_prompt' ],
            self::PAGE_SLUG,
            'vulcanica_section_prompts'
        );

        // Prompt pre-analisi PDF bilanci comunali (allegato form)
        register_setting( self::OPTION_GROUP, 'vulcanica_pdf_comunale_pre_analysis_prompt', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_pdf_comunale_pre_analysis_prompt',
            'Prompt Pre-analisi PDF Bilanci Comunali',
            [ __CLASS__, 'render_field_pdf_comunale_pre_analysis_prompt' ],
            self::PAGE_SLUG,
            'vulcanica_section_prompts'
        );

        // ── Sezione: Testi Fissi del Documento ───────────────────────────────────
        add_settings_section(
            'vulcanica_section_placeholders',
            'Testi Fissi del Documento',
            [ __CLASS__, 'render_section_placeholders' ],
            self::PAGE_SLUG
        );

        // Testo fisso: sezione cap1_1 — Quadro Normativo
        register_setting( self::OPTION_GROUP, 'vulcanica_quadro_normativo', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_quadro_normativo',
            'Sezione <code>id="cap1_1"</code> — Quadro Normativo',
            [ __CLASS__, 'render_field_quadro_normativo' ],
            self::PAGE_SLUG,
            'vulcanica_section_placeholders'
        );

        // Testo fisso: sezione cap1_2 — Metodologia
        register_setting( self::OPTION_GROUP, 'vulcanica_metodologia', [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_textarea' ],
            'default'           => '',
        ] );
        add_settings_field(
            'vulcanica_metodologia',
            'Sezione <code>id="cap1_2"</code> — Metodologia',
            [ __CLASS__, 'render_field_metodologia' ],
            self::PAGE_SLUG,
            'vulcanica_section_placeholders'
        );

        // ── Sezione: PDF ──────────────────────────────────────────────────────
        add_settings_section(
            'vulcanica_section_pdf',
            'Gestione PDF Bilanci Storici',
            [ __CLASS__, 'render_section_pdf' ],
            self::PAGE_SLUG
        );

        // Dimensione massima PDF (MB)
        register_setting( self::OPTION_GROUP, 'vulcanica_pdf_max_size_mb', [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_pdf_max_size' ],
            'default'           => 20,
        ] );
        add_settings_field(
            'vulcanica_pdf_max_size_mb',
            'Dimensione massima PDF',
            [ __CLASS__, 'render_field_pdf_max_size' ],
            self::PAGE_SLUG,
            'vulcanica_section_pdf'
        );

        // Auto-invalidazione analisi storica
        register_setting( self::OPTION_GROUP, 'vulcanica_auto_invalidate_analysis', [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => false,
        ] );
        add_settings_field(
            'vulcanica_auto_invalidate_analysis',
            'Auto-cancella analisi storica',
            [ __CLASS__, 'render_field_auto_invalidate_analysis' ],
            self::PAGE_SLUG,
            'vulcanica_section_pdf'
        );

        // ── Sezione: Generali ─────────────────────────────────────────────────
        add_settings_section(
            'vulcanica_section_general',
            'Impostazioni Generali',
            [ __CLASS__, 'render_section_general' ],
            self::PAGE_SLUG
        );

        // Form ID bilancio
        register_setting( self::OPTION_GROUP, 'vulcanica_bilancio_form_id', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 8,
        ] );
        add_settings_field(
            'vulcanica_bilancio_form_id',
            'ID Form Bilancio di Genere',
            [ __CLASS__, 'render_field_form_id' ],
            self::PAGE_SLUG,
            'vulcanica_section_general'
        );
    }

    // =========================================================================
    // SANITIZZAZIONE
    // =========================================================================

    public static function sanitize_api_key( $value ) {
        $clean = sanitize_text_field( trim( $value ) );
        // Accetta chiavi nel formato Google (AIza...)
        if ( ! empty( $clean ) && ! preg_match( '/^AIza[0-9A-Za-z\-_]{35,}$/', $clean ) ) {
            add_settings_error(
                'vulcanica_gemini_api_key',
                'invalid_key',
                'La chiave API non sembra valida (deve iniziare con "AIza").',
                'warning'
            );
        }
        return $clean;
    }

    public static function sanitize_model( $value ) {
        return array_key_exists( $value, self::MODELS ) ? $value : 'gemini-3-flash-preview';
    }

    public static function sanitize_max_tokens( $value ) {
        $v = absint( $value );
        return max( 500, min( 65535, $v ) ); // Clamp 500–65535 (max per Gemini API)
    }

    public static function sanitize_temperature( $value ) {
        $v = floatval( $value );
        return max( 0.0, min( 2.0, round( $v, 1 ) ) ); // Clamp 0–2
    }

    public static function sanitize_textarea( $value ) {
        // Permette HTML e newline, ma rimuove script malevoli
        return wp_kses_post( $value );
    }

    public static function sanitize_pdf_max_size( $value ) {
        $v = absint( $value );
        return max( 1, min( 50, $v ) ); // Clamp 1–50 MB
    }

    public static function sanitize_checkbox( $value ) {
        return (bool) $value;
    }

    // =========================================================================
    // RENDER SEZIONI
    // =========================================================================

    public static function render_section_gemini() {
        echo '<p>Configura la connessione a Google Gemini API per la generazione automatica del Bilancio di Genere. '
           . 'Ottieni la tua chiave su <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>.</p>';
    }

    public static function render_section_general() {
        echo '<p>Impostazioni generali del plugin.</p>';
    }

    public static function render_section_pdf() {
        echo '<p>Configura i limiti per i PDF dei bilanci storici inviati a Gemini come contesto storico.</p>';
    }

    public static function render_field_pdf_max_size() {
        $value = self::get_pdf_max_size_mb();
        ?>
        <input type="number"
               name="vulcanica_pdf_max_size_mb"
               value="<?php echo esc_attr( $value ); ?>"
               min="1" max="50" step="1"
               style="width:80px">
        MB
        <p class="description">
            Dimensione massima consentita per ogni singolo file PDF caricato (1–50 MB).<br>
            File più grandi vengono rifiutati all'upload. Attuale limite: <strong><?php echo esc_html( $value ); ?> MB</strong>.
        </p>
        <?php
    }

    public static function render_field_auto_invalidate_analysis() {
        $value = self::get_auto_invalidate_analysis();
        ?>
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
            <input
                type="checkbox"
                name="vulcanica_auto_invalidate_analysis"
                value="1"
                <?php checked( $value, true ); ?>
                style="margin-top:3px;"
            />
            <span>
                Cancella automaticamente il riassunto analisi storica quando si modifica il set di PDF
                (upload, cambio stato bozza/pubblicato, eliminazione)
            </span>
        </label>
        <p class="description" style="margin-top:8px;">
            ⚠️ <strong>Disabilitato per default.</strong>
            Se hai personalizzato manualmente il riassunto, tienilo disabilitato per non perderlo
            ogni volta che pubblichi o elimini un PDF.
            Abilita solo se vuoi che il riassunto venga ricalcolato automaticamente ad ogni modifica.
        </p>
        <?php
    }

    public static function render_section_placeholders() {
        echo '<p>
            Testi <strong>fissi e personalizzati</strong> iniettati nel documento <em>dopo</em> la generazione Gemini.<br>
            <strong>Come funziona:</strong> Gemini genera il bilancio completo; poi PHP individua ogni sezione
            tramite l\'attributo <code>id</code> del titolo <code>&lt;h3&gt;</code> e <strong>sovrascrive</strong>
            il testo generato dall\'AI con il testo qui configurato — indipendentemente da cosa Gemini ha scritto.<br>
            Supporta HTML completo (<code>&lt;p&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;table&gt;</code>, ecc.).<br>
            Se un campo è vuoto, la sezione rimane con il testo generato dall\'AI.
        </p>';
    }

    public static function render_field_quadro_normativo() {
        $value = get_option( 'vulcanica_quadro_normativo', '' );
        ?>
        <div style="margin-bottom:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="display:inline-flex;align-items:center;gap:5px;background:#e8f0fe;border:1px solid #93b4f5;border-radius:3px;padding:3px 10px;font-size:11px;font-family:monospace;color:#1a56db;">
                🎯 <code style="background:none;color:inherit;">&lt;h3 id="cap1_1"&gt;</code>
            </span>
            <span style="font-size:12px;color:#555;">
                Il contenuto di questa sezione viene <strong>sostituito</strong> dal testo qui sotto dopo la generazione AI
            </span>
        </div>
        <textarea
            name="vulcanica_quadro_normativo"
            id="vulcanica_quadro_normativo"
            rows="12"
            cols="80"
            class="large-text"
            placeholder="Inserisci il testo HTML per la sezione 1.1 Quadro Normativo…"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <strong>wp_option:</strong> <code>vulcanica_quadro_normativo</code>
            &nbsp;·&nbsp;
            <?php if ( ! empty( $value ) ) : ?>
                <strong style="color:#28a745;">✅ Attivo</strong> — sostituirà il testo AI nella sezione 1.1 (<?php echo number_format( strlen( $value ) ); ?> caratteri)
            <?php else : ?>
                <em style="color:#999;">Vuoto — la sezione 1.1 verrà scritta interamente dall'AI</em>
            <?php endif; ?>
        </p>
        <?php
    }

    public static function render_field_metodologia() {
        $value = get_option( 'vulcanica_metodologia', '' );
        ?>
        <div style="margin-bottom:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="display:inline-flex;align-items:center;gap:5px;background:#e8f0fe;border:1px solid #93b4f5;border-radius:3px;padding:3px 10px;font-size:11px;font-family:monospace;color:#1a56db;">
                🎯 <code style="background:none;color:inherit;">&lt;h3 id="cap1_2"&gt;</code>
            </span>
            <span style="font-size:12px;color:#555;">
                Il contenuto di questa sezione viene <strong>sostituito</strong> dal testo qui sotto dopo la generazione AI
            </span>
        </div>
        <textarea
            name="vulcanica_metodologia"
            id="vulcanica_metodologia"
            rows="12"
            cols="80"
            class="large-text"
            placeholder="Inserisci il testo HTML per la sezione 1.2 Metodologia adottata…"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <strong>wp_option:</strong> <code>vulcanica_metodologia</code>
            &nbsp;·&nbsp;
            <?php if ( ! empty( $value ) ) : ?>
                <strong style="color:#28a745;">✅ Attivo</strong> — sostituirà il testo AI nella sezione 1.2 (<?php echo number_format( strlen( $value ) ); ?> caratteri)
            <?php else : ?>
                <em style="color:#999;">Vuoto — la sezione 1.2 verrà scritta interamente dall'AI</em>
            <?php endif; ?>
        </p>
        <?php
    }

    public static function render_section_prompts() {
        echo '<p>Personalizza i prompt che vengono inviati a Gemini AI. Questi definiscono come Gemini analizza e scrive il Bilancio di Genere.</p>';
    }

    // =========================================================================
    // RENDER CAMPI
    // =========================================================================

    public static function render_field_api_key() {
        $value = get_option( 'vulcanica_gemini_api_key', '' );
        // Mostra solo ultimi 6 caratteri se già salvata
        $display = ( strlen( $value ) > 6 )
            ? str_repeat( '•', strlen( $value ) - 6 ) . substr( $value, -6 )
            : $value;
        ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <input
                type="password"
                id="vulcanica_gemini_api_key"
                name="vulcanica_gemini_api_key"
                value="<?php echo esc_attr( $value ); ?>"
                class="regular-text"
                placeholder="AIzaSy..."
                autocomplete="off"
            />
            <button type="button" id="vulcanica-toggle-key" class="button button-secondary" style="min-width:80px;">
                Mostra
            </button>
            <button type="button" id="vulcanica-test-api" class="button button-secondary">
                🔌 Testa connessione
            </button>
            <span id="vulcanica-test-result" style="font-weight:600;"></span>
        </div>
        <p class="description">
            Tieni la chiave API riservata. Non condividerla mai in chat o email.
            <?php if ( ! empty( $value ) ) : ?>
                <br><em>Chiave attuale: <?php echo esc_html( $display ); ?></em>
            <?php endif; ?>
        </p>
        <?php
    }

    public static function render_field_model() {
        $current = get_option( 'vulcanica_gemini_model', 'gemini-2.0-flash' );
        echo '<select name="vulcanica_gemini_model" id="vulcanica_gemini_model">';
        foreach ( self::MODELS as $value => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">Gemini 2.0 Flash è il miglior compromesso velocità/qualità per i bilanci di genere.</p>';
    }

    public static function render_field_max_tokens() {
    	$value = intval( get_option( 'vulcanica_gemini_max_tokens', 65535 ) );
        printf(
            '<input type="number" name="vulcanica_gemini_max_tokens" value="%d" min="8192" class="small-text" />',
            $value
        );
        echo '<p class="description">Numero massimo di token nella risposta AI (500–65535). Bilanci complessi richiedono 14000–32000 token.</p>';
    }

    public static function render_field_temperature() {
        $value = floatval( get_option( 'vulcanica_gemini_temperature', 0.7 ) );
        printf(
            '<input type="number" name="vulcanica_gemini_temperature" value="%.1f" min="0" max="2" step="0.1" class="small-text" />',
            $value
        );
        echo '<p class="description">0 = deterministico e preciso | 1 = bilanciato | 2 = molto creativo. Consigliato: 0.7 per bilanci ufficiali.</p>';
    }

    public static function render_field_form_id() {
        $value = intval( get_option( 'vulcanica_bilancio_form_id', 8 ) );
        printf(
            '<input type="number" name="vulcanica_bilancio_form_id" value="%d" min="1" step="1" class="small-text" />',
            $value
        );
        echo '<p class="description">ID del form Formidable Forms per il Bilancio di Genere. Default: 8.</p>';
    }

    public static function render_field_system_prompt() {
        $value = get_option( 'vulcanica_system_prompt', self::get_default_system_prompt() );
        ?>
        <textarea
            name="vulcanica_system_prompt"
            id="vulcanica_system_prompt"
            rows="15"
            cols="80"
            class="large-text"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Istruzioni di sistema per Gemini. Definisce il ruolo e il comportamento dell'AI.
            Lascia vuoto per usare il default.
        </p>
        <?php
    }

    public static function render_field_build_prompt() {
        $value = get_option( 'vulcanica_build_prompt', self::get_default_build_prompt() );
        ?>
        <textarea
            name="vulcanica_build_prompt"
            id="vulcanica_build_prompt"
            rows="15"
            cols="80"
            class="large-text"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Istruzioni di analisi da eseguire sui dati del bilancio.
            Lascia vuoto per usare il default.
        </p>
        <?php
    }

    public static function render_field_pdf_context_prompt() {
        $value = get_option( 'vulcanica_pdf_context_prompt', '' );
        $default = self::get_default_pdf_context_prompt( [ [ 'filename' => 'esempio-1.pdf' ], [ 'filename' => 'esempio-2.pdf' ] ] );
        ?>
        <textarea
            name="vulcanica_pdf_context_prompt"
            id="vulcanica_pdf_context_prompt"
            rows="12"
            cols="80"
            class="large-text"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Istruzioni per il contesto storico quando sono allegati i PDF bilanci precedenti.
            Lascia vuoto per usare il default.
            <br><strong>Placeholder disponibili:</strong> {count} = numero file, {file_list} = elenco file
            <br><strong>Default:</strong><br><code><?php echo esc_html( nl2br( $default ) ); ?></code>
        </p>
        <?php
    }

    public static function render_field_preprocessing_prompt() {
        $value   = get_option( 'vulcanica_preprocessing_prompt', '' );
        $default = self::get_default_preprocessing_prompt();
        ?>
        <textarea
            name="vulcanica_preprocessing_prompt"
            id="vulcanica_preprocessing_prompt"
            rows="15"
            cols="80"
            class="large-text"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Prompt inviato a Gemini durante l'analisi storica ("Analizza PDF Storici" in <strong>Bilanci Storici</strong>).
            Viene usato per generare il riassunto strutturato dei bilanci precedenti, che sostituisce i PDF grezzi
            durante la generazione del bilancio (riducendo drasticamente i token).
            <br>Lascia vuoto per usare il default.
            <br><strong>wp_option:</strong> <code>vulcanica_preprocessing_prompt</code>
            <br><strong>Default:</strong><br>
            <pre style="background:#f6f7f7;padding:8px;border:1px solid #ddd;font-size:11px;max-height:160px;overflow-y:auto;"><?php echo esc_html( $default ); ?></pre>
        </p>
        <?php
    }

    public static function render_field_csv_pre_analysis_prompt() {
        $value   = get_option( 'vulcanica_csv_pre_analysis_prompt', '' );
        $default = self::get_default_csv_pre_analysis_prompt();
        ?>
        <textarea
            name="vulcanica_csv_pre_analysis_prompt"
            id="vulcanica_csv_pre_analysis_prompt"
            rows="15"
            cols="80"
            class="large-text"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Prompt inviato a Gemini per la pre-analisi del file CSV dei dati quantitativi del personale
            caricato nel form (campo <code>vcm_csv_dati</code>). Il risultato viene incluso come contesto
            aggiuntivo nella generazione del bilancio di genere.
            <br>Lascia vuoto per usare il default.
            <br><strong>wp_option:</strong> <code>vulcanica_csv_pre_analysis_prompt</code>
            <br><strong>Default:</strong><br>
            <pre style="background:#f6f7f7;padding:8px;border:1px solid #ddd;font-size:11px;max-height:160px;overflow-y:auto;"><?php echo esc_html( $default ); ?></pre>
        </p>
        <?php
    }

    public static function render_field_pdf_comunale_pre_analysis_prompt() {
        $value   = get_option( 'vulcanica_pdf_comunale_pre_analysis_prompt', '' );
        $default = self::get_default_pdf_comunale_pre_analysis_prompt();
        ?>
        <textarea
            name="vulcanica_pdf_comunale_pre_analysis_prompt"
            id="vulcanica_pdf_comunale_pre_analysis_prompt"
            rows="15"
            cols="80"
            class="large-text"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            Prompt inviato a Gemini per la pre-analisi dei PDF dei bilanci comunali generici
            caricati nel form (campi <code>vcm_pdf_bilancio_1</code> e <code>vcm_pdf_bilancio_2</code>).
            Il risultato viene incluso come contesto aggiuntivo nella generazione del bilancio di genere.
            <br>Lascia vuoto per usare il default.
            <br><strong>wp_option:</strong> <code>vulcanica_pdf_comunale_pre_analysis_prompt</code>
            <br><strong>Default:</strong><br>
            <pre style="background:#f6f7f7;padding:8px;border:1px solid #ddd;font-size:11px;max-height:160px;overflow-y:auto;"><?php echo esc_html( $default ); ?></pre>
        </p>
        <?php
    }

    // =========================================================================
    // RENDER PAGINA PRINCIPALE
    // =========================================================================

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( 'Accesso negato.' );
        }
        ?>
        <div class="wrap">
            <h1>
                <span style="font-size:1.4em;">⚖️</span>
                Bilancio di Genere — Impostazioni
            </h1>

            <?php settings_errors(); ?>

            <!-- Stato corrente -->
            <?php self::render_status_box(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( 'Salva impostazioni' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Box di stato in cima alla pagina: mostra se API key è configurata,
     * quanti CPT bilancio_genere esistono, stato del sistema.
     */
    private static function render_status_box() {
        $api_key   = get_option( 'vulcanica_gemini_api_key', '' );
        $has_key   = ! empty( $api_key );
        $model     = get_option( 'vulcanica_gemini_model', 'gemini-2.0-flash' );

        // Conta CPT generati
        $cpt_count = wp_count_posts( 'bilancio_genere' );
        $published = $cpt_count->publish ?? 0;
        $drafts    = $cpt_count->draft ?? 0;

        $status_color = $has_key ? '#00a32a' : '#d63638';
        $status_label = $has_key ? '✅ Configurata' : '❌ Non configurata';
        ?>
        <div style="
            background:#fff;
            border:1px solid #c3c4c7;
            border-left:4px solid <?php echo $status_color; ?>;
            padding:16px 20px;
            margin:16px 0 24px;
            border-radius:0 4px 4px 0;
            display:flex;
            gap:40px;
            flex-wrap:wrap;
            align-items:center;
        ">
            <div>
                <strong>API Gemini</strong><br>
                <span style="color:<?php echo $status_color; ?>;font-weight:600;"><?php echo $status_label; ?></span>
                <?php if ( $has_key ) : ?>
                    <br><small>Modello: <?php echo esc_html( $model ); ?></small>
                <?php endif; ?>
            </div>
            <div>
                <strong>Bilanci generati</strong><br>
                <?php echo intval( $published ); ?> pubblicati
                <?php if ( $drafts > 0 ) : ?>
                    · <?php echo intval( $drafts ); ?> bozze
                <?php endif; ?>
            </div>
            <div>
                <strong>Form ID</strong><br>
                <?php echo intval( get_option( 'vulcanica_bilancio_form_id', 8 ) ); ?>
                <br><small>
                    <a href="<?php echo admin_url( 'admin.php?page=formidable&frm_action=edit&id=' . intval( get_option( 'vulcanica_bilancio_form_id', 8 ) ) ); ?>">
                        Apri form →
                    </a>
                </small>
            </div>
            <?php if ( $published > 0 ) : ?>
            <div>
                <strong>Archivio bilanci</strong><br>
                <a href="<?php echo admin_url( 'edit.php?post_type=bilancio_genere' ); ?>">
                    Visualizza tutti →
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX — TEST CONNESSIONE
    // =========================================================================

    public static function ajax_test_api() {
        check_ajax_referer( 'vulcanica_test_api_nonce', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => 'Permesso negato.' ] );
        }

        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'Inserisci una chiave API prima di testarla.' ] );
        }

        $client = new VulcanicaGeminiClient( $api_key );
        $result = $client->generate(
            'Rispondi solo con: "Connessione OK"',
            [ 'temperature' => 0 ]  // Nessun limite token: usa il default del modello
        );

        if ( $result['success'] ) {
            wp_send_json_success( [
                'message' => '✅ Connessione riuscita! Risposta: ' . esc_html( substr( $result['content'], 0, 80 ) ),
            ] );
        } else {
            wp_send_json_error( [
                'message' => '❌ Errore: ' . esc_html( $result['error'] ),
            ] );
        }
    }

    // =========================================================================
    // SCRIPT ADMIN
    // =========================================================================

    public static function enqueue_admin_scripts( $hook ) {
        // Solo sulla nostra pagina settings (sottomenu → hook = "vulcanica_page_{slug}")
        if ( $hook !== 'vulcanica_page_' . self::PAGE_SLUG ) {
            return;
        }

        wp_add_inline_script( 'jquery', self::get_inline_js() );
    }

    private static function get_inline_js() {
        $nonce = wp_create_nonce( 'vulcanica_test_api_nonce' );
        return <<<JS
jQuery(function($){

    // Mostra/nascondi API key
    $('#vulcanica-toggle-key').on('click', function(){
        var input = $('#vulcanica_gemini_api_key');
        var isPassword = input.attr('type') === 'password';
        input.attr('type', isPassword ? 'text' : 'password');
        $(this).text(isPassword ? 'Nascondi' : 'Mostra');
    });

    // Test connessione API
    $('#vulcanica-test-api').on('click', function(){
        var btn    = $(this);
        var result = $('#vulcanica-test-result');
        var apiKey = $('#vulcanica_gemini_api_key').val();

        if (!apiKey) {
            result.css('color','#d63638').text('Inserisci prima la chiave API.');
            return;
        }

        btn.prop('disabled', true).text('⏳ Test in corso...');
        result.text('');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'vulcanica_test_api',
                nonce:   '{$nonce}',
                api_key: apiKey
            },
            success: function(res){
                if (res.success) {
                    result.css('color','#00a32a').text(res.data.message);
                } else {
                    result.css('color','#d63638').text(res.data.message);
                }
            },
            error: function(){
                result.css('color','#d63638').text('Errore di rete.');
            },
            complete: function(){
                btn.prop('disabled', false).text('🔌 Testa connessione');
            }
        });
    });

});
JS;
    }

    // =========================================================================
    // GETTER STATICI (usati dal resto del plugin)
    // =========================================================================

    public static function get_api_key() {
        return get_option( 'vulcanica_gemini_api_key', '' );
    }

    public static function get_model() {
        return get_option( 'vulcanica_gemini_model', 'gemini-2.5-flash' );
    }

    public static function get_max_tokens() {
    	return intval( get_option( 'vulcanica_gemini_max_tokens', 65535 ) );
    }

    public static function get_temperature() {
        return floatval( get_option( 'vulcanica_gemini_temperature', 0.7 ) );
    }

    public static function get_form_id() {
        return intval( get_option( 'vulcanica_bilancio_form_id', 8 ) );
    }

    public static function get_pdf_max_size_mb() {
        return intval( get_option( 'vulcanica_pdf_max_size_mb', 20 ) );
    }

    /**
     * Se true, modificare il set di PDF (upload/status toggle/delete) invalida
     * automaticamente la cache del riassunto analisi storica.
     * Default: false (riassunto manuale preservato).
     */
    public static function get_auto_invalidate_analysis() {
        return (bool) get_option( 'vulcanica_auto_invalidate_analysis', false );
    }

    /**
     * Testo fisso per il segnaposto %%QUADRO_NORMATIVO%%.
     * Può contenere HTML.
     */
    public static function get_quadro_normativo() {
        return get_option( 'vulcanica_quadro_normativo', '' );
    }

    /**
     * Testo fisso per il segnaposto %%METODOLOGIA%%.
     * Può contenere HTML.
     */
    public static function get_metodologia() {
        return get_option( 'vulcanica_metodologia', '' );
    }

    /**
     * Iniezione post-processing: trova le sezioni nell'HTML generato da Gemini
     * tramite l'attributo `id` del titolo h1-h6, e SOVRASCRIVE il contenuto
     * generato dall'AI con il testo fisso configurato nelle impostazioni.
     *
     * Funziona indipendentemente da ciò che Gemini ha scritto per quelle sezioni:
     * qualunque testo generato tra un <h?> e il successivo viene rimpiazzato.
     *
     * @param  string $content HTML generato da Gemini
     * @return string          HTML con sezioni fisse iniettate
     */
    public static function apply_content_placeholders( $content ) {

        // Mappa: id dell'heading → testo fisso configurato
        $sections = [
            'cap1_1' => self::get_quadro_normativo(),
            'cap1_2' => self::get_metodologia(),
        ];

        foreach ( $sections as $section_id => $custom_text ) {

            if ( empty( trim( $custom_text ) ) ) {
                error_log( "[Vulcanica] Sezione $section_id: testo fisso non configurato, skip." );
                continue;
            }

            // Cattura il tag heading con l'id cercato (h1–h6, attributi in qualsiasi ordine,
            // virgolette singole o doppie) + tutto il contenuto fino al prossimo heading o fine stringa.
            $pattern = '/('
                . '<h[1-6][^>]*\bid\s*=\s*["\']' . preg_quote( $section_id, '/' ) . '["\'][^>]*>'
                . '[\s\S]*?'       // contenuto interno del tag (es. testo del titolo)
                . '<\/h[1-6]>'     // chiusura del tag heading
                . ')'
                . '[\s\S]*?'       // ← tutto ciò che Gemini ha generato per questa sezione
                . '(?=<h[1-6][\s>]|\z)/i';  // lookahead: prossimo heading O fine stringa

            $replaced = preg_replace(
                $pattern,
                '$1' . "\n" . $custom_text . "\n\n",
                $content,
                1   // sostituisce solo la prima occorrenza
            );

            if ( $replaced !== null && $replaced !== $content ) {
                $content = $replaced;
                error_log( sprintf(
                    '[Vulcanica] Sezione %s: testo fisso iniettato (%d caratteri).',
                    $section_id,
                    strlen( $custom_text )
                ) );
            } else {
                error_log( sprintf(
                    '[Vulcanica] Sezione %s: heading non trovato nell\'output Gemini — id non corrisponde?',
                    $section_id
                ) );
            }
        }

        return $content;
    }

    public static function get_pdf_max_size_bytes() {
        return self::get_pdf_max_size_mb() * 1024 * 1024;
    }

    public static function get_system_prompt() {
        $custom = get_option( 'vulcanica_system_prompt', '' );
        return ! empty( $custom ) ? $custom : self::get_default_system_prompt();
    }

    public static function get_build_prompt() {
        $custom = get_option( 'vulcanica_build_prompt', '' );
        return ! empty( $custom ) ? $custom : self::get_default_build_prompt();
    }

    public static function get_default_system_prompt() {
        return <<<SYSTEM
Sei un esperto di bilanci di genere e politiche di pari opportunità con profonda esperienza nel settore pubblico italiano.

CONTESTO: Stai analizzando i dati di un Bilancio di Genere compilato da un Comune italiano tramite un questionario strutturato. Il documento che produrrai sarà pubblicato ufficialmente sul sito del comune e letto da amministratori, funzionari pubblici e cittadini.

DATI CHE RICEVERAI:
- Risposte narrative: testo libero su politiche, obiettivi e azioni del comune
- Dati statistici ISTAT: percentuali e valori su occupazione, PIL e personale comunale disaggregati per genere
- Politiche attive: risposte Sì/No su presenza di specifiche politiche di parità
- Dati numerici: importi di bilancio, fondi destinati a parità di genere, dati ATECO

ISTRUZIONI:
- Basa ogni osservazione esclusivamente sui dati forniti, citando valori specifici
- Usa un linguaggio professionale, preciso ma accessibile anche ai non esperti
- Quando i dati mostrano squilibri significativi (es. differenze di genere > 10%), evidenziali esplicitamente
- Le raccomandazioni devono essere concrete, attuabili e coerenti con il contesto di un comune italiano
- NON inventare dati o fare supposizioni non supportate dai dati forniti
- Formatta SEMPRE la risposta in HTML semantico (h2, h3, p, ul, li) senza tag html/head/body
SYSTEM;
    }

    public static function get_default_build_prompt() {
        return <<<INSTRUCTIONS
---

ISTRUZIONI PER L'ANALISI

Sulla base dei dati sopra riportati, genera un Bilancio di Genere strutturato che includa:

1. Sintesi delle politiche dichiarate — riassumi le azioni e gli obiettivi indicati nelle risposte narrative
2. Analisi delle statistiche ISTAT — commenta i dati di genere (occupazione, PIL, personale comunale) evidenziando squilibri e tendenze
3. Valutazione delle politiche attive — analizza le risposte Sì/No sulle politiche di genere, evidenziando lacune
4. Analisi della spesa — commenta i dati numerici di bilancio in relazione agli obiettivi dichiarati
5. Osservazioni e raccomandazioni — suggerisci 3-5 azioni concrete e prioritarie per migliorare la parità di genere

Formatta la risposta in HTML semantico con h2, h3, p, ul, li.
Non includere tag html, head o body — solo il contenuto interno.
INSTRUCTIONS;
    }

    public static function get_pdf_context_prompt( $pdf_files = [] ) {
        $custom = get_option( 'vulcanica_pdf_context_prompt', '' );
        if ( ! empty( $custom ) ) {
            return self::format_pdf_context_prompt( $custom, $pdf_files );
        }
        return self::get_default_pdf_context_prompt( $pdf_files );
    }

    public static function get_default_pdf_context_prompt( $pdf_files = [] ) {
        $prompt = "---CONTESTO STORICO---\n\n";
        $prompt .= "NOTA: Sono allegati " . count( $pdf_files ) . " bilanci di genere storici di questo ente in formato PDF.\n\n";
        $prompt .= "Utilizza OBBLIGATORIAMENTE questi documenti PDF come contesto storico per:\n";
        $prompt .= "• Confrontare i trend storici con i dati attuali forniti sopra\n";
        $prompt .= "• Identificare i progressi compiuti e le aree che richiedono ancora attenzione\n";
        $prompt .= "• Fornire un'analisi comparativa tra gli anni\n";
        $prompt .= "• Supportare le tue conclusioni e raccomandazioni con dati storici concreti\n\n";
        $prompt .= "Elenco file PDF allegati:\n";
        foreach ( $pdf_files as $idx => $pdf ) {
            $prompt .= "  " . ( $idx + 1 ) . ". " . $pdf['filename'] . "\n";
        }
        return $prompt;
    }

    public static function get_preprocessing_prompt() {
        $custom = get_option( 'vulcanica_preprocessing_prompt', '' );
        return ! empty( $custom ) ? $custom : self::get_default_preprocessing_prompt();
    }

    public static function get_default_preprocessing_prompt() {
        return <<<'PROMPT'
Analizza questi bilanci di genere storici e fornisci un riassunto strutturato con le seguenti sezioni:

1. **PERIODO COPERTO** — anni e ente/comune analizzato
2. **TENDENZE PLURIENNALI** — principali trend nei dati di genere nel tempo
3. **DATI QUANTITATIVI CHIAVE** — tabella riassuntiva con percentuali e numeri significativi per anno
4. **GAP DI GENERE PRINCIPALI** — disparità identificate e loro evoluzione nel tempo
5. **PUNTI DI FORZA RAGGIUNTI** — progressi e aspetti positivi già consolidati
6. **CRITICITÀ RICORRENTI** — problemi storici persistenti non ancora risolti
7. **RACCOMANDAZIONI PRECEDENTI** — azioni già suggerite nei bilanci passati
8. **ELEMENTI CHIAVE DA CONSIDERARE** — insights più importanti per il bilancio attuale

Sii conciso e strutturato. Usa dati numerici dove disponibili. Non copiare verbatim dai documenti.
PROMPT;
    }

    public static function get_csv_pre_analysis_prompt() {
        $custom = get_option( 'vulcanica_csv_pre_analysis_prompt', '' );
        return ! empty( $custom ) ? $custom : self::get_default_csv_pre_analysis_prompt();
    }

    public static function get_default_csv_pre_analysis_prompt() {
        return <<<'PROMPT'
Il seguente file CSV contiene dati quantitativi sul personale dell'ente, organizzati con una riga per indicatore (formato long/tidy).
Le colonne sono: anno, codice_comune, comune, genere (F/M/T), categoria, indicatore, valore.

Analizza questi dati e produci un riassunto strutturato in italiano con:

1. **COMPOSIZIONE DEL PERSONALE** — distribuzione per genere, categoria e area funzionale
2. **DIVARIO RETRIBUTIVO** — differenze salariali tra uomini e donne dove presenti
3. **CARRIERE E PROGRESSIONI** — forbice di carriera, promozioni, posizioni apicali per genere
4. **CONCILIAZIONE VITA-LAVORO** — part-time, smart working, congedi parentali per genere
5. **FORMAZIONE** — distribuzione ore di formazione per genere
6. **TENDENZE NEL TRIENNIO** — variazioni significative anno su anno
7. **CRITICITÀ PRINCIPALI** — massimo 5 gap di genere più rilevanti emersi dai dati

Sii preciso con i numeri. Segnala i dati mancanti (celle vuote) senza inventarli.
PROMPT;
    }

    public static function get_pdf_comunale_pre_analysis_prompt() {
        $custom = get_option( 'vulcanica_pdf_comunale_pre_analysis_prompt', '' );
        return ! empty( $custom ) ? $custom : self::get_default_pdf_comunale_pre_analysis_prompt();
    }

    public static function get_default_pdf_comunale_pre_analysis_prompt() {
        return <<<'PROMPT'
I seguenti file PDF sono bilanci comunali (di previsione o consuntivo) del Comune oggetto di analisi.
Analizzali per identificare elementi utili alla redazione del Bilancio di Genere, in particolare:

1. **ENTRATE E USCITE PER AREA** — dotazioni finanziarie nelle aree rilevanti per il genere (sociale, educazione, cultura, mobilità, pari opportunità)
2. **SPESE PER PARI OPPORTUNITÀ** — eventuali capitoli dedicati, ammontare e variazioni
3. **SERVIZI ALLA PERSONA** — dotazione per servizi educativi (asili, scuole), assistenza domiciliare, centri antiviolenza
4. **INVESTIMENTI E APPALTI** — valore complessivo e presenza di clausole sociali o di genere
5. **VARIAZIONI RISPETTO ALL'ANNO PRECEDENTE** — tagli o incrementi nelle aree sensibili al genere
6. **ELEMENTI DI ATTENZIONE** — voci di bilancio che potrebbero impattare negativamente sulla parità di genere

Sii sintetico e orientato all'uso pratico nel Bilancio di Genere. Non riportare numeri di pagina o codici interni.
PROMPT;
    }

    private static function format_pdf_context_prompt( $template, $pdf_files = [] ) {
        $template = str_replace( '{count}', count( $pdf_files ), $template );
        $file_list = '';
        foreach ( $pdf_files as $idx => $pdf ) {
            $file_list .= "  " . ( $idx + 1 ) . ". " . $pdf['filename'] . "\n";
        }
        $template = str_replace( '{file_list}', $file_list, $template );
        return $template;
    }
}

// Inizializza
add_action( 'plugins_loaded', [ 'VulcanicaSettings', 'init' ] );
?>
