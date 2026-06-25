<?php
/**
 * Meta box "Sintesi AI" per il CPT bilancio_genere.
 *
 * Aggiunge un pulsante nella sidebar dell'editor che invia il contenuto
 * completo del bilancio a Gemini e riceve una sintesi esecutiva, poi la
 * inserisce in testa al documento tramite TinyMCE (lato JS).
 *
 * Il blocco sintesi è delimitato da commenti HTML per permettere
 * la sostituzione senza regex fragili sul markup interno:
 *   <!-- vcm-sintesi-start --> ... <!-- vcm-sintesi-end -->
 */

class VulcanicaAISummary {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );
        add_action( 'wp_ajax_vulcanica_generate_summary', [ __CLASS__, 'ajax_generate_summary' ] );
        add_action( 'wp_head', [ __CLASS__, 'frontend_styles' ] );
    }

    // -------------------------------------------------------------------------
    // Meta box
    // -------------------------------------------------------------------------

    public static function register_meta_box() {
        add_meta_box(
            'vcm-ai-summary',
            '✨ Sintesi AI',
            [ __CLASS__, 'render_meta_box' ],
            'bilancio_genere',
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        ?>
        <p class="description" style="margin-bottom:12px;font-size:12px;">
            Genera un riassunto esecutivo del bilancio tramite Gemini e inseriscilo in testa al documento.
        </p>
        <button type="button" id="vcm-generate-summary-btn" class="button button-primary" style="width:100%;">
            ✨ Genera sintesi AI
        </button>
        <div id="vcm-summary-status" style="margin-top:10px;font-size:12px;display:none;"></div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handler
    // -------------------------------------------------------------------------

    public static function ajax_generate_summary() {

        if ( ! check_ajax_referer( 'vcm_summary_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Errore di sicurezza' ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permessi insufficienti' ] );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Post ID mancante' ] );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'bilancio_genere' ) {
            wp_send_json_error( [ 'message' => 'Post non valido' ] );
        }

        // Rimuovi l'eventuale blocco sintesi esistente prima di mandarlo a Gemini
        // (evita di riassumere il riassunto)
        $content = preg_replace(
            '/<!-- vcm-sintesi-start -->[\s\S]*?<!-- vcm-sintesi-end -->\s*/i',
            '',
            $post->post_content
        );

        // Testo puro — riduce drasticamente i token
        $plain_text = wp_strip_all_tags( $content );
        $plain_text = preg_replace( '/\s+/', ' ', trim( $plain_text ) );

        if ( empty( $plain_text ) ) {
            wp_send_json_error( [ 'message' => 'Il bilancio è vuoto' ] );
        }

        set_time_limit( 0 );

        $prompt = "Di seguito è riportato il testo completo di un Bilancio di Genere comunale.\n\n"
            . "Genera una sintesi esecutiva chiara in italiano, di circa 200-300 parole, che:\n"
            . "- Descriva i principali risultati e indicatori emersi\n"
            . "- Evidenzi le disparità di genere più significative\n"
            . "- Menzioni le aree prioritarie di intervento\n"
            . "- Sia adatta come introduzione al documento completo\n\n"
            . "Scrivi solo paragrafi di testo continuo, senza elenchi puntati, senza titoli, senza markdown.\n\n"
            . "TESTO DEL BILANCIO:\n"
            . mb_substr( $plain_text, 0, 30000 );

        $gemini   = new VulcanicaGeminiClient();
        $response = $gemini->generate( $prompt, [
            'system_prompt' => 'Sei un esperto di politiche di genere e bilanci di genere comunali. '
                . 'Scrivi sintesi chiare, professionali e accessibili in italiano.',
        ] );

        if ( ! $response['success'] ) {
            error_log( '[Vulcanica] AI Summary error: ' . $response['error'] );
            wp_send_json_error( [ 'message' => 'Errore Gemini: ' . $response['error'] ] );
        }

        $summary_text = trim( $response['content'] );

        // Converti doppie newline in paragrafi HTML
        $paragraphs = array_filter( array_map( 'trim', explode( "\n\n", $summary_text ) ) );
        $html_body  = implode( '', array_map( function ( $p ) {
            return '<p>' . esc_html( $p ) . '</p>' . "\n";
        }, $paragraphs ) );

        $summary_html = "<!-- vcm-sintesi-start -->\n"
            . '<div class="vcm-sintesi-bilancio">' . "\n"
            . '<h2 class="vcm-sintesi-titolo">Sintesi del Bilancio di Genere</h2>' . "\n"
            . $html_body
            . '</div>' . "\n"
            . "<!-- vcm-sintesi-end -->\n\n";

        error_log( '[Vulcanica] AI Summary generated for post ' . $post_id . ' (' . strlen( $summary_html ) . ' chars)' );

        wp_send_json_success( [ 'summary_html' => $summary_html ] );
    }

    // -------------------------------------------------------------------------
    // CSS frontend — caricato solo sulle pagine del CPT
    // -------------------------------------------------------------------------

    public static function frontend_styles() {
        if ( ! is_singular( 'bilancio_genere' ) ) {
            return;
        }
        ?>
        <style id="vcm-sintesi-styles">
            .vcm-sintesi-bilancio {
                background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
                border-left: 4px solid #0073aa;
                border-radius: 6px;
                padding: 24px 28px;
                margin-bottom: 36px;
            }
            .vcm-sintesi-titolo {
                margin-top: 0;
                margin-bottom: 16px;
                color: #0073aa;
                font-size: 1.25em;
            }
            .vcm-sintesi-bilancio p {
                line-height: 1.75;
                margin-bottom: 14px;
                color: #2c3e50;
            }
            .vcm-sintesi-bilancio p:last-child {
                margin-bottom: 0;
            }
        </style>
        <?php
    }
}

add_action( 'init', [ 'VulcanicaAISummary', 'init' ] );
