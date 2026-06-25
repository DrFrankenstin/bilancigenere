<?php
/**
 * Handler AJAX per processare il form e inviare a Gemini
 */

class VulcanicaAJAXHandler {

    public static function init() {
        // AJAX per utenti non autenticati
        add_action( 'wp_ajax_nopriv_vulcanica_process_form', [ __CLASS__, 'process_form' ] );
        // AJAX per utenti autenticati
        add_action( 'wp_ajax_vulcanica_process_form', [ __CLASS__, 'process_form' ] );
    }

    /**
     * Processo principale AJAX
     * Riceve item_id → Aggrega dati → Invia a Gemini → Crea CPT
     */
    public static function process_form() {

        // Verifica nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vulcanica_form_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Errore di sicurezza (nonce non valido)' ] );
        }

        // Recupera item_id
        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;

        if ( ! $item_id ) {
            wp_send_json_error( [ 'message' => 'Item ID mancante' ] );
        }

        // Nessun timeout PHP — Gemini può richiedere fino a 60s+
        set_time_limit( 0 );

        // STEP 1: Aggrega dati dall'item
        error_log( "[Vulcanica] AJAX: Inizio elaborazione item $item_id" );

        $aggregated = VulcanicaDataAggregator::aggregate_item_data( $item_id );

        if ( is_wp_error( $aggregated ) ) {
            error_log( "[Vulcanica] AJAX: Errore aggregazione - " . $aggregated->get_error_message() );
            wp_send_json_error( [ 'message' => 'Errore nel recupero dati: ' . $aggregated->get_error_message() ] );
        }

        // STEP 2a: Estrai file allegati dal form (CSV e PDF bilanci comunali)
        $uploaded_files  = VulcanicaDataAggregator::extract_uploaded_files( $aggregated );
        $csv_analysis    = '';
        $pdf_com_analysis = '';

        // STEP 2b: Pre-analisi CSV dati quantitativi (se presente)
        if ( ! empty( $uploaded_files['csv_file'] ) ) {
            $csv_info = $uploaded_files['csv_file'];
            error_log( "[Vulcanica] AJAX: Pre-analisi CSV - {$csv_info['filename']}" );
            $csv_content = file_get_contents( $csv_info['file_path'] );
            if ( $csv_content !== false ) {
                $csv_prompt   = VulcanicaSettings::get_csv_pre_analysis_prompt()
                    . "\n\nCSV:\n"
                    . mb_substr( $csv_content, 0, 80000 ); // limite sicuro token
                $gemini_csv   = new VulcanicaGeminiClient();
                $csv_response = $gemini_csv->generate( $csv_prompt, [
                    'system_prompt' => 'Sei un analista di dati esperto in parità di genere nelle pubbliche amministrazioni.',
                ] );
                if ( $csv_response['success'] ) {
                    $csv_analysis = $csv_response['content'];
                    error_log( '[Vulcanica] AJAX: Pre-analisi CSV completata (' . strlen( $csv_analysis ) . ' chars)' );
                } else {
                    error_log( '[Vulcanica] AJAX: Pre-analisi CSV fallita - ' . $csv_response['error'] );
                }
            }
        }

        // STEP 2c: Pre-analisi PDF bilanci comunali (se presenti)
        $pdf_comunali = array_filter( [
            $uploaded_files['pdf_bilancio_1'],
            $uploaded_files['pdf_bilancio_2'],
        ] );

        if ( ! empty( $pdf_comunali ) ) {
            $names = implode( ', ', array_column( $pdf_comunali, 'filename' ) );
            error_log( "[Vulcanica] AJAX: Pre-analisi PDF comunali - $names" );
            $gemini_pdf_com   = new VulcanicaGeminiClient();
            $pdf_com_response = $gemini_pdf_com->generate(
                VulcanicaSettings::get_pdf_comunale_pre_analysis_prompt(),
                [
                    'system_prompt' => 'Sei un esperto di finanza pubblica e bilanci comunali con focus sulla parità di genere.',
                    'pdf_files'     => array_values( $pdf_comunali ),
                ]
            );
            if ( $pdf_com_response['success'] ) {
                $pdf_com_analysis = $pdf_com_response['content'];
                error_log( '[Vulcanica] AJAX: Pre-analisi PDF comunali completata (' . strlen( $pdf_com_analysis ) . ' chars)' );
            } else {
                error_log( '[Vulcanica] AJAX: Pre-analisi PDF comunali fallita - ' . $pdf_com_response['error'] );
            }
        }

        // STEP 3: Contesto storico PDF storici — usa cache preprocessing se disponibile
        $pdf_files         = [];
        $pdf_analysis_text = '';

        require_once __DIR__ . '/class-pdf-manager.php';

        if ( VulcanicaPDFManager::is_pdf_analysis_cache_valid() ) {
            $cache             = VulcanicaPDFManager::get_pdf_analysis_cache();
            $pdf_analysis_text = $cache['text'];
            error_log( '[Vulcanica] AJAX: Using preprocessed PDF analysis from cache ('
                . strlen( $pdf_analysis_text ) . ' chars, '
                . ( $cache['published_count'] ?? '?' ) . ' PDFs, created: ' . ( $cache['created_at'] ?? '?' ) . ')' );
        } else {
            $pdf_files = VulcanicaPDFManager::get_uploaded_pdfs_with_paths();
            error_log( '[Vulcanica] AJAX: No PDF analysis cache — using raw PDFs ('
                . count( $pdf_files ) . ' files). Consider running preprocessing first.' );
        }

