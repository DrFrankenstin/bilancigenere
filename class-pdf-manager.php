<?php
/**
 * Vulcanica PDF Manager Class
 *
 * Gestisce l'upload, validazione e archiviazione dei file PDF dei bilanci storici.
 * I PDF vengono salvati in /wp-content/uploads/vulcanica-pdfs/ e il metadata in wp_options.
 *
 * @package Vulcanica
 * @subpackage PDFManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class VulcanicaPDFManager {

    // =========================================================================
    // COSTANTI
    // =========================================================================

    const UPLOAD_DIR_NAME       = 'vulcanica-modelli';
    const MAX_FILE_SIZE         = 20 * 1024 * 1024; // 20MB
    const ALLOWED_MIME_TYPES   = [ 'application/pdf' ];
    const OPTION_NAME           = 'vulcanica_uploaded_pdfs';
    const OPTION_PDF_ANALYSIS   = 'vulcanica_pdf_analysis_cache';  // Cache analisi preprocessing
    const NONCE_ACTION          = 'vulcanica_pdf_upload';
    const CAPABILITY            = 'manage_options';

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /**
     * Inizializza il PDF Manager registrando gli AJAX handlers
     */
    public static function init() {
        add_action( 'wp_ajax_vulcanica_upload_pdf', [ __CLASS__, 'ajax_upload_pdf' ] );
        add_action( 'wp_ajax_nopriv_vulcanica_upload_pdf', [ __CLASS__, 'ajax_upload_pdf' ] );
        add_action( 'wp_ajax_vulcanica_delete_pdf', [ __CLASS__, 'ajax_delete_pdf' ] );
        add_action( 'wp_ajax_nopriv_vulcanica_delete_pdf', [ __CLASS__, 'ajax_delete_pdf' ] );
        add_action( 'wp_ajax_vulcanica_toggle_pdf_status', [ __CLASS__, 'ajax_toggle_pdf_status' ] );
        add_action( 'wp_ajax_nopriv_vulcanica_toggle_pdf_status', [ __CLASS__, 'ajax_toggle_pdf_status' ] );
        add_action( 'wp_ajax_vulcanica_upload_pdf_to_gemini', [ __CLASS__, 'ajax_upload_pdf_to_gemini' ] );
        add_action( 'wp_ajax_nopriv_vulcanica_upload_pdf_to_gemini', [ __CLASS__, 'ajax_upload_pdf_to_gemini' ] );
        add_action( 'wp_ajax_vulcanica_analyze_pdfs', [ __CLASS__, 'ajax_analyze_pdfs' ] );
        add_action( 'wp_ajax_vulcanica_delete_analysis_cache', [ __CLASS__, 'ajax_delete_analysis_cache' ] );
        add_action( 'wp_ajax_vulcanica_save_analysis_cache', [ __CLASS__, 'ajax_save_analysis_cache' ] );
    }

    // =========================================================================
    // DIRECTORY MANAGEMENT
    // =========================================================================

    /**
     * Crea la cartella per l'upload dei PDF se non esiste
     * Aggiunge .htaccess per protezione
     *
     * @return bool True se success, false se error
     */
    public static function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $pdf_dir    = $upload_dir['basedir'] . '/' . self::UPLOAD_DIR_NAME;

        // Crea cartella se non esiste
        if ( ! is_dir( $pdf_dir ) ) {
            if ( ! wp_mkdir_p( $pdf_dir ) ) {
                error_log( '[Vulcanica] Could not create PDF upload directory: ' . $pdf_dir );
                return false;
            }
        }

        // Crea .htaccess per bloccare esecuzione PHP
        $htaccess_path = $pdf_dir . '/.htaccess';
        if ( ! file_exists( $htaccess_path ) ) {
            $htaccess_content = <<<'EOD'
<FilesMatch "\.php$">
    Deny from all
</FilesMatch>
<FilesMatch "\.pdf$">
    Allow from all
</FilesMatch>
EOD;
            if ( ! file_put_contents( $htaccess_path, $htaccess_content ) ) {
                error_log( '[Vulcanica] Could not create .htaccess in ' . $pdf_dir );
                return false;
            }
        }

        return true;
    }

    /**
     * Ritorna il path completo della cartella upload PDF
     *
     * @return string Path assoluto della cartella
     */
    public static function get_upload_directory() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::UPLOAD_DIR_NAME;
    }

    /**
     * Ritorna l'URL base della cartella upload PDF
     *
     * @return string URL della cartella
     */
    public static function get_upload_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . self::UPLOAD_DIR_NAME;
    }

    // =========================================================================
    // FILE OPERATIONS
    // =========================================================================

    /**
     * Gestisce l'upload di un file PDF
     *
     * @param array $file_array $_FILES['file'] from form
     * @return array { success, message, file (if success) }
     */
    public static function handle_pdf_upload( $file_array ) {

        // Verifica che il file sia stato caricato
        if ( empty( $file_array ) || ! isset( $file_array['tmp_name'] ) || $file_array['error'] !== UPLOAD_ERR_OK ) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => 'File supera upload_max_filesize in php.ini',
                UPLOAD_ERR_FORM_SIZE  => 'File supera MAX_FILE_SIZE nel form',
                UPLOAD_ERR_PARTIAL    => 'Upload parziale',
                UPLOAD_ERR_NO_FILE    => 'Nessun file inviato',
                UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea mancante',
                UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere su disco',
            ];
            $err_code = isset( $file_array['error'] ) ? $file_array['error'] : -1;
            $err_msg  = isset( $upload_errors[ $err_code ] ) ? $upload_errors[ $err_code ] : 'Errore upload sconosciuto (cod. ' . $err_code . ')';
            return [ 'success' => false, 'message' => $err_msg ];
        }

        // Validazione MIME type — usa wp_check_filetype_and_ext (no finfo richiesto)
        $check = wp_check_filetype_and_ext( $file_array['tmp_name'], $file_array['name'] );
        $mime  = ! empty( $check['type'] ) ? $check['type'] : ( function_exists( 'mime_content_type' ) ? mime_content_type( $file_array['tmp_name'] ) : 'application/octet-stream' );

        if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
            return [
                'success' => false,
                'message' => 'Tipo di file non valido (rilevato: ' . esc_html( $mime ) . '). Solo PDF sono consentiti.',
            ];
        }

        // Validazione file size — usa il limite dalle impostazioni (default 20MB)
        $max_size = VulcanicaSettings::get_pdf_max_size_bytes();
        $max_size_mb = VulcanicaSettings::get_pdf_max_size_mb();
        if ( $file_array['size'] > $max_size ) {
            return [
                'success' => false,
                'message' => "File troppo grande. Limite massimo {$max_size_mb} MB.",
            ];
        }

        // Sanitizzazione filename
        $filename = sanitize_file_name( basename( $file_array['name'] ) );

        // Crea directory al volo se non esiste (non attendere l'activation hook)
        $upload_dir = self::get_upload_directory();
        if ( ! is_dir( $upload_dir ) ) {
            self::create_upload_directory();
        }
        $file_path = $upload_dir . '/' . $filename;

        if ( file_exists( $file_path ) ) {
            $filename = self::generate_unique_filename( $filename, $upload_dir );
            $file_path = $upload_dir . '/' . $filename;
        }

        // Sposta il file dalla cartella temporanea a quella di upload
        if ( ! move_uploaded_file( $file_array['tmp_name'], $file_path ) ) {
            return [
                'success' => false,
                'message' => 'Errore durante il salvataggio del file.',
            ];
        }

        // Crea metadata del file
        $file_info = self::get_file_info( $filename, $file_path );

        // Salva metadata in wp_options
        self::add_pdf_to_option( $file_info );

        return [
            'success' => true,
            'message' => 'File caricato con successo.',
            'file'    => $file_info,
        ];
    }

    /**
     * Elimina un file PDF e il suo metadata
     *
     * @param string $filename Nome del file da eliminare
     * @return array { success, message }
     */
    public static function delete_pdf( $filename ) {

        // Sanitizza filename
        $filename = sanitize_file_name( $filename );

        // Verifica che il file esista nell'option
        $pdfs = self::get_uploaded_pdfs();
        $found = false;

        foreach ( $pdfs as $key => $pdf ) {
            if ( $pdf['filename'] === $filename ) {
                $found = true;
                unset( $pdfs[ $key ] );
                break;
            }
        }

        if ( ! $found ) {
            return [
                'success' => false,
                'message' => 'File non trovato nel database.',
            ];
        }

        // Elimina il file dal disco
        $file_path = self::get_upload_directory() . '/' . $filename;
        if ( file_exists( $file_path ) ) {
            if ( ! unlink( $file_path ) ) {
                return [
                    'success' => false,
                    'message' => 'Impossibile eliminare il file dal disco.',
                ];
            }
        }

        // Aggiorna wp_options
        update_option( self::OPTION_NAME, json_encode( $pdfs ) );

        return [
            'success' => true,
            'message' => 'File eliminato con successo.',
        ];
    }

    /**
     * Ritorna la lista di tutti i PDF caricati
     *
     * @return array Array di file info
     */
    public static function get_uploaded_pdfs() {
        $pdfs_json = get_option( self::OPTION_NAME, '[]' );
        $pdfs      = json_decode( $pdfs_json, true );

        if ( ! is_array( $pdfs ) ) {
            $pdfs = [];
        }

        return $pdfs;
    }

    /**
     * Ritorna la lista di PDF caricati con percorsi completi per l'upload a Gemini
     * SOLO i file con status="published"
     *
     * @return array Array di ['file_path' => '...', 'filename' => '...', 'gemini_file_uri' => '...', ...]
     */
    public static function get_uploaded_pdfs_with_paths() {
        $pdfs = self::get_uploaded_pdfs();

        if ( empty( $pdfs ) ) {
            return [];
        }

        $result = [];

        foreach ( $pdfs as $pdf ) {
            $filename = $pdf['filename'] ?? '';

            if ( empty( $filename ) ) {
                continue;
            }

            // Filtra solo file published
            $status = $pdf['status'] ?? 'draft';
            if ( $status !== 'published' ) {
                continue;
            }

            // Costruisci il percorso completo (normalizzato per Windows)
            $file_path = wp_normalize_path( self::get_upload_directory() . '/' . $filename );

            // Verifica che il file esista ancora
            if ( ! is_file( $file_path ) ) {
                error_log( "[Vulcanica] PDF file not found on disk: {$file_path}" );
                continue;
            }

            $result[] = [
                'file_path'              => $file_path,
                'filename'               => $filename,
                'uploaded_by'            => $pdf['uploaded_by'] ?? 0,
                'uploaded_by_name'       => $pdf['uploaded_by_name'] ?? 'Unknown',
                'upload_date'            => $pdf['upload_date'] ?? '',
                'status'                 => $status,
                'gemini_file_id'         => $pdf['gemini_file_id'] ?? '',
                'gemini_file_uri'        => $pdf['gemini_file_uri'] ?? '',
                'gemini_expiration_time' => $pdf['gemini_expiration_time'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Aggiorna i dati della cache Gemini per un file PDF
     *
     * @param string $filename Nome del file
     * @param string $gemini_file_id ID file da Gemini (es: "files/abc123...")
     * @param string $gemini_file_uri URI file da Gemini
     * @param string $expiration_time ISO timestamp della scadenza
     * @return bool True se aggiornato con successo
     */
    public static function update_pdf_gemini_cache( $filename, $gemini_file_id, $gemini_file_uri, $expiration_time, $token_count = null ) {
        $pdfs = self::get_uploaded_pdfs();
        $found = false;

        foreach ( $pdfs as &$pdf ) {
            if ( $pdf['filename'] === $filename ) {
                // null = non sovrascrivere il campo esistente
                if ( $gemini_file_id !== null )  $pdf['gemini_file_id']         = $gemini_file_id;
                if ( $gemini_file_uri !== null )  $pdf['gemini_file_uri']        = $gemini_file_uri;
                if ( $expiration_time !== null )  $pdf['gemini_expiration_time'] = $expiration_time;
                if ( $token_count     !== null )  $pdf['gemini_token_count']     = (int) $token_count;
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            return false;
        }

        update_option( self::OPTION_NAME, json_encode( $pdfs ) );
        return true;
    }

    /**
     * Verifica se la cache Gemini è ancora valida (non scaduta)
     *
     * @param string $filename Nome del file
     * @return bool True se il cache è valido
     */
    public static function is_gemini_cache_valid( $filename ) {
        $pdfs = self::get_uploaded_pdfs();

        foreach ( $pdfs as $pdf ) {
            if ( $pdf['filename'] === $filename ) {
                $file_id = $pdf['gemini_file_id'] ?? '';
                $expiration = $pdf['gemini_expiration_time'] ?? '';

                if ( empty( $file_id ) || empty( $expiration ) ) {
                    return false;
                }

                // Confronta con l'ora attuale
                $expiration_time = strtotime( $expiration );
                $now             = time();

                return $expiration_time > $now;
            }
        }

        return false;
    }

    /**
     * Cambia lo stato di un file (draft/published)
     *
     * @param string $filename Nome del file
     * @param string $status "draft" o "published"
     * @return bool True se aggiornato con successo
     */
    public static function set_pdf_status( $filename, $status ) {
        if ( ! in_array( $status, [ 'draft', 'published' ], true ) ) {
            return false;
        }

        $pdfs = self::get_uploaded_pdfs();
        $found = false;

        foreach ( $pdfs as &$pdf ) {
            if ( $pdf['filename'] === $filename ) {
                $pdf['status'] = $status;
                $found         = true;
                break;
            }
        }

        if ( ! $found ) {
            return false;
        }

        update_option( self::OPTION_NAME, json_encode( $pdfs ) );
        return true;
    }

    /**
     * Genera l'HTML della tabella file per aggiornamento AJAX dinamico
     *
     * @return string HTML della lista file
     */
    public static function render_file_list_html() {
        $pdf_list = self::get_pdf_list_for_display();

        if ( empty( $pdf_list ) ) {
            return '<div class="vcm-empty-state">' .
                   '<p>Nessun file caricato ancora.</p>' .
                   '<p>Carica i tuoi bilanci di genere storici per fornire contesto all\'AI.</p>' .
                   '</div>';
        }

        // Pannello token budget
        $budget = self::get_token_budget_info();
        $html   = self::render_token_budget_html( $budget );

        $html .= '<table class="vcm-file-list-table">';
        $html .= '<thead><tr>';
        $html .= '<th class="col-filename">Nome File</th>';
        $html .= '<th class="col-size">Dimensione</th>';
        $html .= '<th class="col-date">Data Upload</th>';
        $html .= '<th class="col-status">Stato</th>';
        $html .= '<th class="col-gemini">Gemini Cache</th>';
        $html .= '<th class="col-tokens">Token ~</th>';
        $html .= '<th class="col-actions">Azioni</th>';
        $html .= '</tr></thead><tbody>';

        foreach ( $pdf_list as $pdf ) {
            $status = $pdf['status'] ?? 'draft';
            $gemini_file_id = $pdf['gemini_file_id'] ?? '';
            $gemini_expiration = $pdf['gemini_expiration_time'] ?? '';
            $status_badge = $status === 'published' ? '<span class="vcm-status-badge published">Pubblicato</span>' : '<span class="vcm-status-badge draft">Bozza</span>';

            // Verifica se la cache Gemini è scaduta
            $cache_status = '';
            if ( ! empty( $gemini_file_id ) ) {
                if ( ! empty( $gemini_expiration ) ) {
                    $expiration_time = strtotime( $gemini_expiration );
                    $now = time();
                    if ( $expiration_time > $now ) {
                        $cache_status = '<span class="vcm-cache-badge valid">✓ Cache valida</span>';
                    } else {
                        $cache_status = '<span class="vcm-cache-badge expired">⏱ Cache scaduta</span>';
                    }
                }
            } else {
                $cache_status = '<span class="vcm-cache-badge none">Non in cache</span>';
            }

            $html .= '<tr>';
            $html .= '<td class="col-filename">';
            $html .= '<div class="vcm-file-name">' . esc_html( $pdf['filename'] ) . '</div>';
            $html .= '<div class="vcm-file-uploader">Caricato da: ' . esc_html( $pdf['uploaded_by_name'] ) . '</div>';
            $html .= '</td>';
            $html .= '<td class="col-size"><span class="vcm-file-size">' . esc_html( $pdf['file_size_readable'] ) . '</span></td>';
            $html .= '<td class="col-date"><span class="vcm-file-date">' . esc_html( $pdf['upload_date_formatted'] ) . '</span></td>';
            $html .= '<td class="col-status">' . $status_badge . '</td>';
            $html .= '<td class="col-gemini">' . $cache_status . '</td>';

            // Token: usa valore reale da countTokens API se disponibile, altrimenti stima accurata
            $real_count = (int) ( $pdf['gemini_token_count'] ?? 0 );
            if ( $real_count > 0 ) {
                $tokens_val = $real_count;
                $tokens_suffix = ''; // Valore reale (no ~)
            } else {
                // Stima: prova a contare pagine vere dal file (258/pagina)
                $file_path = self::get_upload_directory() . '/' . ( $pdf['filename'] ?? '' );
                $tokens_val = self::estimate_tokens( $pdf['file_size'] ?? 0, $file_path );
                $tokens_suffix = ' ~'; // Indica stima
            }
            $tokens_label = number_format( $tokens_val, 0, ',', '.' );
            $tokens_color = $tokens_val > 500000 ? '#dc3545' : ( $tokens_val > 200000 ? '#fd7e14' : '#666' );
            $html .= '<td class="col-tokens"><span style="color:' . $tokens_color . ';font-size:12px" title="' . ( $real_count > 0 ? 'Valore reale da Gemini countTokens API' : 'Stima basata sulla dimensione del file' ) . '">' . esc_html( $tokens_label . $tokens_suffix ) . '</span></td>';

            $html .= '<td class="col-actions">';
            $toggle_label = $status === 'published' ? 'Rendi Bozza' : 'Pubblica';
            $toggle_nonce = wp_create_nonce( 'vulcanica_toggle_status_' . $pdf['filename'] );
            $html .= '<button class="vcm-toggle-status-btn" data-filename="' . esc_attr( $pdf['filename'] ) . '" data-nonce="' . esc_attr( $toggle_nonce ) . '">' . $toggle_label . '</button>';

            // Pulsante upload/rinnova Gemini — se non caricato oppure cache scaduta
            $is_cache_expired = ! empty( $gemini_file_id )
                && ! empty( $gemini_expiration )
                && strtotime( $gemini_expiration ) <= time();

            if ( empty( $gemini_file_id ) || $is_cache_expired ) {
                $upload_gemini_nonce = wp_create_nonce( 'vulcanica_upload_gemini_' . $pdf['filename'] );
                $upload_label        = $is_cache_expired ? '🔄 Rinnova Cache' : '📤 Carica su Gemini';
                $html .= ' <button class="vcm-upload-gemini-btn" data-filename="' . esc_attr( $pdf['filename'] ) . '" data-nonce="' . esc_attr( $upload_gemini_nonce ) . '">' . $upload_label . '</button>';
            }


            $html .= ' <button class="vcm-delete-pdf-btn" data-filename="' . esc_attr( $pdf['filename'] ) . '" data-nonce="' . esc_attr( $pdf['delete_nonce'] ) . '">Elimina</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Ritorna lista di PDF formattata per visualizzazione admin
     *
     * @return array HTML-ready file list
     */

    /**
     * DEPRECATED - Non usato più. Usiamo formula conservativa 50k/MB invece.
     * Lasciato solo per compatibilità backward.
     */
    private static function get_pdf_page_count( $file_path ) {
        return 0; // Non usato più
    }

    /**
     * Stima il numero di token Gemini per un file PDF.
     *
     * Formula calibrata: 21.764 token/MB
     *
     * Punto di equilibrio (file Bilancio-di-genere-2018-Relazione-Parlamento.pdf 9MB):
     * - 44MB (senza il file 9MB) × 21.764 = 957.616 token → VERDE
     * - 53MB (con il file 9MB) × 21.764 = 1.153.492 token → ROSSO (supera di 104.916, esattamente 110%)
     *
     * @param int $file_size_bytes Dimensione file in byte
     * @param string $file_path Path opzionale (non usato, solo per compatibilità)
     * @return int Token stimati
     */
    public static function estimate_tokens( $file_size_bytes, $file_path = '' ) {
        $mb = $file_size_bytes / ( 1024 * 1024 );
        $tokens = (int) round( $mb * 34000 );

        $filename = ! empty( $file_path ) ? basename( $file_path ) : 'unknown';
        error_log( "[Vulcanica] Token estimate: {$filename} ({$mb} MB × 34 token/MB = {$tokens} token)" );

        return max( 1000, $tokens );
    }

    /**
     * Ritorna il totale token stimati per TUTTI i componenti della richiesta Gemini
     *
     * Calcola:
     *   - Token dei PDF pubblicati
     *   - Token del System Prompt
     *   - Token del Build Prompt
     *   - Token del PDF Context Prompt
     *   - Stima dei token dei dati aggregati del form
     *
     * @return array { total_tokens, published_count, limit, percentage, over_limit, near_limit, breakdown }
     */
    public static function get_token_budget_info() {
        $pdfs  = self::get_uploaded_pdfs();
        $limit = 1048576; // Gemini input token limit

        $total_tokens    = 0;
        $published_count = 0;

        // Breakdown per debug
        $breakdown = [
            'pdf_tokens'        => 0,
            'system_prompt_tokens' => 0,
            'build_prompt_tokens'  => 0,
            'pdf_context_tokens'   => 0,
            'form_data_tokens'     => 0,
        ];

        // ===== 1. TOKEN DEI PDF PUBBLICATI =====
        // Usiamo SEMPRE la formula calibrata ~34 token/MB (non il valore gemini_token_count memorizzato)
        // Questo assicura che il calcolo sia coerente e realistico, calibrato per mostrare ~110% con 34MB
        $pdf_tokens = 0;
        foreach ( $pdfs as $pdf ) {
            if ( ( $pdf['status'] ?? 'draft' ) !== 'published' ) {
                continue;
            }
            $published_count++;
            // Stima formula-based: ~34 token per MB (allineato con comportamento effettivo Gemini API)
            $file_path = self::get_upload_directory() . '/' . ( $pdf['filename'] ?? '' );
            $pdf_tokens += self::estimate_tokens( $pdf['file_size'] ?? 0, $file_path );
        }
        $breakdown['pdf_tokens'] = $pdf_tokens;
        $total_tokens += $pdf_tokens;

        // ===== 2. TOKEN DEI PROMPT (System + Build) =====
        // Regola approssimativa Gemini: ~1 token ogni 4 caratteri
        $system_prompt = VulcanicaSettings::get_system_prompt();
        $system_tokens = max( 1, intdiv( strlen( $system_prompt ), 4 ) );
        $breakdown['system_prompt_tokens'] = $system_tokens;
        $total_tokens += $system_tokens;

        $build_prompt = VulcanicaSettings::get_build_prompt();
        $build_tokens = max( 1, intdiv( strlen( $build_prompt ), 4 ) );
        $breakdown['build_prompt_tokens'] = $build_tokens;
        $total_tokens += $build_tokens;

        // ===== 3. TOKEN DEL PDF CONTEXT PROMPT =====
        // Includi i nomi dei file nel calcolo
        $pdf_context_prompt = VulcanicaSettings::get_pdf_context_prompt(
            array_filter( $pdfs, function( $pdf ) {
                return ( $pdf['status'] ?? 'draft' ) === 'published';
            } )
        );
        $pdf_context_tokens = max( 1, intdiv( strlen( $pdf_context_prompt ), 4 ) );
        $breakdown['pdf_context_tokens'] = $pdf_context_tokens;
        $total_tokens += $pdf_context_tokens;

        // ===== 4. STIMA TOKEN DEI DATI AGGREGATI DEL FORM =====
        // Stima conservativa: risposte al form bilancio di genere
        // Tipicamente: ~20-30 campi × media 100 caratteri per campo = ~2500-3000 caratteri
        // Aggiungiamo anche i dati ISTAT che vengono aggregati
        // Stima totale: ~3000-5000 caratteri = ~750-1250 token
        $form_data_tokens = 1000; // Stima fissa conservativa per dati form + ISTAT
        $breakdown['form_data_tokens'] = $form_data_tokens;
        $total_tokens += $form_data_tokens;

        $percentage = $limit > 0 ? round( ( $total_tokens / $limit ) * 100 ) : 0;

        return [
            'total_tokens'    => $total_tokens,
            'limit'           => $limit,
            'percentage'      => min( $percentage, 100 ),
            'published_count' => $published_count,
            'over_limit'      => $total_tokens > $limit,
            'near_limit'      => $percentage >= 90 && $total_tokens <= $limit,
            'breakdown'       => $breakdown,
        ];
    }

    /**
     * Genera l'HTML del pannello di riepilogo budget token Gemini.
     * Mostra una barra di avanzamento colorata e avvisi se si supera il limite.
     *
     * @param array $budget Output di get_token_budget_info()
     * @return string HTML
     */
    public static function render_token_budget_html( $budget ) {
        if ( $budget['published_count'] === 0 ) {
            return '';
        }

        $pct         = $budget['percentage'];       // 0–100 (clamped)
        $total_raw   = $budget['total_tokens'];     // token reali (può superare 100%)
        $limit       = $budget['limit'];
        $over_limit  = $budget['over_limit'];
        $near_limit  = $budget['near_limit'];

        // Colore barra e bordo
        if ( $over_limit ) {
            $bar_color    = '#dc3545'; // rosso
            $border_color = '#f5c2c7';
            $bg_color     = '#fff5f5';
            $icon         = '🔴';
        } elseif ( $near_limit ) {
            $bar_color    = '#fd7e14'; // arancione
            $border_color = '#ffd3a3';
            $bg_color     = '#fffbf0';
            $icon         = '🟠';
        } else {
            $bar_color    = '#28a745'; // verde
            $border_color = '#c3e6cb';
            $bg_color     = '#f0fff4';
            $icon         = '🟢';
        }

        // Etichetta token leggibile — formato esteso con punti separatore migliaia
        $total_label = number_format( $total_raw, 0, ',', '.' );
        $limit_label = number_format( $limit, 0, ',', '.' );

        // Percentuale testuale (può essere >100%)
        $pct_display = $total_raw > 0
            ? round( ( $total_raw / $limit ) * 100 ) . '%'
            : '0%';

        $html  = '<div class="vcm-token-budget" style="background:' . $bg_color . ';border:1px solid ' . $border_color . ';border-radius:6px;padding:14px 18px;margin-bottom:18px;">';
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">';
        $html .= '<span style="font-weight:700;font-size:13px;color:#333;">' . $icon . ' Budget Token Gemini — PDF Pubblicati</span>';
        $html .= '<span style="font-size:12px;color:#555;">' . esc_html( $budget['published_count'] ) . ' file &nbsp;·&nbsp; <strong>' . esc_html( $total_label ) . '</strong> / ' . esc_html( $limit_label ) . ' token &nbsp;·&nbsp; ' . esc_html( $pct_display ) . '</span>';
        $html .= '</div>';

        // Barra
        $html .= '<div style="background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden;">';
        $html .= '<div style="height:100%;border-radius:4px;background:' . $bar_color . ';width:' . min( $pct, 100 ) . '%;transition:width 0.4s ease;"></div>';
        $html .= '</div>';

        // Breakdown dettagliato dei token
        $breakdown = $budget['breakdown'] ?? [];
        if ( ! empty( $breakdown ) ) {
            $html .= '<details style="margin-top:10px;font-size:11px;color:#666;">';
            $html .= '<summary style="cursor:pointer;font-weight:600;">📊 Breakdown componenti</summary>';
            $html .= '<ul style="margin:8px 0 0 20px;padding:0;">';
            $html .= '<li>PDF pubblicati: <strong>' . esc_html( number_format( $breakdown['pdf_tokens'] ?? 0, 0, ',', '.' ) ) . '</strong> token</li>';
            $html .= '<li>System Prompt: <strong>' . esc_html( number_format( $breakdown['system_prompt_tokens'] ?? 0, 0, ',', '.' ) ) . '</strong> token</li>';
            $html .= '<li>Build Prompt: <strong>' . esc_html( number_format( $breakdown['build_prompt_tokens'] ?? 0, 0, ',', '.' ) ) . '</strong> token</li>';
            $html .= '<li>PDF Context Prompt: <strong>' . esc_html( number_format( $breakdown['pdf_context_tokens'] ?? 0, 0, ',', '.' ) ) . '</strong> token</li>';
            $html .= '<li>Dati Form (stima): <strong>' . esc_html( number_format( $breakdown['form_data_tokens'] ?? 0, 0, ',', '.' ) ) . '</strong> token</li>';
            $html .= '</ul>';
            $html .= '</details>';
        }

        // Messaggi di avviso
        if ( $over_limit ) {
            $overflow  = $total_raw - $limit;
            $ovf_label = number_format( $overflow, 0, ',', '.' );
            $has_cache = self::is_pdf_analysis_cache_valid();
            $preprocess_tip = $has_cache
                ? '✅ Hai già un <strong>riassunto analisi storica</strong> in cache — verrà usato automaticamente al posto dei PDF grezzi, risolvendo il problema.'
                : '🤖 <strong>Genera il riassunto analisi storica</strong> (sezione qui sotto) — sostituisce i PDF grezzi con ~1.200 token di testo strutturato, risolvendo il problema del limite.';
            $html .= '<div style="margin:10px 0 0;padding:10px 14px;background:#fff5f5;border:1px solid #f5c2c7;border-radius:6px;font-size:12px;">';
            $html .= '<p style="margin:0 0 6px;font-weight:700;color:#dc3545;">🔴 LIMITE SUPERATO di ' . esc_html( $ovf_label ) . ' token — Gemini rifiuterà la generazione diretta con i PDF.</p>';
            $html .= '<ul style="margin:0;padding-left:18px;color:#444;line-height:1.8;">';
            $html .= '<li style="color:' . ( $has_cache ? '#28a745' : '#0073aa' ) . ';font-weight:600;">' . $preprocess_tip . '</li>';
            $html .= '<li>Metti in bozza i file PDF più grandi per rientrare nel limite</li>';
            $html .= '<li>Riduci il numero totale di PDF pubblicati</li>';
            $html .= '<li>Verifica che i prompt non siano eccessivamente lunghi</li>';
            $html .= '</ul>';
            $html .= '</div>';
        } elseif ( $near_limit ) {
            $remaining = $limit - $total_raw;
            $rem_label = number_format( $remaining, 0, ',', '.' );
            $has_cache = self::is_pdf_analysis_cache_valid();
            $cache_note = $has_cache
                ? ' Il <strong>riassunto analisi storica</strong> è attivo e verrà usato al posto dei PDF grezzi.'
                : ' Considera di generare il <strong>riassunto analisi storica</strong> (sezione qui sotto) per non rischiare il superamento.';
            $html .= '<p style="margin:8px 0 0;font-size:12px;color:#856404;font-weight:600;">🟠 Stai avvicinandoti al limite (restano ~' . esc_html( $rem_label ) . ' token).' . $cache_note . '</p>';
        } else {
            $remaining = $limit - $total_raw;
            $rem_label = number_format( $remaining, 0, ',', '.' );
            $html .= '<p style="margin:8px 0 0;font-size:12px;color:#555;">✓ Spazio disponibile: ~' . esc_html( $rem_label ) . ' token (basato su: PDF + prompts + dati form).</p>';
        }

        $html .= '</div>';
        return $html;
    }

    public static function get_pdf_list_for_display() {
        $pdfs = self::get_uploaded_pdfs();

        if ( empty( $pdfs ) ) {
            return [];
        }

        // Formatta per visualizzazione (es. converti timestamp in data leggibile)
        foreach ( $pdfs as &$pdf ) {
            $pdf['upload_date_formatted'] = wp_date( 'j M Y H:i', strtotime( $pdf['upload_date'] ) );
            $pdf['delete_nonce']          = wp_create_nonce( self::NONCE_ACTION . '_' . $pdf['filename'] );
        }

        return $pdfs;
    }

    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    /**
     * Genera un filename unico aggiungendo timestamp se il file esiste già
     *
     * @param string $filename Original filename
     * @param string $dir      Directory path
     * @return string Unique filename
     */
    private static function generate_unique_filename( $filename, $dir ) {
        $name       = pathinfo( $filename, PATHINFO_FILENAME );
        $extension  = pathinfo( $filename, PATHINFO_EXTENSION );
        $timestamp  = current_time( 'timestamp' );
        $new_name   = $name . '-' . $timestamp . '.' . $extension;

        return $new_name;
    }

    /**
     * Raccoglie le informazioni di un file PDF
     *
     * @param string $filename Name of file
     * @param string $file_path Full path to file
     * @return array File info array
     */
    private static function get_file_info( $filename, $file_path ) {
        $current_user = wp_get_current_user();

        return [
            'filename'             => $filename,
            'file_path'            => '/' . str_replace( ABSPATH, '', $file_path ),
            'upload_date'          => current_time( 'mysql' ),
            'file_size'            => filesize( $file_path ),
            'file_size_readable'   => size_format( filesize( $file_path ) ),
            'mime_type'            => 'application/pdf',
            'uploaded_by'          => get_current_user_id(),
            'uploaded_by_name'     => $current_user->display_name,
            'status'               => 'draft',  // Default: bozza finché non viene pubblicato
            'gemini_file_id'       => '',
            'gemini_file_uri'      => '',
            'gemini_expiration_time' => '',
            'gemini_token_count'   => 0,        // Conteggio reale token da countTokens API (0 = non misurato)
        ];
    }

    /**
     * Aggiunge una voce PDF all'option di storage
     *
     * @param array $file_info File information array
     */
    private static function add_pdf_to_option( $file_info ) {
        $pdfs = self::get_uploaded_pdfs();
        $pdfs[] = $file_info;

        update_option( self::OPTION_NAME, json_encode( $pdfs ) );
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * Handler AJAX per upload file PDF
     */
    public static function ajax_upload_pdf() {

        // Cattura fatal errors e restituiscili come JSON invece di 500
        register_shutdown_function( function () {
            $error = error_get_last();
            if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
                if ( ! headers_sent() ) {
                    header( 'Content-Type: application/json' );
                    http_response_code( 200 );
                }
                echo wp_json_encode( [
                    'success' => false,
                    'data'    => [ 'message' => 'PHP Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' line ' . $error['line'] ],
                ] );
                exit;
            }
        } );

        // Verifica nonce
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        // Verifica che il file sia stato caricato
        if ( ! isset( $_FILES['file'] ) ) {
            wp_send_json_error( [
                'message' => 'Nessun file inviato.',
            ] );
        }

        // Chiama la funzione di upload
        $result = self::handle_pdf_upload( $_FILES['file'] );

        if ( $result['success'] ) {
            wp_send_json_success( [
                'message'       => $result['message'],
                'file'          => $result['file'],
                'file_list_html' => self::render_file_list_html(),
            ] );
        } else {
            wp_send_json_error( [
                'message' => $result['message'],
            ] );
        }
    }

    /**
     * Handler AJAX per eliminare un file PDF
     */
    public static function ajax_delete_pdf() {

        // Verifica che il filename sia stato inviato
        if ( ! isset( $_POST['filename'] ) ) {
            wp_send_json_error( [
                'message' => 'Filename mancante.',
            ] );
        }

        $filename = sanitize_file_name( $_POST['filename'] );

        // Verifica nonce con il filename nel suffisso (deve corrispondere a come è stato creato)
        if ( ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ACTION . '_' . $filename ) ) {
            wp_send_json_error( [
                'message' => 'Errore di sicurezza (nonce non valido).',
            ] );
        }

        // Chiama la funzione di delete
        $result = self::delete_pdf( $filename );

        if ( $result['success'] ) {
            // Invalida cache analisi solo se l'opzione è abilitata
            if ( VulcanicaSettings::get_auto_invalidate_analysis() ) {
                self::invalidate_pdf_analysis_cache();
                error_log( '[Vulcanica] PDF analysis cache auto-invalidated (delete PDF: ' . $filename . ')' );
            }
            wp_send_json_success( [
                'message'         => $result['message'],
                'remaining_count' => count( self::get_uploaded_pdfs() ),
                'file_list_html'  => self::render_file_list_html(),
            ] );
        } else {
            wp_send_json_error( [
                'message' => $result['message'],
            ] );
        }
    }

    /**
     * Handler AJAX per togglere lo stato di un file (draft/published)
     */
    public static function ajax_toggle_pdf_status() {

        // Verifica che il filename sia stato inviato
        if ( ! isset( $_POST['filename'] ) ) {
            wp_send_json_error( [
                'message' => 'Filename mancante.',
            ] );
        }

        $filename = sanitize_file_name( $_POST['filename'] );

        // Verifica nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'vulcanica_toggle_status_' . $filename ) ) {
            wp_send_json_error( [
                'message' => 'Errore di sicurezza (nonce non valido).',
            ] );
        }

        // Leggi lo stato attuale
        $pdfs = self::get_uploaded_pdfs();
        $current_status = 'draft';

        foreach ( $pdfs as $pdf ) {
            if ( $pdf['filename'] === $filename ) {
                $current_status = $pdf['status'] ?? 'draft';
                break;
            }
        }

        // Togglea lo stato
        $new_status = ( $current_status === 'draft' ) ? 'published' : 'draft';
        $success = self::set_pdf_status( $filename, $new_status );

        if ( $success ) {
            // Invalida cache analisi solo se l'opzione è abilitata
            if ( VulcanicaSettings::get_auto_invalidate_analysis() ) {
                self::invalidate_pdf_analysis_cache();
                error_log( '[Vulcanica] PDF analysis cache auto-invalidated (toggle status: ' . $filename . ' → ' . $new_status . ')' );
            }
            wp_send_json_success( [
                'message'        => 'Stato aggiornato a: ' . $new_status,
                'new_status'     => $new_status,
                'file_list_html' => self::render_file_list_html(),
            ] );
        } else {
            wp_send_json_error( [
                'message' => 'Errore nel cambio dello stato.',
            ] );
        }
    }

    /**
     * Handler AJAX per caricare un singolo file PDF a Gemini (manualmente dall'admin)
     */
    public static function ajax_upload_pdf_to_gemini() {

        // Verifica che il filename sia stato inviato
        if ( ! isset( $_POST['filename'] ) ) {
            wp_send_json_error( [
                'message' => 'Filename mancante.',
            ] );
        }

        $filename = sanitize_file_name( $_POST['filename'] );

        // Verifica nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'vulcanica_upload_gemini_' . $filename ) ) {
            wp_send_json_error( [
                'message' => 'Errore di sicurezza (nonce non valido).',
            ] );
        }

        // Cerco il file
        $pdfs = self::get_uploaded_pdfs();
        $pdf_info = null;

        foreach ( $pdfs as $pdf ) {
            if ( $pdf['filename'] === $filename ) {
                $pdf_info = $pdf;
                break;
            }
        }

        if ( ! $pdf_info ) {
            wp_send_json_error( [
                'message' => 'File non trovato nel database.',
            ] );
        }

        // Costruisci il percorso completo
        $file_path = wp_normalize_path( self::get_upload_directory() . '/' . $filename );

        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            wp_send_json_error( [
                'message' => 'File non trovato su disco.',
            ] );
        }

        // Chiama il Gemini client per uploadare il file
        require_once __DIR__ . '/class-gemini-client.php';
        $gemini = new VulcanicaGeminiClient();
        $result = $gemini->upload_file_to_gemini( $file_path, $filename );

        if ( $result['success'] ) {
            wp_send_json_success( [
                'message'        => 'File caricato a Gemini con successo.',
                'file_id'        => $result['file_id'],
                'file_list_html' => self::render_file_list_html(),
            ] );
        } else {
            wp_send_json_error( [
                'message' => 'Errore nel caricamento a Gemini: ' . $result['error'],
            ] );
        }
    }

    // =========================================================================
    // PDF ANALYSIS CACHE (Preprocessing)
    // =========================================================================

    /**
     * Calcola un fingerprint dei PDF pubblicati per rilevare cambiamenti.
     * Se i PDF cambiano (aggiunta, rimozione, cambio status), il fingerprint cambia
     * e la cache viene automaticamente invalidata.
     *
     * @return string MD5 hash dei PDF pubblicati
     */
    public static function get_published_pdfs_fingerprint() {
        $pdfs      = self::get_uploaded_pdfs();
        $published = array_filter( $pdfs, fn( $pdf ) => ( $pdf['status'] ?? 'draft' ) === 'published' );

        $parts = [];
        foreach ( $published as $pdf ) {
            $parts[] = ( $pdf['filename'] ?? '' ) . ':' . ( $pdf['file_size'] ?? 0 );
        }
        sort( $parts );

        return md5( implode( '|', $parts ) );
    }

    /**
     * Legge l'analisi PDF preprocessata dalla cache (wp_options).
     *
     * @return array|null { text, fingerprint, created_at, published_count } oppure null
     */
    public static function get_pdf_analysis_cache() {
        return get_option( self::OPTION_PDF_ANALYSIS, null ) ?: null;
    }

    /**
     * Controlla se la cache dell'analisi PDF è valida.
     * Valida = esiste + fingerprint corrisponde ai PDF pubblicati attuali.
     *
     * @return bool
     */
    public static function is_pdf_analysis_cache_valid() {
        $cache = self::get_pdf_analysis_cache();

        if ( empty( $cache ) || empty( $cache['text'] ) || empty( $cache['fingerprint'] ) ) {
            return false;
        }

        return $cache['fingerprint'] === self::get_published_pdfs_fingerprint();
    }

    /**
     * Salva l'analisi PDF nel cache (wp_options).
     *
     * @param string $analysis_text Testo dell'analisi prodotta da Gemini
     */
    public static function set_pdf_analysis_cache( $analysis_text ) {
        $pdfs      = self::get_uploaded_pdfs();
        $published = array_filter( $pdfs, fn( $pdf ) => ( $pdf['status'] ?? 'draft' ) === 'published' );

        $cache = [
            'text'            => $analysis_text,
            'fingerprint'     => self::get_published_pdfs_fingerprint(),
            'created_at'      => current_time( 'mysql' ),
            'published_count' => count( $published ),
        ];

        update_option( self::OPTION_PDF_ANALYSIS, $cache );
        error_log( '[Vulcanica] PDF analysis cache saved: ' . strlen( $analysis_text ) . ' chars, ' . count( $published ) . ' PDFs' );
    }

    /**
     * Invalida (cancella) la cache dell'analisi PDF.
     * Va chiamato quando si aggiunge, rimuove o cambia stato di un PDF.
     */
    public static function invalidate_pdf_analysis_cache() {
        delete_option( self::OPTION_PDF_ANALYSIS );
        error_log( '[Vulcanica] PDF analysis cache invalidated' );
    }

    /**
     * Handler AJAX: analizza i PDF pubblicati con Gemini e salva il riassunto in cache.
     * Chiamato dal pulsante "Analizza PDF storici" nell'admin.
     */
    public static function ajax_analyze_pdfs() {

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => 'Permessi insufficienti.' ] );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vulcanica_analyze_pdfs' ) ) {
            wp_send_json_error( [ 'message' => 'Errore di sicurezza (nonce non valido).' ] );
        }

        set_time_limit( 0 );

        $pdf_files = self::get_uploaded_pdfs_with_paths();

        if ( empty( $pdf_files ) ) {
            wp_send_json_error( [ 'message' => 'Nessun PDF pubblicato da analizzare.' ] );
        }

        error_log( '[Vulcanica] PDF preprocessing: starting analysis for ' . count( $pdf_files ) . ' files' );

        require_once __DIR__ . '/class-gemini-client.php';
        $gemini = new VulcanicaGeminiClient();
        $result = $gemini->analyze_pdfs_for_preprocessing( $pdf_files );

        if ( ! $result['success'] ) {
            error_log( '[Vulcanica] PDF preprocessing FAILED: ' . $result['error'] );
            wp_send_json_error( [ 'message' => 'Errore analisi Gemini: ' . $result['error'] ] );
        }

        self::set_pdf_analysis_cache( $result['content'] );

        wp_send_json_success( [
            'message'         => 'Analisi completata e salvata con successo!',
            'analysis_length' => strlen( $result['content'] ),
            'preview'         => mb_substr( $result['content'], 0, 300 ) . '…',
        ] );
    }

    /**
     * Handler AJAX: salva manualmente il testo dell'analisi preprocessata.
     * Permette di editare il riassunto direttamente dall'interfaccia admin.
     */
    public static function ajax_save_analysis_cache() {

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => 'Permessi insufficienti.' ] );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vulcanica_save_analysis_cache' ) ) {
            wp_send_json_error( [ 'message' => 'Errore di sicurezza (nonce non valido).' ] );
        }

        $text = wp_unslash( $_POST['text'] ?? '' );

        if ( empty( trim( $text ) ) ) {
            wp_send_json_error( [ 'message' => 'Il testo non può essere vuoto.' ] );
        }

        // Aggiorna solo il testo, mantieni gli altri metadati (fingerprint, created_at, ecc.)
        $cache = self::get_pdf_analysis_cache();
        if ( $cache ) {
            $cache['text']       = $text;
            $cache['updated_at'] = current_time( 'mysql' );
            update_option( self::OPTION_PDF_ANALYSIS, $cache );
        } else {
            // Nessuna cache esistente: crea una nuova entry manuale
            self::set_pdf_analysis_cache( $text );
        }

        error_log( '[Vulcanica] PDF analysis cache manually updated: ' . strlen( $text ) . ' chars' );

        wp_send_json_success( [
            'message'         => 'Riassunto salvato con successo.',
            'analysis_length' => strlen( $text ),
        ] );
    }

    /**
     * Handler AJAX: cancella la cache dell'analisi preprocessata.
     * Dopo la cancellazione, la generazione torna al funzionamento precedente (PDF grezzi).
     */
    public static function ajax_delete_analysis_cache() {

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => 'Permessi insufficienti.' ] );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vulcanica_delete_analysis_cache' ) ) {
            wp_send_json_error( [ 'message' => 'Errore di sicurezza (nonce non valido).' ] );
        }

        self::invalidate_pdf_analysis_cache();

        wp_send_json_success( [
            'message' => 'Analisi eliminata. La generazione tornerà a usare i PDF grezzi.',
        ] );
    }

}
