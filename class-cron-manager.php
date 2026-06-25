<?php
/**
 * Vulcanica Cron Manager
 *
 * WP-Cron job che controlla periodicamente la cache Gemini dei PDF pubblicati
 * e la rinnova in background prima della scadenza, così al form submit
 * i file sono sempre pronti senza dover aspettare il re-upload.
 *
 * Schedule: ogni 20 ore (Gemini TTL è 24h, rinnoviamo con 4h di margine)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VulcanicaCronManager {

    const CRON_HOOK     = 'vulcanica_refresh_gemini_cache';
    const CRON_INTERVAL = 'vulcanica_every_20h';

    // Rinnova la cache se scade entro questa soglia (in secondi)
    // 4 ore = 14400 secondi → rinnova se mancano meno di 4h alla scadenza
    const RENEWAL_THRESHOLD = 4 * HOUR_IN_SECONDS;

    // =========================================================================
    // INIT
    // =========================================================================

    public static function init() {
        // Registra l'interval personalizzato
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );

        // Registra il callback del cron WP
        add_action( self::CRON_HOOK, [ __CLASS__, 'refresh_gemini_cache' ] );

        // Auto-schedula se il plugin è già attivo e il cron non è ancora schedulato
        add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );

        // Endpoint REST per trigger da cron di sistema esterno
        // Chiamata: GET /wp-json/vulcanica/v1/refresh-gemini-cache?secret=TOKEN
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_endpoint' ] );
    }

    /**
     * Assicura che il cron sia schedulato — chiamato su ogni init.
     * Schedula solo se non è già in coda (operazione leggera, no duplicati).
     */
    public static function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
            error_log( '[Vulcanica] Cron auto-schedulato: primo run tra 1 minuto' );
        }
    }

    /**
     * Registra l'endpoint REST per il trigger da cron di sistema esterno.
     * Permette di usare un vero cron Linux invece di WP-Cron lazy.
     */
    public static function register_rest_endpoint() {
        register_rest_route( 'vulcanica/v1', '/refresh-gemini-cache', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_refresh_cache' ],
            'permission_callback' => [ __CLASS__, 'rest_check_secret' ],
        ] );
    }

    /**
     * Verifica il secret token per l'endpoint REST
     */
    public static function rest_check_secret( $request ) {
        $secret = get_option( 'vulcanica_cron_secret', '' );
        if ( empty( $secret ) ) {
            return false; // Endpoint disabilitato se non configurato il secret
        }
        return $request->get_param( 'secret' ) === $secret;
    }

    /**
     * Callback REST — esegue il refresh e risponde con un report JSON
     */
    public static function rest_refresh_cache( $request ) {
        self::refresh_gemini_cache();
        $last = self::get_last_run_info();
        return rest_ensure_response( [
            'success' => true,
            'message' => 'Cache Gemini aggiornata',
            'result'  => $last,
        ] );
    }

    /**
     * Aggiunge l'intervallo personalizzato "ogni 20 ore" a WP-Cron
     */
    public static function add_cron_interval( $schedules ) {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => 20 * HOUR_IN_SECONDS,
            'display'  => 'Ogni 20 ore (Vulcanica Gemini Cache Refresh)',
        ];
        return $schedules;
    }

    // =========================================================================
    // ACTIVATION / DEACTIVATION
    // =========================================================================

    /**
     * Schedula il cron all'attivazione del plugin
     */
    public static function on_activation() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
            error_log( '[Vulcanica] Cron schedulato: ' . self::CRON_HOOK . ' ogni 20h' );
        }
    }

    /**
     * Rimuove il cron alla disattivazione del plugin
     */
    public static function on_deactivation() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            error_log( '[Vulcanica] Cron rimosso: ' . self::CRON_HOOK );
        }
    }

    // =========================================================================
    // CRON CALLBACK
    // =========================================================================

    /**
     * Callback principale del cron.
     * Scansiona tutti i PDF pubblicati e rinnova la cache Gemini se necessario.
     */
    public static function refresh_gemini_cache() {
        error_log( '[Vulcanica Cron] ▶ Avvio refresh cache Gemini — ' . current_time( 'mysql' ) );

        $pdfs = VulcanicaPDFManager::get_uploaded_pdfs();

        if ( empty( $pdfs ) ) {
            error_log( '[Vulcanica Cron] Nessun PDF trovato, niente da fare.' );
            return;
        }

        $checked   = 0;
        $renewed   = 0;
        $skipped   = 0;
        $errors    = 0;

        $gemini = new VulcanicaGeminiClient();
        $now    = time();

        foreach ( $pdfs as $pdf ) {
            $filename = $pdf['filename'] ?? '';
            $status   = $pdf['status']   ?? 'draft';

            // Salta i file in bozza
            if ( $status !== 'published' ) {
                continue;
            }

            $checked++;
            $file_id    = $pdf['gemini_file_id']         ?? '';
            $expiration = $pdf['gemini_expiration_time'] ?? '';

            // Determina se serve il rinnovo
            $needs_renewal = self::needs_renewal( $file_id, $expiration, $now );

            if ( ! $needs_renewal ) {
                $skipped++;
                $remaining = $expiration ? human_time_diff( $now, strtotime( $expiration ) ) : 'N/A';
                error_log( "[Vulcanica Cron] ✅ Skip {$filename} — cache valida ancora per {$remaining}" );
                continue;
            }

            // Costruisci il percorso del file
            $file_path = wp_normalize_path(
                VulcanicaPDFManager::get_upload_directory() . '/' . $filename
            );

            if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
                error_log( "[Vulcanica Cron] ⚠️ File non trovato su disco: {$file_path}" );
                $errors++;
                continue;
            }

            // Forza il re-upload svuotando prima la cache locale
            // (così upload_file_to_gemini non la riusa)
            VulcanicaPDFManager::update_pdf_gemini_cache( $filename, '', '', '' );

            // Re-upload a Gemini
            error_log( "[Vulcanica Cron] 📤 Re-upload su Gemini: {$filename}" );
            $result = $gemini->upload_file_to_gemini( $file_path, $filename );

            if ( $result['success'] ) {
                $renewed++;
                error_log( "[Vulcanica Cron] ✅ Cache rinnovata: {$filename} → {$result['file_id']}" );
            } else {
                $errors++;
                error_log( "[Vulcanica Cron] ❌ Errore re-upload {$filename}: {$result['error']}" );
                // Ripristina il vecchio file_id se disponibile, così il prossimo
                // form submit può provare a usarlo (meglio che niente)
                if ( ! empty( $file_id ) ) {
                    VulcanicaPDFManager::update_pdf_gemini_cache( $filename, $file_id, $pdf['gemini_file_uri'] ?? '', $expiration );
                }
            }
        }

        error_log(
            "[Vulcanica Cron] ◀ Fine refresh — checked:{$checked}, renewed:{$renewed}, skipped:{$skipped}, errors:{$errors}"
        );

        // Salva il timestamp dell'ultimo run per diagnostica
        update_option( 'vulcanica_cron_last_run', [
            'time'    => current_time( 'mysql' ),
            'checked' => $checked,
            'renewed' => $renewed,
            'skipped' => $skipped,
            'errors'  => $errors,
        ] );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Determina se un file ha bisogno di essere rinnovato su Gemini.
     *
     * Situazioni che richiedono il rinnovo:
     * - Nessun file_id (non mai caricato)
     * - Nessuna expiration_time
     * - Cache già scaduta
     * - Cache scade entro RENEWAL_THRESHOLD (4h)
     *
     * @param string $file_id    Gemini file ID
     * @param string $expiration ISO timestamp scadenza
     * @param int    $now        Timestamp attuale
     * @return bool
     */
    private static function needs_renewal( $file_id, $expiration, $now ) {
        // Nessun file_id → mai caricato
        if ( empty( $file_id ) ) {
            return true;
        }

        // Nessuna expiration → non sappiamo quando scade, meglio rinnovare
        if ( empty( $expiration ) ) {
            return true;
        }

        $expiration_ts = strtotime( $expiration );

        // Già scaduto
        if ( $expiration_ts <= $now ) {
            return true;
        }

        // Scade entro la soglia di rinnovo (es. meno di 4 ore)
        if ( ( $expiration_ts - $now ) < self::RENEWAL_THRESHOLD ) {
            return true;
        }

        return false;
    }

    /**
     * Ritorna le info sull'ultimo run del cron (per diagnostica nella UI admin)
     *
     * @return array|null
     */
    public static function get_last_run_info() {
        return get_option( 'vulcanica_cron_last_run', null );
    }

    /**
     * Ritorna quando è schedulato il prossimo run
     *
     * @return string Data formattata o 'Non schedulato'
     */
    public static function get_next_run_info() {
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( ! $next ) {
            return 'Non schedulato';
        }
        return wp_date( 'j M Y H:i', $next ) . ' (' . human_time_diff( time(), $next ) . ')';
    }
}