        // STEP 4: Costruisce il prompt principale includendo i risultati di pre-analisi
        $prompt = self::build_prompt( $aggregated, $pdf_files, $pdf_analysis_text, $csv_analysis, $pdf_com_analysis );

        // STEP 5: Invia a Gemini API per la generazione del bilancio completo
        $gemini   = new VulcanicaGeminiClient();
        $response = $gemini->generate( $prompt, [
            'system_prompt' => self::get_system_prompt(),
            'pdf_files'     => $pdf_files,
        ] );

        if ( ! $response['success'] ) {
            error_log( "[Vulcanica] AJAX: Errore Gemini - " . $response['error'] );
            wp_send_json_error( [ 'message' => 'Errore API Gemini: ' . $response['error'] ] );
        }

        $ai_result = $response['content'];

        // STEP 4b: Sostituisce i segnaposto %%...%% con i testi fissi dalle impostazioni
        $ai_result = VulcanicaSettings::apply_content_placeholders( $ai_result );

        // STEP 5: Crea CPT con la risposta
        $cpt_id = VulcanicaCPTBilancio::create_from_ai(
            $item_id,
            $ai_result,
            get_current_user_id(),
            [
                'form_id' => $aggregated['form_id'],
                'aggregate_data' => wp_json_encode( $aggregated ),
            ]
        );

        if ( is_wp_error( $cpt_id ) ) {
            error_log( "[Vulcanica] AJAX: Errore creazione CPT - " . $cpt_id->get_error_message() );
            wp_send_json_error( [ 'message' => 'Errore nella creazione del documento' ] );
        }

        // STEP 6: Aggiorna stato item
        VulcanicaCPTBilancio::update_item_status( $item_id, 'elaborato', [
            'cpt_id' => $cpt_id,
        ] );

        error_log( "[Vulcanica] AJAX: Elaborazione completata - Item $item_id → CPT $cpt_id" );

        // STEP 7: Ritorna success con URL del nuovo post
        wp_send_json_success( [
            'message' => 'Bilancio generato con successo!',
            'post_id' => $cpt_id,
            'post_url' => get_permalink( $cpt_id ),
            'edit_url' => get_edit_post_link( $cpt_id, 'raw' ),
        ] );
    }

    /**
     * Costruisce il prompt per Gemini basato sui dati aggregati.
     *
     * Logica contesto storico PDF (priorità):
     *   1. $pdf_analysis_text (testo preprocessato da cache) — token ridotti, preferito
     *   2. $pdf_files (PDF grezzi via Gemini Files API) — fallback se no cache
     *
     * @param array  $aggregated       Output di aggregate_item_data()
     * @param array  $pdf_files        Array di PDF caricati (usato solo se no cache)
     * @param string $pdf_analysis_text Testo riassuntivo preanalizzato da Gemini (da cache)
     * @return string
     */
    public static function build_prompt( $aggregated, $pdf_files = [], $pdf_analysis_text = '', $csv_analysis = '', $pdf_com_analysis = '' ) {

        $data_section = VulcanicaDataAggregator::format_for_prompt( $aggregated );

        // Sezione contesto storico (PDF bilanci di genere precedenti)
        $pdf_section = '';
        if ( ! empty( $pdf_analysis_text ) ) {
            $pdf_section  = "\n\n## CONTESTO STORICO — Analisi Bilanci di Genere Precedenti\n\n";
            $pdf_section .= $pdf_analysis_text;
            $pdf_section .= "\n\n---\n";
        } elseif ( ! empty( $pdf_files ) ) {
            $pdf_section = "\n\n" . VulcanicaSettings::get_pdf_context_prompt( $pdf_files );
        }

        // Sezione pre-analisi CSV dati quantitativi personale
        $csv_section = '';
        if ( ! empty( $csv_analysis ) ) {
            $csv_section  = "\n\n## DATI QUANTITATIVI PERSONALE — Analisi CSV Allegato\n\n";
            $csv_section .= "I seguenti dati provengono dall'analisi di un file CSV con indicatori quantitativi "
                         . "sul personale dell'ente, fornito direttamente dall'amministrazione comunale. "
                         . "Usali come dati primari e precisi per la sezione interna sull'organico.\n\n";
            $csv_section .= $csv_analysis;
            $csv_section .= "\n\n---\n";
        }

        // Sezione pre-analisi PDF bilanci comunali generici
        $pdf_com_section = '';
        if ( ! empty( $pdf_com_analysis ) ) {
            $pdf_com_section  = "\n\n## BILANCI COMUNALI — Analisi Documenti Finanziari Allegati\n\n";
            $pdf_com_section .= "I seguenti elementi provengono dall'analisi dei bilanci comunali (previsione/consuntivo) "
                             . "allegati dall'amministrazione. Usali per contestualizzare le risorse disponibili "
                             . "e le scelte di spesa rispetto alla parità di genere.\n\n";
            $pdf_com_section .= $pdf_com_analysis;
            $pdf_com_section .= "\n\n---\n";
        }

        $instructions = "\n\n" . VulcanicaSettings::get_build_prompt();

        return $data_section . $pdf_section . $csv_section . $pdf_com_section . $instructions;
    }

    /**
     * System prompt per Gemini — recuperato dalle impostazioni del plugin
     *
     * @return string
     */
    public static function get_system_prompt() {
        return VulcanicaSettings::get_system_prompt();
    }
}

// Registra l'handler AJAX
add_action( 'wp_loaded', [ 'VulcanicaAJAXHandler', 'init' ] );
?>
