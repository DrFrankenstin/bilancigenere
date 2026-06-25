<?php
/**
 * Plugin Name: Vulcanica Comuni Manager
 * Description: Gestione database comuni italiani per Formidable Forms. Legge i dati ISTAT
 *              dalle tabelle locali wp_istat_* e compila automaticamente i campi readonly
 *              del form "Bilancio di Genere" (form ID 8).
 *
 *              V4.0: Dynamic year detection + JSON metrics per sezione + JS refactoring
 * Version: 4.0
 * Author: Andrea Balboni | Vulcanica
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ===== CARICA LE CLASSI NECESSARIE =====
require_once plugin_dir_path( __FILE__ ) . 'class-settings.php';       // Settings page (prima degli altri — espone getter)
require_once plugin_dir_path( __FILE__ ) . 'class-data-aggregator.php';
require_once plugin_dir_path( __FILE__ ) . 'class-gemini-client.php';
require_once plugin_dir_path( __FILE__ ) . 'class-cpt-bilancio.php';
require_once plugin_dir_path( __FILE__ ) . 'class-ajax-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'class-pdf-manager.php';    // PDF upload manager
require_once plugin_dir_path( __FILE__ ) . 'class-cron-manager.php';   // WP-Cron: refresh cache Gemini
require_once plugin_dir_path( __FILE__ ) . 'class-frontend-list.php';  // Shortcode [vulcanica_bilanci_list]
require_once plugin_dir_path( __FILE__ ) . 'class-ai-summary.php';     // Meta box "Sintesi AI" per bilancio_genere

// Inizializza il Cron Manager (registra interval + hook callback)
VulcanicaCronManager::init();

class VulcanicaComuniManager {

    private $table_name;

    /** field_id del campo "Comune" (dropdown) nel form di registrazione */
    const COMUNE_FIELD_ID  = 42;  // Field "Comune" - dropdown con lista comuni

    /** field_id del campo "ISTAT" (dove si salva il codice_istat) */
    const ISTAT_FIELD_ID   = 40;  // Field "ISTAT" - salvato da lookup.js

    /** ID del form "Bilancio di genere" */
    const BILANCIO_FORM_ID = 8;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vulcanica_comuni';

        register_activation_hook( __FILE__, [ $this, 'on_activation' ] );
        register_deactivation_hook( __FILE__, [ 'VulcanicaCronManager', 'on_deactivation' ] );
        add_action( 'admin_menu',         [ $this, 'add_admin_menu' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_scripts' ] );       // Frontend
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] ); // Admin

        // Formidable: popola dropdown comuni
        add_filter( 'frm_setup_new_fields_vars', [ $this, 'populate_comuni_dropdown' ], 20, 2 );

        // Formidable: pre-compila il campo ISTAT nel form bilancio
        add_filter( 'frm_get_default_value', [ $this, 'prefill_istat_in_bilancio' ], 10, 2 );

        // Intercetta lo shortcode [formidable] per controllare l'accesso
        add_filter( 'do_shortcode_tag', [ $this, 'intercept_formidable_shortcode' ], 10, 4 );

        // Formidable: Hook post-submit → salva item_id per il JS
        add_action( 'frm_after_create_entry', [ $this, 'on_form_submit' ], 10, 2 );

        // AJAX: recupera item_id dal transient (fallback per il JS)
        add_action( 'wp_ajax_vulcanica_get_item_id',        [ $this, 'ajax_get_item_id' ] );
        add_action( 'wp_ajax_nopriv_vulcanica_get_item_id', [ $this, 'ajax_get_item_id' ] );

        // REST API
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Pulisci i dati serializzati corrotti del form dal database (una sola volta)
        add_action( 'init', [ $this, 'fix_form_data_corruption' ], 0 );

        // Filtra i field_options per rimuovere dati serializzati corrotti durante il rendering
        add_filter( 'frm_field_options_json', [ $this, 'clean_field_options_on_render' ] );

        // Output buffering per pulire i dati serializzati corrotti dall'HTML finale
        add_action( 'template_redirect', [ $this, 'start_output_buffering_clean' ], 1 );
        add_action( 'wp_footer', [ $this, 'flush_clean_output_buffer' ], 999 );

        // Redirect login per utenti Formidable (ruolo Sottoscrittore) alla homepage
        // Priorità 999 per assicurarsi di essere eseguito DOPO altri filtri (es. Formidable)
        add_filter( 'login_redirect', [ $this, 'redirect_formidable_users_to_home' ], 999, 3 );

        // Logout: filtro per reindirizzare subscriber alla homepage (priorità alta come login_redirect)
        add_filter( 'logout_redirect', [ $this, 'redirect_subscriber_on_logout' ], 999, 3 );

        // Login: salva messaggio di benvenuto
        add_action( 'wp_login', [ $this, 'save_login_message' ], 10, 2 );

        // Logout: salva messaggio di logout
        add_action( 'wp_logout', [ $this, 'save_logout_message' ] );

        // Mostra i messaggi di login/logout automaticamente nel body
        add_action( 'wp_body_open', [ $this, 'display_vulcanica_messages' ] );

        // Form login personalizzato (Formidable ID 4)
        add_action( 'login_init', [ $this, 'redirect_to_custom_login_form' ] );
        add_action( 'frm_after_create_entry', [ $this, 'on_login_form_submit' ], 10, 2 );

        // Aggiungi link Amministrazione nel menu per gli admin loggati
        add_filter( 'wp_nav_menu_items', [ $this, 'add_admin_link_to_menu' ], 10, 2 );

        // DISABILITATO: Output buffering stava causando problemi col rendering dei campi radio
        // Fix serialized data in form rendering using output buffering
        // add_action( 'template_redirect', [ $this, 'start_output_buffering' ], 1 );
        // add_action( 'wp_footer', [ $this, 'clean_and_flush_output' ], 999 );

        // PDF Manager: registra AJAX handlers
        VulcanicaPDFManager::init();
    }

    // =========================================================================
    // PULIZIA DATI SERIALIZZATI — Rimuove caratteri indesiderati
    // =========================================================================

    /**
     * Pulisce i dati serializzati dei campi radio una sola volta
     * Rimuove virgolette intelligenti e altri caratteri strani
     */
    public function cleanup_serialized_data_once() {
        // Esegui solo se non è stato fatto prima
        if ( get_transient( 'vulcanica_serialized_cleanup_done' ) ) {
            return;
        }

        // IDs dei campi radio
        $radio_field_ids = [
            448, 449, 450, 451, 452, 453, 454, 455, 456, 457, 458, 459, 460, 461,
            463, 464, 465, 466, 467, 468, 469, 470,
            473, 474, 475, 476, 477, 478, 479, 480, 481, 482, 483, 484,
            496, 497, 498, 499, 500, 501
        ];

        global $wpdb;
        $cleaned_count = 0;

        foreach ( $radio_field_ids as $field_id ) {
            $field = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, field_options FROM {$wpdb->prefix}frm_fields WHERE id = %d",
                    $field_id
                )
            );

            if ( ! $field ) {
                continue;
            }

            // Decodifica i dati serializzati
            $options = maybe_unserialize( $field->field_options );
            if ( ! is_array( $options ) ) {
                continue;
            }

            $modified = false;

            // Pulisci le opzioni
            if ( isset( $options['options'] ) && is_array( $options['options'] ) ) {
                foreach ( $options['options'] as $key => $value ) {
                    $cleaned = $this->clean_unwanted_chars( $value );
                    if ( $cleaned !== $value ) {
                        $options['options'][ $key ] = $cleaned;
                        $modified = true;
                    }
                }
            }

            // Pulisci il label
            if ( isset( $options['label'] ) ) {
                $cleaned = $this->clean_unwanted_chars( $options['label'] );
                if ( $cleaned !== $options['label'] ) {
                    $options['label'] = $cleaned;
                    $modified = true;
                }
            }

            // Se ci sono modifiche, salva
            if ( $modified ) {
                $serialized = maybe_serialize( $options );
                $wpdb->update(
                    "{$wpdb->prefix}frm_fields",
                    [ 'field_options' => $serialized ],
                    [ 'id' => $field_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                $cleaned_count++;
            }
        }

        // Segna come completato (per 7 giorni)
        set_transient( 'vulcanica_serialized_cleanup_done', 1, WEEK_IN_SECONDS );
    }

    /**
     * Filtra i field_options durante il rendering per rimuovere dati serializzati corrotti
     *
     * @param string $json JSON field options
     * @return string JSON pulito
     */
    public function clean_field_options_on_render( $json ) {
        if ( ! is_string( $json ) ) {
            return $json;
        }

        // Rimuovi pattern di dati serializzati corrotti tipo: ";s:10:
        $json = preg_replace( '/";s:\d+:/', '', $json );

        return $json;
    }

    /**
     * Avvia output buffering per pulire i dati serializzati corrotti
     */
    public function start_output_buffering_clean() {
        // Solo sulle pagine singole
        if ( is_singular( 'page' ) ) {
            ob_start( [ $this, 'clean_output_on_buffer_flush' ] );
        }
    }

    /**
     * Callback per il buffer flush - pulisce i dati serializzati corrotti
     *
     * @param string $output HTML generato
     * @return string HTML pulito
     */
    public function clean_output_on_buffer_flush( $output ) {
        // Rimuovi tutti i pattern di dati serializzati corrotti
        $output = preg_replace( '/";s:\d+:/', '', $output );
        $output = preg_replace( '/;s:\d+:/', '', $output );
        $output = preg_replace( '/";s\b/', '', $output );
        $output = preg_replace( '/^\s*";s/m', '', $output );

        // Rimuovi virgolette orfane prima di tag HTML
        $output = preg_replace( '/>\s*"\s*</', '><', $output );
        $output = preg_replace( '/>\s*"\s*\n\s*</', '><', $output );
        $output = preg_replace( '/^\s*"\s*</m', '<', $output );
        $output = preg_replace( '/>\s*"$/m', '>', $output );

        return $output;
    }

    /**
     * Flush del buffer pulito al footer
     */
    public function flush_clean_output_buffer() {
        if ( ob_get_level() > 0 ) {
            ob_end_flush();
        }
    }

    /**
     * Rimuove caratteri indesiderati da una stringa
     *
     * @param mixed $string Stringa da pulire
     * @return mixed Stringa pulita
     */
    private function clean_unwanted_chars( $string ) {
        if ( ! is_string( $string ) ) {
            return $string;
        }

        // Rimuovi BOM UTF-8
        $string = preg_replace( '/^\xEF\xBB\xBF/', '', $string );

        // Converti virgolette intelligenti in normali
        $string = str_replace(
            [ '"', '"', '–', '—', "'", "'" ],
            [ '"', '"', '-', '-', "'", "'" ],
            $string
        );

        // Rimuovi caratteri di controllo invisibili
        $string = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string );

        return trim( $string );
    }

    /**
     * Pulizia dei dati corrotti della forma dal database (una sola volta)
     * Pulisce sia le options della forma che TUTTI i field_options corrotti in form 8
     */
    public function fix_form_data_corruption() {
        if ( get_option( 'vulcanica_form_data_fixed' ) ) {
            return;
        }

        global $wpdb;
        $cleaned_count = 0;

        // 1. Pulisci options (NOT form_options!) per il form ID 8
        $form = $wpdb->get_row(
            "SELECT id, options FROM {$wpdb->prefix}frm_forms WHERE id = 8 LIMIT 1"
        );

        if ( $form ) {
            $raw = $form->options;
            $cleaned = $this->clean_serialized_data( $raw );

            if ( $cleaned !== $raw ) {
                $wpdb->update(
                    "{$wpdb->prefix}frm_forms",
                    [ 'options' => $cleaned ],
                    [ 'id' => 8 ],
                    [ '%s' ],
                    [ '%d' ]
                );
                $cleaned_count++;
            }
        }

        // 2. Pulisci TUTTI i field_options dei campi in form 8
        $fields = $wpdb->get_results(
            "SELECT id, field_options FROM {$wpdb->prefix}frm_fields WHERE form_id = 8"
        );

        foreach ( $fields as $field ) {
            $raw = $field->field_options;
            $cleaned = $this->clean_serialized_data( $raw );

            // Aggiorna solo se c'è stata una modifica
            if ( $cleaned !== $raw ) {
                $wpdb->update(
                    "{$wpdb->prefix}frm_fields",
                    [ 'field_options' => $cleaned ],
                    [ 'id' => $field->id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                $cleaned_count++;
            }
        }

        // Segna come fatto
        update_option( 'vulcanica_form_data_fixed', 1 );
    }

    /**
     * Pulisce i dati serializzati da frammenti corrotti
     *
     * @param string $data Dati da pulire
     * @return string Dati puliti
     */
    private function clean_serialized_data( $data ) {
        // Pulisci i frammenti di serializzazione corrotti
        // Pattern 1: ";s:10: (con numero)
        $cleaned = preg_replace( '/";s:\d+:/', '', $data );
        // Pattern 2: ;s:10: (senza virgoletta iniziale)
        $cleaned = preg_replace( '/;s:\d+:/', '', $cleaned );
        // Pattern 3: ";s senza numero (il case che vediamo nel form)
        $cleaned = preg_replace( '/";s\b/', '', $cleaned );
        // Pattern 4: ";s al termine di linea
        $cleaned = preg_replace( '/";s$/m', '', $cleaned );
        // Rimuovi BOM
        $cleaned = preg_replace( '/^\xEF\xBB\xBF/', '', $cleaned );
        // Converti smart quotes
        $cleaned = str_replace( [ '"', '"', '–', '—' ], [ '"', '"', '-', '-' ], $cleaned );
        // Rimuovi control characters
        $cleaned = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned );

        return $cleaned;
    }

    /**
     * Pulizia automatica una sola volta dei dati serializzati corrotti nel database
     */
    public function auto_cleanup_serialized_data() {
        // Esegui solo se non è stato fatto prima
        if ( get_option( 'vulcanica_db_cleanup_done' ) ) {
            return;
        }

        // IDs dei campi radio
        $radio_field_ids = [
            448, 449, 450, 451, 452, 453, 454, 455, 456, 457, 458, 459, 460, 461,
            463, 464, 465, 466, 467, 468, 469, 470,
            473, 474, 475, 476, 477, 478, 479, 480, 481, 482, 483, 484,
            496, 497, 498, 499, 500, 501
        ];

        global $wpdb;

        foreach ( $radio_field_ids as $field_id ) {
            $field = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, field_options FROM {$wpdb->prefix}frm_fields WHERE id = %d",
                    $field_id
                )
            );

            if ( ! $field ) {
                continue;
            }

            // Decodifica i dati serializzati
            $options = maybe_unserialize( $field->field_options );
            if ( ! is_array( $options ) ) {
                continue;
            }

            $modified = false;

            // Pulisci le opzioni
            if ( isset( $options['options'] ) && is_array( $options['options'] ) ) {
                foreach ( $options['options'] as $key => $value ) {
                    $cleaned = $this->clean_unwanted_chars( $value );
                    if ( $cleaned !== $value ) {
                        $options['options'][ $key ] = $cleaned;
                        $modified = true;
                    }
                }
            }

            // Pulisci il label
            if ( isset( $options['label'] ) ) {
                $cleaned = $this->clean_unwanted_chars( $options['label'] );
                if ( $cleaned !== $options['label'] ) {
                    $options['label'] = $cleaned;
                    $modified = true;
                }
            }

            // Se ci sono modifiche, salva
            if ( $modified ) {
                $serialized = maybe_serialize( $options );
                $wpdb->update(
                    "{$wpdb->prefix}frm_fields",
                    [ 'field_options' => $serialized ],
                    [ 'id' => $field_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
        }

        // Segna come completato
        update_option( 'vulcanica_db_cleanup_done', 1 );
    }

    // =========================================================================
    // FORM SUBMIT HANDLING — Dopo che l'utente invia il form
    // =========================================================================

    /**
     * Triggered quando l'utente invia il form Bilancio di Genere
     * Mostra interfaccia "working" e prepara AJAX per Gemini
     *
     * @param int $entry_id ID dell'entry creato (wp_frm_items)
     * @param int $form_id ID della forma
     */
    public function on_form_submit( $entry_id, $form_id ) {
        // Solo per il form Bilancio di Genere
        if ( (int) $form_id !== self::BILANCIO_FORM_ID ) {
            return;
        }

        // Salva item_id nel transient legato all'utente (o all'IP se non loggato)
        // Il JS lo recupera subito dopo via AJAX come fallback
        $key = $this->get_pending_transient_key();
        set_transient( $key, (int) $entry_id, 5 * MINUTE_IN_SECONDS );
    }

    /**
     * AJAX: restituisce l'item_id pendente dal transient e lo cancella.
     * Usato dal JS come fallback quando Formidable non include item_id nel response.
     */
    public function ajax_get_item_id() {
        check_ajax_referer( 'vulcanica_form_nonce', 'nonce' );

        $key     = $this->get_pending_transient_key();
        $item_id = get_transient( $key );

        if ( $item_id ) {
            delete_transient( $key ); // Consuma il token — usa una sola volta
            wp_send_json_success( [ 'item_id' => $item_id ] );
        } else {
            wp_send_json_error( [ 'message' => 'Nessun invio pendente trovato.' ] );
        }
    }

    /**
     * Chiave transient univoca per utente o IP.
     */
    private function get_pending_transient_key() {
        if ( is_user_logged_in() ) {
            return 'vcm_pending_' . get_current_user_id();
        }
        // Non loggato: usa un hash dell'IP
        return 'vcm_pending_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'anon' );
    }

    // =========================================================================
    // TABELLA COMUNI
    // =========================================================================

    public function on_activation() {
        // Inizializza impostazioni con valori di default se non ancora salvate
        if ( ! get_option( 'vulcanica_gemini_model' ) ) {
            add_option( 'vulcanica_gemini_model', 'gemini-3-flash-preview' );  // Gemini 3.1 Flash Preview - equilibrato
        }
        if ( ! get_option( 'vulcanica_gemini_max_tokens' ) ) {
        	add_option( 'vulcanica_gemini_max_tokens', 65535 );  // Max tokens per risposte complete
        }
        if ( ! get_option( 'vulcanica_gemini_temperature' ) ) {
            add_option( 'vulcanica_gemini_temperature', 0.7 );
        }

        // PDF Manager: crea directory di upload e inizializza option
        VulcanicaPDFManager::create_upload_directory();
        if ( ! get_option( VulcanicaPDFManager::OPTION_NAME ) ) {
            add_option( VulcanicaPDFManager::OPTION_NAME, '[]' );
        }

        // Cron Manager: schedula il refresh cache Gemini ogni 20h
        VulcanicaCronManager::on_activation();

        // Crea tabella
        $this->create_table();
        // Registra CPT e flush rewrite rules così i permalink funzionano subito
        VulcanicaCPTBilancio::register();
        flush_rewrite_rules();
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id_comune    varchar(10)  NOT NULL,
            nome_comune  varchar(255) NOT NULL,
            codice_istat varchar(10)  NOT NULL UNIQUE,
            provincia    varchar(100),
            regione      varchar(100),
            PRIMARY KEY (id_comune)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================================
    // DYNAMIC YEAR DETECTION — Scopre quali anni sono disponibili
    // =========================================================================

    /**
     * Rileva dinamicamente quali anni sono disponibili per una tabella con suffisso anno.
     * Es: per wp_istat_popolazione_comuni, rileva quali tra _2023, _2024, _2025 esistono.
     *
     * @param string $table_base Nome base tabella senza suffisso anno (es. 'wp_istat_popolazione_comuni')
     * @return array Array di anni disponibili, ordinati DESC (es. [2025, 2024, 2023])
     */
    private function detect_available_years( $table_base ) {
        global $wpdb;

        // Anni candidati: ultimi 5 anni dal corrente
        $current_year = (int) date( 'Y' );
        $years = [];
        for ( $y = $current_year; $y >= $current_year - 4; $y-- ) {
            $years[] = $y;
        }

        $available = [];
        foreach ( $years as $year ) {
            $table_name = $wpdb->prefix . str_replace( 'wp_', '', $table_base ) . '_' . $year;
            $result = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" );
            if ( $result ) {
                $available[] = $year;
            }
        }

        // Ordina DESC (anni più recenti prima)
        rsort( $available );
        return $available;
    }

    /**
     * Ritorna gli ultimi 3 anni disponibili, etichettati come UA (ultimo), PA (penultimo), TA (terzultimo)
     *
     * @param string $table_base Nome base tabella
     * @return array ['ua' => 2025, 'pa' => 2024, 'ta' => 2023, 'available' => [2025, 2024, 2023]]
     */
    private function get_years_labels( $table_base ) {
        $available = $this->detect_available_years( $table_base );

        return [
            'ua'        => isset( $available[0] ) ? $available[0] : null,
            'pa'        => isset( $available[1] ) ? $available[1] : null,
            'ta'        => isset( $available[2] ) ? $available[2] : null,
            'available' => $available,
        ];
    }

    // =========================================================================
    // REST ROUTES
    // =========================================================================

    public function register_rest_routes() {
        register_rest_route( 'vulcanica/v1', '/istat-locale/(?P<istat>[0-9]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_istat_locale' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'istat' => [
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param );
                    }
                ]
            ]
        ] );

        register_rest_route( 'vulcanica/v1', '/user-istat', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_user_istat' ],
            'permission_callback' => [ $this, 'check_permission' ]
        ] );

        register_rest_route( 'vulcanica/v1', '/get-data/(?P<name>[a-zA-Z0-9\s\-_%]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_comune_data_callback' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function check_permission() {
        return is_user_logged_in();
    }

    // =========================================================================
    // REST CALLBACKS
    // =========================================================================

    public function get_comune_data_callback( $data ) {
        global $wpdb;
        $search = sanitize_text_field( rawurldecode( $data['name'] ) );
        $res = $wpdb->get_row( $wpdb->prepare(
            "SELECT codice_istat, id_comune FROM {$this->table_name}
              WHERE nome_comune = %s OR codice_istat = %s OR id_comune = %s LIMIT 1",
            $search, $search, $search
        ) );
        if ( ! $res ) return new WP_Error( 'not_found', 'Dato non trovato', [ 'status' => 404 ] );
        return rest_ensure_response( $res );
    }

    public function rest_get_user_istat( WP_REST_Request $request ) {
        $istat = $this->get_current_user_istat();
        if ( ! $istat ) {
            return new WP_Error( 'no_istat', 'Nessun codice ISTAT trovato per questo utente', [ 'status' => 404 ] );
        }
        return rest_ensure_response( [ 'codice_istat' => $istat ] );
    }

    /**
     * GET /wp-json/vulcanica/v1/istat-locale/{istat}
     *
     * Restituisce METRICHE SERIALIZZATE IN JSON pronte per l'AI.
     * Struttura: { p1_211_anno, p1_211_UA_u, p1_211_UA_d, ... p1_243_contr10k_ta, ... }
     * Cachato 24h con WP Transient (disabilitato in locale).
     */
    public function rest_get_istat_locale( WP_REST_Request $request ) {
        $raw   = preg_replace( '/[^0-9]/', '', $request->get_param( 'istat' ) );
        $istat = $this->resolve_istat_code( $raw );

        if ( ! $istat ) {
            return new WP_Error( 'invalid_istat', 'Codice ISTAT non valido', [ 'status' => 400 ] );
        }

        $cache_key = 'vulcanica_metrics_' . md5( $istat );
        // DISABILITATO IN LOCALE PER SVILUPPO — Riabilitare online
        // $cached = get_transient( $cache_key );
        // if ( $cached !== false ) {
        //     $cached['_source'] = 'cache';
        //     return rest_ensure_response( $cached );
        // }

        // Compila tutte le metriche secondo il CSV
        $metrics = [
            '_istat'  => $istat,
            '_source' => 'db',
        ];

        // § 2.1.1 - Popolazione residente per genere
        $metrics = array_merge( $metrics, $this->calc_2_1_1( $istat ) );

        // § 2.1.2 - Dinamiche demografiche
        $metrics = array_merge( $metrics, $this->calc_2_1_2( $istat ) );

        // § 2.1.3 - Densità abitativa
        $metrics = array_merge( $metrics, $this->calc_2_1_3( $istat ) );

        // § 2.1.4 - Indice di vecchiaia
        $metrics = array_merge( $metrics, $this->calc_2_1_4( $istat ) );

        // § 2.1.5 - Nuclei familiari
        $metrics = array_merge( $metrics, $this->calc_2_1_5( $istat ) );

        // § 2.1.6 - Stato civile per genere
        $metrics = array_merge( $metrics, $this->calc_2_1_6( $istat ) );

        // § 2.1.7 - Stranieri per genere ed età
        $metrics = array_merge( $metrics, $this->calc_2_1_7( $istat ) );

        // § 2.2.1 - Istruzione per genere
        $metrics = array_merge( $metrics, $this->calc_2_2_1( $istat ) );

        // § 2.3.1 - Occupazione per genere
        $metrics = array_merge( $metrics, $this->calc_2_3_1( $istat ) );

        // § 2.3.2 - Disoccupazione e inattività
        $metrics = array_merge( $metrics, $this->calc_2_3_2( $istat ) );

        // § 2.3.3 - Tasso di occupazione femminile
        $metrics = array_merge( $metrics, $this->calc_2_3_3( $istat ) );

        // § 2.4.1 - Reddito pro capite
        $metrics = array_merge( $metrics, $this->calc_2_4_1( $istat ) );

        // § 2.4.2 - PIL pro capite
        $metrics = array_merge( $metrics, $this->calc_2_4_2( $istat ) );

        // § 2.4.3 - Povertà
        $metrics = array_merge( $metrics, $this->calc_2_4_3( $istat ) );

        // DISABILITATO IN LOCALE PER SVILUPPO — Riabilitare online
        // set_transient( $cache_key, $metrics, DAY_IN_SECONDS );

        return rest_ensure_response( $metrics );
    }

    // =========================================================================
    // § 2.1.1 POPOLAZIONE RESIDENTE PER GENERE
    // =========================================================================

    private function calc_2_1_1( $istat ) {
        global $wpdb;
        $years = $this->get_years_labels( 'wp_istat_popolazione_comuni' );

        $metrics = [
            'p1_211_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        // Ultimo Anno (UA)
        if ( $years['ua'] ) {
            $row = $this->query_popolazione_year( $istat, $years['ua'] );
            if ( $row ) {
                $metrics['p1_211_UA_d'] = round( floatval( $row['percentuale_di_donne'] ?? 0 ), 2 );
                $metrics['p1_211_UA_u'] = round( 100 - floatval( $row['percentuale_di_donne'] ?? 0 ), 2 );
            }
        }

        // Penultimo Anno (PA)
        if ( $years['pa'] ) {
            $row = $this->query_popolazione_year( $istat, $years['pa'] );
            if ( $row ) {
                $metrics['p1_211_PA_d'] = round( floatval( $row['percentuale_di_donne'] ?? 0 ), 2 );
                $metrics['p1_211_PA_u'] = round( 100 - floatval( $row['percentuale_di_donne'] ?? 0 ), 2 );
            }
        }

        // Terzultimo Anno (TA)
        if ( $years['ta'] ) {
            $row = $this->query_popolazione_year( $istat, $years['ta'] );
            if ( $row ) {
                $metrics['p1_211_TA_d'] = round( floatval( $row['percentuale_di_donne'] ?? 0 ), 2 );
                $metrics['p1_211_TA_u'] = round( 100 - floatval( $row['percentuale_di_donne'] ?? 0 ), 2 );
            }
        }

        return $metrics;
    }

    private function query_popolazione_year( $istat, $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_popolazione_comuni_' . $year;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE LPAD(TRIM(codice_comune), 6, '0') = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.1.2 DINAMICHE DEMOGRAFICHE (NATALITÀ, MORTALITÀ, MIGRAZIONI)
    // =========================================================================

    private function calc_2_1_2( $istat ) {
        global $wpdb;
        $years = $this->get_years_labels( 'wp_istat_bilancio_demografico' );

        $metrics = [
            'p1_212_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            if ( $years[ $key ] ) {
                $row = $this->query_bilancio_year( $istat, $years[ $key ] );
                if ( $row ) {
                    $metrics["p1_212_{$label}_nati"]     = intval( $row['nati_vivi_totale'] ?? 0 );
                    $metrics["p1_212_{$label}_morti"]    = intval( $row['morti_totale'] ?? 0 );
                    // Trasferimenti = interno + estero
                    $trasf_in  = intval( $row['immigrati_da_altro_comune_totale'] ?? 0 )
                               + intval( $row['immigrati_dallestero_totale'] ?? 0 );
                    $trasf_out = intval( $row['emigrati_per_altro_comune_totale'] ?? 0 )
                               + intval( $row['emigrati_per_lestero_totale'] ?? 0 );
                    $metrics["p1_212_{$label}_trasf_in"]  = $trasf_in;
                    $metrics["p1_212_{$label}_trasf_out"] = $trasf_out;
                }
            }
        }

        return $metrics;
    }

    private function query_bilancio_year( $istat, $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_bilancio_demografico_' . $year;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE LPAD(TRIM(codice_comune), 6, '0') = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.1.3 DENSITÀ ABITATIVA E ANTROPIZZAZIONE
    // =========================================================================

    private function calc_2_1_3( $istat ) {
        global $wpdb;

        $row = $this->query_densita( $istat );

        // Colonne multi-anno: densita_abitativa_2024, _2023, _2022
        $ua = $row ? intval( $row['densita_abitativa_2024'] ?? 0 ) : null;
        $pa = $row ? intval( $row['densita_abitativa_2023'] ?? 0 ) : null;
        $ta = $row ? intval( $row['densita_abitativa_2022'] ?? 0 ) : null;

        $metrics = [
            'p1_213_anno' => $row ? '2024, 2023, 2022' : '',
            'p1_213_UA'   => $ua,
            'p1_213_PA'   => $pa,
            'p1_213_TA'   => $ta,
        ];

        return $metrics;
    }

    private function query_densita( $istat ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_densita_abitativa';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE LPAD(TRIM(codice_comune), 6, '0') = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.1.4 INDICE DI VECCHIAIA E STRUTTURA GENERAZIONALE
    // =========================================================================

    private function calc_2_1_4( $istat ) {
        global $wpdb;
        $years = $this->get_years_labels( 'wp_istat_popolazione_comuni' );

        $metrics = [
            'p1_214_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        // Indice vecchiaia = percentuale_di_popolazione_over_65 / percentuale_di_popolazione_under_35 * 100
        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            if ( $years[ $key ] ) {
                $row = $this->query_popolazione_year( $istat, $years[ $key ] );
                if ( $row ) {
                    $over65  = floatval( $row['percentuale_di_popolazione_over_65'] ?? 0 );
                    $under35 = floatval( $row['percentuale_di_popolazione_under_35'] ?? 0 );
                    $metrics["p1_214_{$label}"] = $under35 > 0
                        ? round( ( $over65 / $under35 ) * 100, 2 )
                        : null;
                }
            }
        }

        return $metrics;
    }

    // =========================================================================
    // § 2.1.5 NUCLEI FAMILIARI
    // =========================================================================

    private function calc_2_1_5( $istat ) {
        global $wpdb;
        $metrics = [
            'p1_215_anno' => '2020', // ultimi dati disponibili
        ];

        $row = $this->query_nuclei_familiari( $istat );
        if ( $row ) {
            // Ultimo Anno (UA) = 2020
            $metrics['p1_215_UA_single']  = intval( $row['c_2020_unipersonali'] ?? 0 );
            $metrics['p1_215_UA_coppias'] = intval( $row['c_2020_coppie_senza_figli'] ?? 0 );
            $metrics['p1_215_UA_coppiac'] = intval( $row['c_2020_coppie_con_figli'] ?? 0 );
            $metrics['p1_215_UA_mono']    = intval( $row['c_2020_monogenitore'] ?? 0 );
            $metrics['p1_215_UA_mf']      = null; // non disponibile
            $metrics['p1_215_UA_pf']      = null; // non disponibile

            // PA e TA
            $metrics['p1_215_PA_single']  = intval( $row['c_2019_unipersonali'] ?? 0 );
            $metrics['p1_215_PA_coppias'] = intval( $row['c_2019_coppie_senza_figli'] ?? 0 );
            $metrics['p1_215_PA_coppiac'] = intval( $row['c_2019_coppie_con_figli'] ?? 0 );
            $metrics['p1_215_PA_mono']    = intval( $row['c_2019_monogenitore'] ?? 0 );
            $metrics['p1_215_PA_mf']      = null;
            $metrics['p1_215_PA_pf']      = null;

            $metrics['p1_215_TA_single']  = intval( $row['c_2018_unipersonali'] ?? 0 );
            $metrics['p1_215_TA_coppias'] = intval( $row['c_2018_coppie_senza_figli'] ?? 0 );
            $metrics['p1_215_TA_coppiac'] = intval( $row['c_2018_coppie_con_figli'] ?? 0 );
            $metrics['p1_215_TA_mono']    = intval( $row['c_2018_monogenitore'] ?? 0 );
            $metrics['p1_215_TA_mf']      = null;
            $metrics['p1_215_TA_pf']      = null;
        }

        return $metrics;
    }

    private function query_nuclei_familiari( $istat ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_nuclei_familiari';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE codice_comune_istat = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.1.6 POPOLAZIONE PER STATO CIVILE DIVISA PER GENERE
    // Fonte: tabella popolazione_comuni — colonne dirette per stato civile
    // =========================================================================

    private function calc_2_1_6( $istat ) {
        $years = $this->get_years_labels( 'wp_istat_popolazione_comuni' );

        $metrics = [
            'p1_216_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            if ( $years[ $key ] ) {
                $row = $this->query_popolazione_year( $istat, $years[ $key ] );
                if ( $row ) {
                    // Use parse_italian_number for 2025 data which may have dot as thousands separator
                    $metrics["p1_216_{$label}_nubili"]    = intval( round( $this->parse_italian_number( $row['numero_di_donne_nubili'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_celibi"]    = intval( round( $this->parse_italian_number( $row['numero_di_uomini_celibi'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_congiunte"] = intval( round( $this->parse_italian_number( $row['numero_di_donne_congiunte'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_congiunti"] = intval( round( $this->parse_italian_number( $row['numero_di_uomini_congiunti'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_divorziate"]= intval( round( $this->parse_italian_number( $row['numero_di_donne_divorziate'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_divorziati"]= intval( round( $this->parse_italian_number( $row['numero_di_uomini_divorziati'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_vedove"]    = intval( round( $this->parse_italian_number( $row['numero_di_donne_vedove'] ?? 0 ) ) );
                    $metrics["p1_216_{$label}_vedovi"]    = intval( round( $this->parse_italian_number( $row['numero_di_uomini_vedovi'] ?? 0 ) ) );
                }
            }
        }

        return $metrics;
    }

    // =========================================================================
    // § 2.1.7 STRANIERI PER GENERE, ETÀ, PROVENIENZA
    // Colonne reali: perc_donne_straniere, perc_uomini_stranieri
    // Nota: Alcuni comuni piccoli potrebbero non avere dati ISTAT sui stranieri
    // =========================================================================

    private function calc_2_1_7( $istat ) {
        $years = $this->get_years_labels( 'wp_istat_stranieri' );

        $metrics = [
            'p1_217_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            $lbl = strtolower( $label );
            if ( $years[ $key ] ) {
                $row = $this->query_stranieri_year( $istat, $years[ $key ] );
                if ( $row ) {
                    $metrics["p1_217_ds_{$lbl}"]     = round( floatval( $row['percentuale_di_donne_straniere'] ?? 0 ), 2 );
                    $metrics["p1_217_us_{$lbl}"]     = round( floatval( $row['percentuale_di_uomini_stranieri'] ?? 0 ), 2 );
                    $metrics["p1_217_ds017_{$lbl}"]  = intval( $row['donne_straniere_0_17'] ?? 0 );
                    $metrics["p1_217_ds1834_{$lbl}"] = intval( $row['donne_straniere_18_34'] ?? 0 );
                    $metrics["p1_217_ds3549_{$lbl}"] = intval( $row['donne_straniere_35_49'] ?? 0 );
                    $metrics["p1_217_ds5064_{$lbl}"] = intval( $row['donne_straniere_50_64'] ?? 0 );
                    $metrics["p1_217_ds65_{$lbl}"]   = intval( $row['donne_straniere_65plus'] ?? 0 );
                    $metrics["p1_217_us017_{$lbl}"]  = intval( $row['uomini_stranieri_0_17'] ?? 0 );
                    $metrics["p1_217_us1834_{$lbl}"] = intval( $row['uomini_stranieri_18_34'] ?? 0 );
                    $metrics["p1_217_us3549_{$lbl}"] = intval( $row['uomini_stranieri_35_49'] ?? 0 );
                    $metrics["p1_217_us5064_{$lbl}"] = intval( $row['uomini_stranieri_50_64'] ?? 0 );
                    $metrics["p1_217_us65_{$lbl}"]   = intval( $row['uomini_stranieri_65plus'] ?? 0 );
                }
            }
        }

        return $metrics;
    }

    private function query_stranieri_year( $istat, $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_stranieri_' . $year;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT percentuale_di_donne_straniere, percentuale_di_uomini_stranieri,
                    donne_straniere_0_17, donne_straniere_18_34, donne_straniere_35_49,
                    donne_straniere_50_64, donne_straniere_65plus,
                    uomini_stranieri_0_17, uomini_stranieri_18_34, uomini_stranieri_35_49,
                    uomini_stranieri_50_64, uomini_stranieri_65plus
             FROM `{$table}`
             WHERE LPAD(TRIM(codice_comune), 6, '0') = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.2.1 ISTRUZIONE PER GENERE
    // Colonne reali: n_di_donne_con_titolo_di_studio_terziario_di_primo_livello (laurea)
    //                n_di_donne_con_titolo_di_studio_terziario_di_secondo_livello (post-laurea)
    // =========================================================================

    private function calc_2_2_1( $istat ) {
        $years = $this->get_years_labels( 'wp_istat_istruzione' );

        $metrics = [
            'p1_221_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            $lbl = strtolower( $label );
            if ( $years[ $key ] ) {
                $row = $this->query_istruzione_year( $istat, $years[ $key ] );
                if ( $row ) {
                    $metrics["p1_221_dnoel_{$lbl}"] = intval( $row['n_di_donne_senza_licenza_elementare'] ?? 0 );
                    $metrics["p1_221_del_{$lbl}"]   = intval( $row['n_di_donne_con_licenza_elementare'] ?? 0 );
                    $metrics["p1_221_dmed_{$lbl}"]  = intval( $row['n_di_donne_con_licenza_media'] ?? 0 );
                    $metrics["p1_221_ddip_{$lbl}"]  = intval( $row['n_di_donne_con_diploma'] ?? 0 );
                    $metrics["p1_221_dlau_{$lbl}"]  = intval( $row['n_di_donne_con_titolo_di_studio_terziario_di_primo_livello'] ?? 0 );
                    $metrics["p1_221_dsup_{$lbl}"]  = intval( $row['n_di_donne_con_titolo_di_studio_terziario_di_secondo_livello'] ?? 0 );

                    $metrics["p1_221_unoel_{$lbl}"] = intval( $row['n_di_uomini_senza_licenza_elementare'] ?? 0 );
                    $metrics["p1_221_uel_{$lbl}"]   = intval( $row['n_di_uomini_con_licenza_elementare'] ?? 0 );
                    $metrics["p1_221_umed_{$lbl}"]  = intval( $row['n_di_uomini_con_licenza_media'] ?? 0 );
                    $metrics["p1_221_udip_{$lbl}"]  = intval( $row['n_di_uomini_con_diploma'] ?? 0 );
                    $metrics["p1_221_ulau_{$lbl}"]  = intval( $row['n_di_uomini_con_titolo_di_studio_terziario_di_primo_livello'] ?? 0 );
                    $metrics["p1_221_usup_{$lbl}"]  = intval( $row['n_di_uomini_con_titolo_di_studio_terziario_di_secondo_livello'] ?? 0 );
                }
            }
        }

        return $metrics;
    }

    private function query_istruzione_year( $istat, $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_istruzione_' . $year;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE LPAD(TRIM(codice_comune), 6, '0') = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.3.1 OCCUPAZIONE PER GENERE
    // Nota: la tabella ha solo totali per genere, non fascia x genere
    // =========================================================================

    private function calc_2_3_1( $istat ) {
        $years = $this->get_years_labels( 'wp_istat_occupazione' );

        $metrics = [
            'p1_231_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            $lbl = strtolower( $label );
            if ( $years[ $key ] ) {
                $row = $this->query_occupazione_year( $istat, $years[ $key ] );
                if ( $row ) {
                    $metrics["p1_231_docc_{$lbl}"] = intval( $row['numero_di_occupate_donne'] ?? 0 );
                    $metrics["p1_231_uocc_{$lbl}"] = intval( $row['numero_di_occupati_uomini'] ?? 0 );
                }
            }
        }

        return $metrics;
    }

    private function query_occupazione_year( $istat, $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_occupazione_' . $year;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE codice_comune = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.3.2 DISOCCUPAZIONE E INATTIVITÀ
    // Nota: fasce età disponibili solo come totale (non disaggregate per genere)
    // =========================================================================

    private function calc_2_3_2( $istat ) {
        $years = $this->get_years_labels( 'wp_istat_occupazione' );

        $metrics = [
            'p1_232_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            $lbl = strtolower( $label );
            if ( $years[ $key ] ) {
                $row = $this->query_occupazione_year( $istat, $years[ $key ] );
                if ( $row ) {
                    // Totali per genere
                    $metrics["p1_232_ddis_{$lbl}"] = intval( $row['numero_di_disoccupate_donne'] ?? 0 );
                    $metrics["p1_232_udis_{$lbl}"] = intval( $row['numero_di_disoccupati_uomini'] ?? 0 );
                    // Inattivi = "altra condizione"
                    $metrics["p1_232_dina_{$lbl}"] = intval( $row['numero_di_donne_in_altra_condizione'] ?? 0 );
                    $metrics["p1_232_uina_{$lbl}"] = intval( $row['numero_di_uomini_in_altra_condizione'] ?? 0 );
                    // Fasce età disoccupati (totale, non disaggregato per genere)
                    $metrics["p1_232_dis1524_{$lbl}"] = intval( $row['numero_di_disoccupati_15_24_anni'] ?? 0 );
                    $metrics["p1_232_dis2549_{$lbl}"] = intval( $row['numero_di_disoccupati_25_49_anni'] ?? 0 );
                    $metrics["p1_232_dis5064_{$lbl}"] = intval( $row['numero_di_disoccupati_50_64_anni'] ?? 0 );
                    $metrics["p1_232_dis65_{$lbl}"]   = intval( $row['numero_di_disoccupati_65_anni_e_piu'] ?? 0 );
                    // Altra condizione fasce età (totale)
                    $metrics["p1_232_ina1524_{$lbl}"] = intval( $row['numero_di_persone_in_altra_condizione_15_24_anni'] ?? 0 );
                    $metrics["p1_232_ina2549_{$lbl}"] = intval( $row['numero_di_persone_in_altra_condizione_25_49_anni'] ?? 0 );
                    $metrics["p1_232_ina5064_{$lbl}"] = intval( $row['numero_di_persone_in_altra_condizione_50_64_anni'] ?? 0 );
                    $metrics["p1_232_ina65_{$lbl}"]   = intval( $row['numero_di_persone_in_altra_condizione_65_anni_e_piu'] ?? 0 );
                }
            }
        }

        return $metrics;
    }

    // =========================================================================
    // § 2.3.3 TASSO DI OCCUPAZIONE FEMMINILE
    // Colonna reale: percentuale_di_donne_occupate
    // =========================================================================

    private function calc_2_3_3( $istat ) {
        $years = $this->get_years_labels( 'wp_istat_occupazione' );

        $metrics = [
            'p1_233_anno' => implode( ', ', array_filter( [ $years['ua'], $years['pa'], $years['ta'] ] ) ),
        ];

        foreach ( [ 'ua' => 'UA', 'pa' => 'PA', 'ta' => 'TA' ] as $key => $label ) {
            $lbl = strtolower( $label );
            if ( $years[ $key ] ) {
                $row = $this->query_occupazione_year( $istat, $years[ $key ] );
                if ( $row ) {
                    $metrics["p1_233_docc_{$lbl}"] = round( floatval( $row['percentuale_di_donne_occupate'] ?? 0 ), 2 );
                    $metrics["p1_233_uocc_{$lbl}"] = round( floatval( $row['percentuale_di_uomini_occupati'] ?? 0 ), 2 );
                }
            }
        }

        return $metrics;
    }

    // =========================================================================
    // § 2.4.1 REDDITO PRO CAPITE
    // Tabella: istat_reddito_procapite_comuni, key: codice_istat_comune
    // Colonne multi-anno: reddito_procapite_2021/2022/2023
    // =========================================================================

    private function calc_2_4_1( $istat ) {
        $row = $this->query_reddito( $istat );

        $metrics = [
            'p1_241_anno'    => '2023, 2022, 2021',
            'p1_241_redd_ua' => $row ? round( $this->parse_italian_number( $row['reddito_procapite_2023'] ?? 0 ), 2 ) : null,
            'p1_241_redd_pa' => $row ? round( $this->parse_italian_number( $row['reddito_procapite_2022'] ?? 0 ), 2 ) : null,
            'p1_241_redd_ta' => $row ? round( $this->parse_italian_number( $row['reddito_procapite_2021'] ?? 0 ), 2 ) : null,
        ];

        return $metrics;
    }

    private function query_reddito( $istat ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_reddito_procapite_comuni';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE codice_istat_comune = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.4.2 PIL PRO CAPITE
    // Tabella: istat_pil_procapite_province, key: codice_provincia_numerico (prime 3 cifre ISTAT)
    // Colonne multi-anno: pil_pro_capite_2021/2022/2023 (formato italiano con virgola)
    // =========================================================================

    private function calc_2_4_2( $istat ) {
        $row = $this->query_pil( $istat );

        $metrics = [
            'p1_242_anno'       => '2023, 2022, 2021',
            'p1_242_pilprov_ua' => $row ? round( $this->parse_italian_number( $row['pil_pro_capite_2023'] ?? 0 ), 2 ) : null,
            'p1_242_pilprov_pa' => $row ? round( $this->parse_italian_number( $row['pil_pro_capite_2022'] ?? 0 ), 2 ) : null,
            'p1_242_pilprov_ta' => $row ? round( $this->parse_italian_number( $row['pil_pro_capite_2021'] ?? 0 ), 2 ) : null,
            'p1_242_provincia'  => $row ? ( $row['provincia'] ?? null ) : null,
        ];

        return $metrics;
    }

    private function query_pil( $istat ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'istat_pil_procapite_province';
        $cod_prov = $this->get_provincia_from_istat( $istat );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE codice_provincia_numerico = %s LIMIT 1",
            $cod_prov
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // § 2.4.3 POVERTÀ
    // Tabella: istat_contribuenti_sotto_10k, key: codice_istat_comune
    // Colonne multi-anno: contribuenti_reddito_sotto_10000_2021/2022/2023
    // =========================================================================

    private function calc_2_4_3( $istat ) {
        $row = $this->query_poverta( $istat );

        $metrics = [
            'p1_243_anno'        => '2023, 2022, 2021',
            'p1_243_contr10k_ua' => $row ? intval( str_replace( '.', '', $row['contribuenti_reddito_sotto_10000_2023'] ?? 0 ) ) : null,
            'p1_243_contr10k_pa' => $row ? intval( str_replace( '.', '', $row['contribuenti_reddito_sotto_10000_2022'] ?? 0 ) ) : null,
            'p1_243_contr10k_ta' => $row ? intval( str_replace( '.', '', $row['contribuenti_reddito_sotto_10000_2021'] ?? 0 ) ) : null,
        ];

        return $metrics;
    }

    private function query_poverta( $istat ) {
        global $wpdb;
        $table = $wpdb->prefix . 'istat_contribuenti_sotto_10k';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE codice_istat_comune = %s LIMIT 1",
            $istat
        ), ARRAY_A );

        return $row;
    }

    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    private function get_current_user_istat() {
        global $wpdb;
        $user_id = get_current_user_id();
        if ( ! $user_id ) return null;

        // Query form_item_metas per recuperare il codice ISTAT (Field 40) dal Form 2 dell'utente
        $istat = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value
             FROM {$wpdb->prefix}frm_item_metas
             WHERE field_id = %d
             AND item_id IN (
                SELECT id FROM {$wpdb->prefix}frm_items
                WHERE user_id = %d AND form_id = %d
             )
             ORDER BY item_id DESC
             LIMIT 1",
            self::ISTAT_FIELD_ID,
            $user_id,
            2  // Form ID 2 è il form di registrazione
        ) );

        return $istat ? trim( $istat ) : null;
    }

    /**
     * Converte un numero in formato italiano (es. "17.189,02") in float PHP.
     */
    private function parse_italian_number( $val ) {
        if ( ! $val || $val === '' ) return 0;
        $val = str_replace( '.', '', $val );   // rimuovi punti migliaia
        $val = str_replace( ',', '.', $val );   // virgola → punto decimale
        return floatval( $val );
    }

    /**
     * Estrae il codice provincia (prime 3 cifre) da un codice ISTAT a 6 cifre.
     */
    private function get_provincia_from_istat( $istat ) {
        return substr( str_pad( $istat, 6, '0', STR_PAD_LEFT ), 0, 3 );
    }

    private function resolve_istat_code( $code ) {
        global $wpdb;

        // Normalizza a 6 cifre
        $normalized = str_pad( $code, 6, '0', STR_PAD_LEFT );

        // Valida formato
        if ( ! preg_match( '/^\d{6}$/', $normalized ) ) {
            return null;
        }

        return $normalized;
    }

    // =========================================================================
    // FORMIDABLE: dropdown comuni
    // =========================================================================
    
    public function populate_comuni_dropdown( $values, $field ) {
    	$classes = $field->field_options['classes'] ?? '';
    	if ( strpos( $classes, 'vulcanica-comuni' ) === false ) return $values;
    	
    	global $wpdb;
    	$results = $wpdb->get_results(
    			"SELECT id_comune, nome_comune, sigla_provincia FROM {$this->table_name} ORDER BY nome_comune ASC"
    	);
    	
    	$options = [ '' => 'Seleziona un comune' ];
    	foreach ( $results as $row ) {
    		$options[ $row->id_comune ] = $row->nome_comune . ' (' . $row->sigla_provincia . ')';
    	}
    	
    	$values['options']  = $options;
    	$values['use_key']  = true;
    	return $values;
    }

    public function prefill_istat_in_bilancio( $value, $field ) {
        if ( $field->form_id != self::BILANCIO_FORM_ID || $field->id != self::ISTAT_FIELD_ID ) {
            return $value;
        }

        $istat = $this->get_current_user_istat();
        return $istat ? $istat : $value;
    }

    /**
     * Intercetta lo shortcode [formidable] e controlla l'accesso al form bilancio
     */
    public function intercept_formidable_shortcode( $output, $tag, $attr, $m ) {
        // Applica il controllo solo allo shortcode [formidable]
        if ( $tag !== 'formidable' ) {
            return $output;
        }

        // Controlla se è il form bilancio (ID 8)
        $form_id = isset( $attr['id'] ) ? intval( $attr['id'] ) : null;
        if ( $form_id !== self::BILANCIO_FORM_ID ) {
            return $output;
        }

        // Controlla se l'utente è loggato
        if ( ! is_user_logged_in() ) {
            $login_url = esc_url( wp_login_url() );
            return '<div class="vulcanica-access-error" style="padding: 20px; background: #fee; border: 1px solid #fcc; border-radius: 4px; margin: 20px 0; color: #c33; font-size: 16px;">' .
                '<strong>Accesso richiesto</strong><br>' .
                'Devi essere loggato per accedere al Bilancio di Genere. ' .
                '<a href="' . $login_url . '">Accedi qui</a>' .
                '</div>';
        }

        // Controlla se l'utente ha un codice ISTAT valido
        $istat = $this->get_current_user_istat();
        if ( empty( $istat ) ) {
            return '<div class="vulcanica-access-error" style="padding: 20px; background: #fee; border: 1px solid #fcc; border-radius: 4px; margin: 20px 0; color: #c33; font-size: 16px;">' .
                '<strong>Registrazione incompleta</strong><br>' .
                'Devi completare la registrazione con un codice ISTAT valido per accedere al Bilancio di Genere.' .
                '</div>';
        }

        // L'utente è autorizzato: renderizza il form normalmente
        return $output;
    }

    /**
     * Reindirizza gli utenti registrati via Formidable (ruolo 'subscriber')
     * alla homepage invece che a wp-admin dopo il login
     *
     * @param string $redirect_to URL di reindirizzamento di default
     * @param string $requested_redirect_to URL richiesto come parametro
     * @param WP_User|int|WP_Error $user L'oggetto utente, l'ID utente, o WP_Error
     * @return string URL di destinazione
     */
    public function redirect_formidable_users_to_home( $redirect_to, $requested_redirect_to, $user ) {
        // Se $user è un int (ID utente), recupera l'oggetto WP_User
        if ( is_int( $user ) ) {
            $user = get_user_by( 'id', $user );
        }

        // Controlla se $user è un oggetto WP_User valido
        if ( ! ( $user instanceof WP_User ) ) {
            return $redirect_to;
        }

        // Se l'utente è un admin, lascia passare il redirect di default (wp-admin)
        if ( user_can( $user, 'manage_options' ) ) {
            return $redirect_to;
        }

        // Se l'utente ha il ruolo 'subscriber' (registrato via Formidable),
        // reindirizzalo alla homepage
        if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
            return home_url( '/' );
        }

        // Per tutti gli altri utenti, usa il redirect di default
        return $redirect_to;
    }

    /**
     * Salva un messaggio di login nel transient quando l'utente accede
     *
     * @param string $user_login Username dell'utente loggato
     * @param WP_User $user Oggetto utente
     */
    public function save_login_message( $user_login, $user ) {
        // Salva il messaggio nel transient per 30 secondi
        set_transient( 'vulcanica_login_message', 'Login effettuato correttamente', 30 );
    }

    /**
     * Salva un messaggio di logout nel transient quando l'utente si disconnette
     */
    public function save_logout_message() {
        // Salva il messaggio nel transient per 30 secondi
        set_transient( 'vulcanica_logout_message', 'Logout effettuato correttamente', 30 );
    }

    /**
     * Reindirizza gli utenti subscriber (Formidable) alla homepage al logout
     *
     * @param string $redirect_to URL di reindirizzamento di default
     * @param string $requested_redirect_to URL richiesto come parametro
     * @param WP_User $user Oggetto utente che si sta disconnettendo
     * @return string URL di destinazione
     */
    public function redirect_subscriber_on_logout( $redirect_to, $requested_redirect_to, $user ) {
        // Se l'utente è un subscriber (utente Formidable), reindirizzalo alla homepage
        if ( $user && in_array( 'subscriber', (array) $user->roles, true ) ) {
            return home_url( '/' );
        }

        // Per tutti gli altri utenti, usa il redirect di default
        return $redirect_to;
    }

    /**
     * Mostra i messaggi di login/logout salvati nei transient
     */
    public function display_vulcanica_messages() {
        // Stile CSS per i messaggi
        $style = 'padding: 15px 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin: 80px 0 0; color: #155724; font-weight: 500; box-shadow: 0 2px 4px rgba(0,0,0,0.1);';

        // Mostra messaggio di login
        if ( $login_msg = get_transient( 'vulcanica_login_message' ) ) {
            echo '<div style="' . esc_attr( $style ) . '">✅ ' . esc_html( $login_msg ) . '</div>';
            delete_transient( 'vulcanica_login_message' );
        }

        // Mostra messaggio di logout
        if ( $logout_msg = get_transient( 'vulcanica_logout_message' ) ) {
            echo '<div style="' . esc_attr( $style ) . '">✅ ' . esc_html( $logout_msg ) . '</div>';
            delete_transient( 'vulcanica_logout_message' );
        }
    }

    /**
     * Reindirizza da wp-login.php al form di login personalizzato (/accedi/)
     * ESCLUSO: logout, reset password, accesso a wp-admin, e altre azioni speciali
     */
    public function redirect_to_custom_login_form() {
        // Se sta facendo logout, reset password, o altre azioni, non reindirizzare
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'login';

        if ( in_array( $action, [ 'logout', 'lostpassword', 'resetpass', 'rp' ], true ) ) {
            return;
        }

        // Se utente è loggato, non reindirizzare
        if ( is_user_logged_in() ) {
            return;
        }

        // Se sta cercando di accedere a wp-admin (redirect_to contiene wp-admin),
        // non reindirizzare (permetti l'accesso a wp-login.php per gli admin)
        $redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_url( $_GET['redirect_to'] ) : '';
        if ( strpos( $redirect_to, 'wp-admin' ) !== false ) {
            return;
        }

        // Reindirizza al form di login personalizzato
        wp_safe_redirect( home_url( '/accedi/' ) );
        exit;
    }

    /**
     * Intercetta il submit del form login Formidable (ID 4)
     * Valida le credenziali e fa il login
     *
     * @param int $entry_id ID dell'entry Formidable appena creato
     * @param int $form_id ID del form
     */
    public function on_login_form_submit( $entry_id, $form_id ) {
        // Debug log
        error_log( "[Vulcanica] on_login_form_submit: entry_id=$entry_id, form_id=$form_id" );

        // Solo per form login (ID 4)
        if ( (int) $form_id !== 4 ) {
            error_log( "[Vulcanica] Form ID non è 4, skip" );
            return;
        }

        // Recupera email e password dai metadati dell'entry
        // Formidable salva con field_id come chiave (773=email, 774=password)
        $email = $this->get_form_field_by_id( $entry_id, 773 );
        $password = $this->get_form_field_by_id( $entry_id, 774 );

        error_log( "[Vulcanica] Login attempt: email=$email, password_length=" . strlen( $password ) );

        if ( empty( $email ) || empty( $password ) ) {
            // Salva errore per mostrarlo nel form
            set_transient( 'vcm_login_error', 'Email e password sono obbligatori', 5 * MINUTE_IN_SECONDS );
            return;
        }

        // Valida credenziali con WordPress
        $user = wp_authenticate( $email, $password );

        if ( is_wp_error( $user ) ) {
            // Credenziali non valide
            set_transient( 'vcm_login_error', 'Email o password non validi', 5 * MINUTE_IN_SECONDS );
            return;
        }

        // Login riuscito: crea sessione e cookie
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID );

        // Salva il messaggio di login (viene mostrato dopo il redirect)
        set_transient( 'vulcanica_login_message', 'Login effettuato correttamente', 30 );

        // Determina l'URL di reindirizzamento usando il filtro login_redirect
        // Questo permette di reindirizzare admin a wp-admin e subscriber a homepage
        $redirect_url = apply_filters( 'login_redirect', home_url( '/' ), '', $user );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Recupera il valore di un campo dal meta di un entry Formidable (usando field_id)
     *
     * @param int $entry_id ID dell'entry
     * @param int $field_id ID del campo Formidable
     * @return string Valore del campo
     */
    private function get_form_field_by_id( $entry_id, $field_id ) {
        global $wpdb;

        $meta = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id = %d AND field_id = %d LIMIT 1",
            $entry_id,
            $field_id
        ) );

        return $meta ? maybe_unserialize( $meta ) : '';
    }

    /**
     * Recupera il valore di un campo dal meta di un entry Formidable (usando field_key - legacy)
     *
     * @param int $entry_id ID dell'entry
     * @param string $field_key Field key del campo
     * @return string Valore del campo
     */
    private function get_form_field_value( $entry_id, $field_key ) {
        global $wpdb;

        // Cerca il field_id dal field_key
        $field_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}frm_fields WHERE field_key = %s LIMIT 1",
            $field_key
        ) );

        if ( ! $field_id ) {
            return '';
        }

        return $this->get_form_field_by_id( $entry_id, $field_id );
    }

    /**
     * Modifica il menu per gli utenti loggati:
     * 1. Nascondi il link "Registrazione utenti" se sei loggato
     * 2. Aggiungi il link "Amministrazione" se sei admin
     *
     * @param string $items Menu items HTML
     * @param object $args Menu arguments
     * @return string Menu items HTML modificato
     */
    public function add_admin_link_to_menu( $items, $args ) {
        // Se l'utente è loggato, nascondi il link "Registrazione utenti"
        if ( is_user_logged_in() ) {
            $items = preg_replace(
                '/<li[^>]*>.*?<a[^>]*href="[^"]*registrazione-utenti[^"]*"[^>]*>[^<]*<\/a>.*?<\/li>/i',
                '',
                $items
            );
        }

        // Aggiungi il link "Amministrazione" solo se sei admin loggato
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            $admin_link = '<li class="menu-item menu-item-type-custom menu-item-object-custom">' .
                '<a href="' . esc_url( admin_url() ) . '">Amministrazione</a>' .
                '</li>';
            $items .= $admin_link;
        }

        return $items;
    }

    /**
     * Start output buffering to capture the entire page output
     */
    public function start_output_buffering() {
        if ( ! is_singular( 'page' ) ) {
            return;
        }
        ob_start( [ $this, 'clean_form_serialized_data' ] );
    }

    /**
     * Removes serialized PHP fragments from form output (Formidable rendering bug)
     * Removes patterns like " ";s and ";s:10: that appear due to form config issues
     */
    public function clean_form_serialized_data( $content ) {
        if ( strpos( $content, 'formidable' ) === false && strpos( $content, 'frm' ) === false ) {
            return $content;
        }

        // DISABILITATO: Questo filtro stava rimuovendo parti dei campi radio
        // Remove serialized data fragments: " ";s, ";s:10:, etc.
        // Pattern: " ";s:10: or similar → ""
        // $content = preg_replace( '/"\s*";\s*s:\d+:/', '', $content );
        // Pattern: ";s:10: or similar → ""
        // $content = preg_replace( '/";s:\d+:/', '', $content );
        // Remove leftover fragment patterns
        $content = str_replace( [ '";s', '" ";s' ], '', $content );
        // Remove isolated quote marks that were part of serialized data
        $content = preg_replace( '/<\/h\d>\s*"\s*</', '</h1><', $content );
        $content = preg_replace( '/>\s*"\s*</', '><', $content );

        return $content;
    }

    /**
     * Flush the output buffer with cleaned content
     */
    public function clean_and_flush_output() {
        if ( ! is_singular( 'page' ) ) {
            return;
        }
        if ( ob_get_level() > 0 ) {
            ob_end_flush();
        }
    }

    /**
     * Controlla se la pagina corrente contiene lo shortcode del form bilancio.
     * Usato per caricare form-processor.js solo dove serve.
     */
    private function page_has_bilancio_form() {
        global $post;
        if ( ! $post ) {
            return false;
        }
        // Shortcode Formidable: [formidable id=8] oppure [formidable id="8"]
        return has_shortcode( $post->post_content, 'formidable' )
            && strpos( $post->post_content, (string) self::BILANCIO_FORM_ID ) !== false;
    }

    /**
     * Controlla se la pagina corrente contiene lo shortcode del form registrazione (ID 2).
     */
    private function page_has_registration_form() {
        global $post;
        if ( ! $post ) {
            return false;
        }
        return has_shortcode( $post->post_content, 'formidable' )
            && strpos( $post->post_content, '2' ) !== false;
    }

    public function add_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=vulcanica-settings' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">Impostazioni</a>' );
        return $links;
    }

    public function add_admin_menu() {
        add_menu_page(
            'Vulcanica Comuni',
            'Vulcanica',
            'manage_options',
            'vulcanica_menu',
            [ $this, 'render_admin_page' ]
        );

        // Submenu: Bilanci Storici (PDF Manager)
        add_submenu_page(
            'vulcanica_menu',
            'Bilanci Storici',
            'Bilanci Storici',
            'manage_options',
            'vulcanica-bilanci-storici',
            [ $this, 'render_pdf_manager_page' ]
        );
    }

    public function render_admin_page() {
        echo '<h1>Vulcanica Comuni Manager v4.0</h1>';
        echo '<p>Gestione dati ISTAT con Dynamic Year Detection + JSON serialization per sezione</p>';
        echo '<p><strong>Stato:</strong> Plugin attivo e funzionante</p>';
    }

    /**
     * Renderizza la pagina admin per la gestione PDF dei bilanci storici
     */
    public function render_pdf_manager_page() {
        // Verifica capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        // Recupera lista PDF per la visualizzazione
        $pdf_list = VulcanicaPDFManager::get_pdf_list_for_display();
        $nonce = wp_create_nonce( VulcanicaPDFManager::NONCE_ACTION );
        ?>
        <div id="vcm-pdf-manager-wrap">
            <h1>Bilanci Storici</h1>
            <p>Carica i bilanci di genere storici di riferimento. Questi PDF verranno utilizzati dall'AI per generare nuovi bilanci con un contesto più ricco e accurato.</p>
            <p class="vcm-upload-path-label">
                📁 <strong>Cartella upload:</strong>
                <code><?php echo esc_html( VulcanicaPDFManager::get_upload_directory() ); ?></code>
            </p>

            <!-- Upload Zone -->
            <h2>Carica un nuovo file</h2>
            <div id="vcm-upload-zone" class="vcm-upload-zone">
                <div class="vcm-upload-icon">📄</div>
                <p class="vcm-upload-text">Trascina un file PDF qui oppure clicca per selezionare</p>
                <p class="vcm-upload-hint">Massimo 20 MB per file. Solo file PDF sono consentiti.</p>
                <input type="file" id="vcm-file-input" accept=".pdf" style="display:none;">
            </div>

            <!-- File List -->
            <h2>File Caricati</h2>
            <div id="vcm-file-list-container">
                <?php echo VulcanicaPDFManager::render_file_list_html(); ?>
            </div>

            <!-- Preprocessing Analisi PDF -->
            <h2>🤖 Analisi Storica Preprocessata</h2>
            <?php
            $analysis_cache   = VulcanicaPDFManager::get_pdf_analysis_cache();
            $analysis_valid   = VulcanicaPDFManager::is_pdf_analysis_cache_valid();
            $analyze_nonce    = wp_create_nonce( 'vulcanica_analyze_pdfs' );
            $published_count  = count( array_filter( VulcanicaPDFManager::get_uploaded_pdfs(), fn( $p ) => ( $p['status'] ?? 'draft' ) === 'published' ) );
            ?>
            <div id="vcm-analysis-box" style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:20px;max-width:900px;">
                <p style="margin-top:0;color:#555;">
                    Il preprocessing analizza tutti i PDF pubblicati con Gemini e salva un <strong>riassunto strutturato</strong> in cache.
                    Quando generi un nuovo bilancio, viene usato il riassunto (pochi token) invece dei PDF grezzi,
                    <strong>riducendo drasticamente i token inviati</strong> e risolvendo il problema del limite di 1M token.
                </p>

                <?php if ( $analysis_valid && $analysis_cache ) : ?>
                    <div style="background:#f0fff4;border:1px solid #c3e6cb;border-radius:4px;padding:12px 16px;margin-bottom:16px;">
                        <strong style="color:#28a745;">✅ Cache analisi valida</strong><br>
                        <small style="color:#555;">
                            Creata: <strong><?php echo esc_html( $analysis_cache['created_at'] ?? '?' ); ?></strong> &nbsp;·&nbsp;
                            PDF analizzati: <strong><?php echo esc_html( $analysis_cache['published_count'] ?? '?' ); ?></strong> &nbsp;·&nbsp;
                            Dimensione riassunto: <strong><?php echo number_format( strlen( $analysis_cache['text'] ?? '' ) ); ?> caratteri</strong>
                            (~<?php echo number_format( intdiv( strlen( $analysis_cache['text'] ?? '' ), 4 ) ); ?> token)
                        </small>
                        <details style="margin-top:10px;" id="vcm-analysis-details">
                            <summary style="cursor:pointer;color:#28a745;font-size:12px;">▶ Visualizza / Modifica riassunto…</summary>
                            <div style="margin-top:10px;">
                                <textarea
                                    id="vcm-analysis-text"
                                    rows="20"
                                    style="width:100%;font-family:monospace;font-size:12px;padding:12px;background:#252526;color:#d4d4d4;border:1px solid #444;border-radius:4px;resize:vertical;box-sizing:border-box;"
                                ><?php echo esc_textarea( $analysis_cache['text'] ?? '' ); ?></textarea>
                                <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
                                    <button id="vcm-save-analysis-btn" class="button button-primary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'vulcanica_save_analysis_cache' ) ); ?>">
                                        💾 Salva modifiche
                                    </button>
                                    <span id="vcm-save-analysis-feedback" style="font-size:12px;display:none;"></span>
                                </div>
                            </div>
                        </details>
                    </div>
                <?php elseif ( $analysis_cache && ! $analysis_valid ) : ?>
                    <div style="background:#fff8e1;border:1px solid #ffd54f;border-radius:4px;padding:12px 16px;margin-bottom:16px;">
                        <strong style="color:#856404;">⚠️ Cache non aggiornata</strong> — I PDF pubblicati sono cambiati dal'ultima analisi. Esegui una nuova analisi.
                    </div>
                <?php else : ?>
                    <div style="background:#fff5f5;border:1px solid #f5c2c7;border-radius:4px;padding:12px 16px;margin-bottom:16px;">
                        <strong style="color:#dc3545;">❌ Nessuna analisi disponibile</strong> — Esegui il preprocessing per ridurre i token inviati a Gemini.
                    </div>
                <?php endif; ?>

                <?php if ( $published_count > 0 ) : ?>
                    <button id="vcm-analyze-btn" class="button button-primary"
                        data-nonce="<?php echo esc_attr( $analyze_nonce ); ?>"
                        style="font-size:14px;height:36px;line-height:36px;padding:0 18px;">
                        🔍 <?php echo $analysis_valid ? 'Ri-analizza PDF Storici' : 'Analizza PDF Storici'; ?>
                    </button>
                    <?php if ( $analysis_cache ) : ?>
                    <button id="vcm-delete-analysis-btn" class="button"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'vulcanica_delete_analysis_cache' ) ); ?>"
                        style="font-size:14px;height:36px;line-height:36px;padding:0 18px;margin-left:8px;color:#dc3545;border-color:#dc3545;">
                        🗑 Cancella analisi (torna a PDF grezzi)
                    </button>
                    <?php endif; ?>
                    <div id="vcm-analyze-spinner" class="vcm-processing-box" style="display:none;" role="status" aria-live="polite">
                        <div class="vcm-spinner-ring" aria-hidden="true"></div>
                        <div class="vcm-processing-text">
                            <span class="vcm-processing-label">
                                Analisi in corso
                                <span class="vcm-dots" aria-hidden="true">
                                    <span></span><span></span><span></span>
                                </span>
                            </span>
                            <span class="vcm-processing-hint">Elaborazione batch PDF con Gemini — può richiedere 2–5 minuti. Non chiudere la pagina.</span>
                        </div>
                    </div>
                    <div id="vcm-analyze-result" style="margin-top:12px;display:none;padding:10px;border-radius:4px;"></div>
                    <p style="margin-top:8px;font-size:11px;color:#888;">
                        ℹ️ wp_option: <code>vulcanica_pdf_analysis_cache</code>
                    </p>
                <?php else : ?>
                    <p style="color:#888;font-style:italic;">Nessun PDF pubblicato. Pubblica almeno un PDF per poter eseguire l'analisi.</p>
                <?php endif; ?>
            </div>

            <!-- Cron Status -->
            <h2>🕐 Aggiornamento Automatico Cache Gemini</h2>
            <?php
            $last_run  = VulcanicaCronManager::get_last_run_info();
            $next_run  = VulcanicaCronManager::get_next_run_info();

            // Genera il secret al primo accesso se non esiste
            $cron_secret = get_option( 'vulcanica_cron_secret', '' );
            if ( empty( $cron_secret ) ) {
                $cron_secret = wp_generate_password( 32, false );
                update_option( 'vulcanica_cron_secret', $cron_secret );
            }
            $rest_url = rest_url( 'vulcanica/v1/refresh-gemini-cache' ) . '?secret=' . $cron_secret;
            ?>
            <table class="form-table" style="max-width:700px">
                <tr>
                    <th style="width:200px">Prossimo aggiornamento</th>
                    <td><strong><?php echo esc_html( $next_run ); ?></strong></td>
                </tr>
                <?php if ( $last_run ) : ?>
                <tr>
                    <th>Ultimo aggiornamento</th>
                    <td><?php echo esc_html( $last_run['time'] ); ?></td>
                </tr>
                <tr>
                    <th>File controllati</th>
                    <td><?php echo intval( $last_run['checked'] ); ?></td>
                </tr>
                <tr>
                    <th>Cache rinnovate</th>
                    <td style="color:<?php echo $last_run['renewed'] > 0 ? '#28a745' : '#666'; ?>">
                        <?php echo intval( $last_run['renewed'] ); ?>
                    </td>
                </tr>
                <tr>
                    <th>Errori</th>
                    <td style="color:<?php echo $last_run['errors'] > 0 ? '#dc3545' : '#666'; ?>">
                        <?php echo intval( $last_run['errors'] ); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <details style="margin-top:15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:12px 16px">
                <summary style="cursor:pointer;font-weight:600;color:#0073aa">
                    ⚙️ Configurazione cron di sistema
                </summary>
                <div style="margin-top:12px;font-size:13px;color:#444;line-height:1.8">
                    <p>
                        <strong>⚠️ Limitazione WP-Cron:</strong> di default WordPress esegue il cron
                        solo quando qualcuno visita il sito. Se il sito non riceve visite durante la notte,
                        il cron slitta fino alla prima visita del mattino.
                    </p>
                    <p>
                        <strong>Soluzione:</strong> configura un <strong>cron di sistema</strong> sul server
                        che chiami questo endpoint ogni 20 ore:
                    </p>
                    <code style="display:block;background:#1e1e1e;color:#4ec9b0;padding:10px 14px;border-radius:4px;word-break:break-all;margin:8px 0">
                        <?php echo esc_html( $rest_url ); ?>
                    </code>
                    <p><strong>Aggiungi nel crontab del server</strong> (<code>crontab -e</code>):</p>
                    <code style="display:block;background:#1e1e1e;color:#9cdcfe;padding:10px 14px;border-radius:4px;margin:8px 0">
                        0 */20 * * * curl -s "<?php echo esc_url( $rest_url ); ?>" &gt; /dev/null 2&gt;&amp;1
                    </code>
                    <p style="color:#888;font-size:12px">
                        🔐 Il secret token è generato automaticamente e cambia solo se lo elimini dal database.
                        Non condividere questo URL pubblicamente.
                    </p>
                </div>
            </details>

            <p style="color:#666;font-size:13px;margin-top:12px">
                ℹ️ Il cron rinnova i file su Gemini quando mancano meno di 4 ore alla scadenza (TTL Gemini = 24h),
                così al form submit i file sono sempre già pronti.
            </p>

            <!-- Debug wp_options -->
            <h2>🗄️ Debug wp_options</h2>
            <?php
            $opt_pdfs     = get_option( VulcanicaPDFManager::OPTION_NAME, '[]' );
            $opt_analysis = get_option( VulcanicaPDFManager::OPTION_PDF_ANALYSIS, null );

            $opts = [
                [
                    'key'   => VulcanicaPDFManager::OPTION_NAME,
                    'label' => 'File caricati + cache Gemini file_id',
                    'value' => is_string( $opt_pdfs ) ? json_decode( $opt_pdfs, true ) : $opt_pdfs,
                ],
                [
                    'key'   => VulcanicaPDFManager::OPTION_PDF_ANALYSIS,
                    'label' => 'Cache analisi storica preprocessata',
                    'value' => $opt_analysis,
                ],
            ];
            ?>
            <div style="max-width:900px;">
                <?php foreach ( $opts as $opt ) :
                    $json_pretty = json_encode( $opt['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                    $is_empty    = empty( $opt['value'] );
                ?>
                <details style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:10px;">
                    <summary style="padding:12px 16px;cursor:pointer;font-size:13px;font-weight:600;list-style:none;display:flex;align-items:center;gap:10px;">
                        <span style="color:#666;">📦</span>
                        <code style="background:#f0f0f1;padding:2px 8px;border-radius:3px;font-size:13px;"><?php echo esc_html( $opt['key'] ); ?></code>
                        <span style="color:#555;font-weight:400;">— <?php echo esc_html( $opt['label'] ); ?></span>
                        <?php if ( $is_empty ) : ?>
                            <span style="color:#999;font-size:11px;margin-left:auto;">(vuoto)</span>
                        <?php else : ?>
                            <span style="color:#28a745;font-size:11px;margin-left:auto;">✓ <?php echo strlen( $json_pretty ); ?> caratteri</span>
                        <?php endif; ?>
                    </summary>
                    <div style="padding:0 16px 16px;">
                        <?php if ( $is_empty ) : ?>
                            <p style="color:#999;font-style:italic;margin:12px 0 0;">Nessun valore salvato.</p>
                        <?php else : ?>
                            <pre style="background:#1e1e1e;color:#d4d4d4;padding:14px;border-radius:4px;font-size:11px;overflow-x:auto;max-height:400px;overflow-y:auto;margin:12px 0 0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html( $json_pretty ); ?></pre>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>

        </div>
        <?php
    }

    public function enqueue_scripts() {
        // ===== FORM REGISTRAZIONE (Form 2) =====
        // Carica solo su pagine con il form registrazione
        if ( $this->page_has_registration_form() ) {
            // Script lookup comuni (quando seleziona un comune, recupera ISTAT)
            wp_enqueue_script(
                'vulcanica-comuni-lookup',
                plugin_dir_url( __FILE__ ) . 'assets/js/lookup.js',
                [ 'jquery' ],
                '1.3',
                true
            );

            // Localizza variabili per lookup.js
            wp_localize_script( 'vulcanica-comuni-lookup', 'vulcanica_api', [
                'root'  => rest_url( 'vulcanica/v1/get-data/' ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ] );
        }

        // ===== FORM BILANCIO (Form 8) =====
        // Carica solo su pagine con il form bilancio
        if ( $this->page_has_bilancio_form() ) {
            // Script per popolare form bilancio dal frontend
            wp_enqueue_script(
                'istat-data',
                plugin_dir_url( __FILE__ ) . 'assets/js/istat-data.js',
                [ 'jquery' ],
                '4.0',
                true
            );

            wp_localize_script( 'istat-data', 'vulcanicaConfig', [
                'apiUrl' => rest_url( 'vulcanica/v1' ),
                'nonce'  => wp_create_nonce( 'wp_rest' ),
            ] );

            // Script per renderizzare i campi radio Si/No
            wp_enqueue_script(
                'render-radio-fields',
                plugin_dir_url( __FILE__ ) . 'assets/js/render-radio-fields.js',
                [],
                '3.0',
                true
            );

            // CSS per sezioni aggiuntive collapsibili
            wp_enqueue_style(
                'vulcanica-collapsible-sections',
                plugin_dir_url( __FILE__ ) . 'assets/css/collapsible-sections.css',
                [],
                '1.0'
            );

            // JavaScript per gestire l'apertura/chiusura sezioni aggiuntive
            wp_enqueue_script(
                'vulcanica-collapsible-sections',
                plugin_dir_url( __FILE__ ) . 'assets/js/collapsible-sections.js',
                [],
                '2.0',
                true
            );

            // CSS e JS per form processor (AJAX + Working UI)
            wp_enqueue_style(
                'vulcanica-form-processor-css',
                plugin_dir_url( __FILE__ ) . 'assets/css/form-processor.css',
                [],
                '2.0'
            );

            wp_enqueue_script(
                'vulcanica-form-processor',
                plugin_dir_url( __FILE__ ) . 'assets/js/form-processor.js',
                [ 'jquery' ],
                '2.0',
                true
            );

            wp_localize_script( 'vulcanica-form-processor', 'vulcanicaAjax', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'vulcanica_form_nonce' ),
                'formId'  => self::BILANCIO_FORM_ID,
            ] );
        }

        // ===== BILANCIO DI GENERE — Visualizzazione grafici =====
        // Carica Chart.js + charts.js sulle pagine singole del CPT bilancio_genere
        if ( is_singular( 'bilancio_genere' ) ) {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
                [],
                '4',
                true
            );

            wp_enqueue_script(
                'vulcanica-charts',
                plugin_dir_url( __FILE__ ) . 'assets/js/charts.js',
                [ 'chartjs' ],
                filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/charts.js' ),
                true
            );
        }

    }

    /**
     * Enqueue scripts e styles per le pagine admin del plugin
     * Hook: admin_enqueue_scripts
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        // Carica CSS/JS PDF Manager solo sulla pagina Bilanci Storici
        // $hook_suffix è il nome della pagina: {parent_slug}_page_{page_slug}
        $is_pdf_page = ( $hook_suffix === 'vulcanica_page_vulcanica-bilanci-storici' )
                    || ( isset( $_GET['page'] ) && $_GET['page'] === 'vulcanica-bilanci-storici' );

        if ( $is_pdf_page ) {
            wp_enqueue_style(
                'vulcanica-pdf-manager-css',
                plugin_dir_url( __FILE__ ) . 'assets/css/pdf-manager.css',
                [],
                filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/pdf-manager.css' )
            );

            wp_enqueue_script(
                'vulcanica-pdf-upload',
                plugin_dir_url( __FILE__ ) . 'assets/js/pdf-upload.js',
                [ 'jquery' ],
                filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/pdf-upload.js' ),
                true
            );

            wp_localize_script( 'vulcanica-pdf-upload', 'vulcanicaPDFUpload', [
                'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
                'nonce'               => wp_create_nonce( VulcanicaPDFManager::NONCE_ACTION ),
                'maxFileSize'         => VulcanicaPDFManager::MAX_FILE_SIZE,
                'maxFileSizeReadable' => size_format( VulcanicaPDFManager::MAX_FILE_SIZE ),
            ] );
        }

        // ===== EDITOR ADMIN — Grafici nelle tabelle del bilancio di genere =====
        // Carica Chart.js + charts.js nell'editor TinyMCE per il CPT bilancio_genere
        $screen = get_current_screen();
        $is_bilancio_editor = $screen
            && $screen->post_type === 'bilancio_genere'
            && $hook_suffix === 'post.php';

        if ( $is_bilancio_editor ) {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
                [],
                '4',
                true
            );

            wp_enqueue_script(
                'vulcanica-charts',
                plugin_dir_url( __FILE__ ) . 'assets/js/charts.js',
                [ 'chartjs' ],
                filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/charts.js' ),
                true
            );

            wp_enqueue_script(
                'vulcanica-ai-summary',
                plugin_dir_url( __FILE__ ) . 'assets/js/ai-summary.js',
                [ 'jquery' ],
                filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ai-summary.js' ),
                true
            );

            wp_localize_script( 'vulcanica-ai-summary', 'vcmAISummary', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'vcm_summary_nonce' ),
            ] );
        }
    }
}

// Instanzia il plugin
new VulcanicaComuniManager();
