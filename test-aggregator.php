<?php
/**
 * TEST SCRIPT — Aggregatore + Gemini AI + CPT
 * Accedi: http://verso/wp-content/plugins/vulcanica-comuni-manager/test-aggregator.php
 *
 * Sezioni:
 *   1. Field Map       — mappatura campi form 8
 *   2. Items           — invii disponibili
 *   3. Aggregazione    — dati aggregati per item
 *   4. Prompt          — prompt generato
 *   5. Gemini + CPT    — invia a Gemini e crea CPT
 *
 * ELIMINARE questo file dopo i test!
 */

require_once dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';
require_once __DIR__ . '/class-settings.php';
require_once __DIR__ . '/class-data-aggregator.php';
require_once __DIR__ . '/class-gemini-client.php';
require_once __DIR__ . '/class-cpt-bilancio.php';
require_once __DIR__ . '/class-ajax-handler.php';

// Sicurezza: solo admin o localhost
if ( ! current_user_can( 'administrator' ) && ! in_array( $_SERVER['REMOTE_ADDR'], [ '127.0.0.1', '::1' ] ) ) {
    die( 'Accesso negato' );
}

/**
 * Sanitizzazione: escapa i tag HTML in un testo per evitare problemi di injection
 * Converte: < → &lt;   > → &gt;   & → &amp;
 * @param string $text Testo con possibili tag HTML
 * @return string Testo con tag HTML escapati
 */
function sanitize_html_tags( $text ) {
    return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

$section = $_GET['section'] ?? 'all';
$item_id = intval( $_GET['item_id'] ?? 4 );

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Test Aggregator</title>
<style>
* { box-sizing: border-box; }
body  { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
h1   { color: #4ec9b0; }
h2   { color: #9cdcfe; border-bottom: 1px solid #333; padding-bottom: 5px; }
h3   { color: #dcdcaa; }
.ok   { color: #4ec9b0; }
.err  { color: #f44747; }
.warn { color: #ce9178; }
pre  { background: #252526; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; }
th   { background: #252526; color: #9cdcfe; padding: 8px; text-align: left; }
td   { padding: 6px 8px; border-bottom: 1px solid #333; font-size: 12px; }
td:first-child { color: #b5cea8; }
.nav { margin: 20px 0; }
.nav a { color: #4fc1ff; margin-right: 15px; text-decoration: none; }
.nav a:hover { text-decoration: underline; }
.nav a.active { color: #4ec9b0; font-weight: bold; border-bottom: 1px solid #4ec9b0; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; }
.badge-narrative { background: #1e4d2b; color: #4ec9b0; }
.badge-json      { background: #1e3a5f; color: #4fc1ff; }
.badge-radio     { background: #4d2b1e; color: #ce9178; }
.badge-numeric   { background: #3a1e5f; color: #c586c0; }
.badge-text      { background: #333;    color: #d4d4d4; }
.badge-skip      { background: #2a2a2a; color: #666; }
.btn { display: inline-block; padding: 8px 18px; border-radius: 4px; border: none; cursor: pointer; font-family: monospace; font-size: 13px; text-decoration: none; }
.btn-primary  { background: #0e639c; color: #fff; }
.btn-primary:hover  { background: #1177bb; }
.btn-success  { background: #1e4d2b; color: #4ec9b0; border: 1px solid #4ec9b0; }
.btn-success:hover  { background: #2a6e3f; }
.btn-danger   { background: #4d1e1e; color: #f44747; border: 1px solid #f44747; }
.info-box { background: #252526; border-left: 3px solid #4fc1ff; padding: 12px 16px; margin: 12px 0; border-radius: 0 4px 4px 0; }
.ai-response { background: #1a2535; border: 1px solid #1e3a5f; padding: 20px; border-radius: 4px; margin: 15px 0; line-height: 1.6; font-family: sans-serif; font-size: 14px; }
.ai-response h2, .ai-response h3 { color: #9cdcfe; }
.ai-response ul { padding-left: 20px; }
.ai-response li { margin: 4px 0; }
.meta-row { display: flex; gap: 20px; flex-wrap: wrap; margin: 10px 0; }
.meta-item { background: #252526; padding: 8px 14px; border-radius: 4px; font-size: 12px; }
.meta-item strong { color: #9cdcfe; display: block; margin-bottom: 3px; }
.spinner { display: inline-block; animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>

<h1>🔬 Test Aggregator — Form 8</h1>

<div class="nav">
    <a href="?section=all&item_id=<?= $item_id ?>">Tutti i test</a>
    <a href="?section=fieldmap&item_id=<?= $item_id ?>" <?= $section==='fieldmap' ? 'class="active"' : '' ?>>1. Field Map</a>
    <a href="?section=items&item_id=<?= $item_id ?>" <?= $section==='items' ? 'class="active"' : '' ?>>2. Items disponibili</a>
    <a href="?section=aggregate&item_id=<?= $item_id ?>" <?= $section==='aggregate' ? 'class="active"' : '' ?>>3. Aggregazione</a>
    <a href="?section=prompt&item_id=<?= $item_id ?>" <?= $section==='prompt' ? 'class="active"' : '' ?>>4. Prompt generato</a>
    <a href="?section=gemini&item_id=<?= $item_id ?>" <?= $section==='gemini' ? 'class="active"' : '' ?>>5. 🤖 Gemini + CPT</a>
</div>

<?php

// =========================================================================
// TEST 1: FIELD MAP
// =========================================================================

if ( $section === 'all' || $section === 'fieldmap' ) :

    echo "<h2>TEST 1 — Field Map (mappatura campi)</h2>";

    VulcanicaDataAggregator::flush_field_map();
    $map = VulcanicaDataAggregator::build_field_map();

    if ( empty( $map ) ) {
        echo "<p class='err'>❌ Mappa vuota! Controlla che i campi del form 8 esistano.</p>";
    } else {
        $counts = [];
        foreach ( $map as $fid => $def ) {
            $t = $def['treatment'];
            $counts[$t] = ( $counts[$t] ?? 0 ) + 1;
        }

        echo "<p class='ok'>✅ Mappatura costruita: <strong>" . count($map) . " campi</strong></p>";
        echo "<p>Distribuzione per treatment: ";
        foreach ( $counts as $t => $n ) {
            echo "<span class='badge badge-{$t}'>{$t}: {$n}</span> ";
        }
        echo "</p>";

        echo "<table>";
        echo "<tr><th>field_id</th><th>field_key</th><th>name</th><th>type</th><th>treatment</th></tr>";
        foreach ( $map as $fid => $def ) {
            $t = $def['treatment'];
            echo "<tr>
                <td>{$fid}</td>
                <td style='color:#6a9955'>{$def['field_key']}</td>
                <td>{$def['name']}</td>
                <td>{$def['type']}</td>
                <td><span class='badge badge-{$t}'>{$t}</span></td>
            </tr>";
        }
        echo "</table>";

        $unexpected = array_filter( $map, fn($d) => $d['treatment'] === 'text' );
        if ( ! empty( $unexpected ) ) {
            echo "<p class='warn'>⚠️ " . count($unexpected) . " campi con treatment 'text' — verifica se sono corretti:</p>";
            echo "<table><tr><th>field_id</th><th>field_key</th><th>name</th></tr>";
            foreach ( $unexpected as $fid => $def ) {
                echo "<tr><td>{$fid}</td><td>{$def['field_key']}</td><td>{$def['name']}</td></tr>";
            }
            echo "</table>";
        }
    }

endif;

// =========================================================================
// TEST 2: ITEMS DISPONIBILI
// =========================================================================

if ( $section === 'all' || $section === 'items' ) :

    global $wpdb;
    echo "<h2>TEST 2 — Items Form 8 disponibili</h2>";

    $items = $wpdb->get_results(
        "SELECT i.id, i.created_at, i.user_id, COUNT(m.id) as meta_count
         FROM {$wpdb->prefix}frm_items i
         LEFT JOIN {$wpdb->prefix}frm_item_metas m ON m.item_id = i.id
         WHERE i.form_id = 8
         GROUP BY i.id
         ORDER BY i.id DESC"
    );

    if ( empty( $items ) ) {
        echo "<p class='err'>❌ Nessun invio trovato per il form 8.</p>";
    } else {
        echo "<p class='ok'>✅ " . count($items) . " invii trovati</p>";
        echo "<table>";
        echo "<tr><th>item_id</th><th>user_id</th><th>created_at</th><th>n. metas</th><th>azioni</th></tr>";
        foreach ( $items as $item ) {
            // Controlla se esiste già un CPT per questo item
            $existing_cpt = VulcanicaCPTBilancio::get_by_item( $item->id );
            $cpt_badge = $existing_cpt
                ? "<a href='" . get_edit_post_link( $existing_cpt->ID, 'raw' ) . "' style='color:#4ec9b0'>📄 CPT #{$existing_cpt->ID}</a>"
                : "<span style='color:#666'>—</span>";

            echo "<tr>
                <td>{$item->id}</td>
                <td>{$item->user_id}</td>
                <td>{$item->created_at}</td>
                <td>{$item->meta_count}</td>
                <td>
                    <a href='?section=aggregate&item_id={$item->id}'>Aggrega</a> |
                    <a href='?section=prompt&item_id={$item->id}'>Prompt</a> |
                    <a href='?section=gemini&item_id={$item->id}' style='color:#4ec9b0'>🤖 Gemini</a>
                    &nbsp;{$cpt_badge}
                </td>
            </tr>";
        }
        echo "</table>";
    }

endif;

// =========================================================================
// TEST 3: AGGREGAZIONE DATI
// =========================================================================

if ( $section === 'all' || $section === 'aggregate' ) :

    echo "<h2>TEST 3 — Aggregazione dati per item_id = {$item_id}</h2>";

    $aggregated = VulcanicaDataAggregator::aggregate_item_data( $item_id );

    if ( is_wp_error( $aggregated ) ) {
        echo "<p class='err'>❌ Errore: " . $aggregated->get_error_message() . "</p>";
    } else {
        $fields = $aggregated['fields'];
        $total  = count( $fields );

        echo "<p class='ok'>✅ Aggregati <strong>{$total} campi</strong> per item {$item_id}</p>";

        $by_treatment = [];
        foreach ( $fields as $fid => $f ) {
            $by_treatment[ $f['treatment'] ][] = $f;
        }

        foreach ( $by_treatment as $treatment => $group ) {
            echo "<h3><span class='badge badge-{$treatment}'>{$treatment}</span> — " . count($group) . " campi</h3>";
            echo "<table><tr><th>field_id</th><th>name</th><th>value</th></tr>";
            foreach ( $group as $f ) {
                $val = is_array( $f['value'] )
                    ? '<pre>' . json_encode( $f['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . '</pre>'
                    : htmlspecialchars( (string) $f['value'] );
                echo "<tr><td>{$f['field_id']}</td><td>{$f['name']}</td><td>{$val}</td></tr>";
            }
            echo "</table>";
        }
    }

endif;

// =========================================================================
// TEST 4: PROMPT GENERATO
// =========================================================================

if ( $section === 'all' || $section === 'prompt' ) :

    echo "<h2>TEST 4 — Prompt generato per item_id = {$item_id}</h2>";

    $aggregated = VulcanicaDataAggregator::aggregate_item_data( $item_id );

    if ( is_wp_error( $aggregated ) ) {
        echo "<p class='err'>❌ Errore: " . $aggregated->get_error_message() . "</p>";
    } else {
        $prompt = VulcanicaDataAggregator::format_for_prompt( $aggregated );

        // Carica i PDF files reali
        require_once __DIR__ . '/class-pdf-manager.php';
        $pdf_files = VulcanicaPDFManager::get_uploaded_pdfs_with_paths();

        $full   = VulcanicaAJAXHandler::build_prompt( $aggregated, $pdf_files );

        // Rimuovi tag HTML dal prompt per la visualizzazione (mantieni solo testo)
        $full_cleaned = strip_tags( $full );

        echo "<p class='ok'>✅ Prompt dati: <strong>" . strlen($prompt) . " caratteri</strong> — "
           . "Prompt completo (con CONTESTO STORICO + istruzioni): <strong>" . strlen($full_cleaned) . " caratteri</strong></p>";

        echo "<h3>📋 PROMPT COMPLETO (testo inviato a Gemini, tag HTML rimossi)</h3>";
        echo "<pre>" . htmlspecialchars( $full_cleaned ) . "</pre>";

        // Mostra il JSON che verrebbe mandato a Gemini
        echo "<h3>📦 JSON RICHIESTA A GEMINI</h3>";

        // Costruisci i fileData parts reali dai PDF caricati su Gemini
        $file_parts = [ [ 'text' => $full ] ];
        $pdf_info_lines = [];

        foreach ( $pdf_files as $pdf ) {
            $uri = $pdf['gemini_file_uri'] ?? '';
            $exp = $pdf['gemini_expiration_time'] ?? '';
            $cached = ! empty( $uri );

            if ( $cached ) {
                $expired = ! empty( $exp ) && strtotime( $exp ) < time();
                if ( $expired ) {
                    $pdf_info_lines[] = "⏱ <span class='warn'>{$pdf['filename']}</span> — cache SCADUTA, verrà re-uploadato al momento dell'invio";
                    // Non aggiungo il file_uri scaduto: sarà re-uploadato durante il generate()
                    $file_parts[] = [ 'fileData' => [ 'mimeType' => 'application/pdf', 'fileUri' => $uri . ' (SCADUTO)' ] ];
                } else {
                    $pdf_info_lines[] = "✅ <span class='ok'>{$pdf['filename']}</span> — cache valida fino a: {$exp}";
                    $file_parts[] = [ 'fileData' => [ 'mimeType' => 'application/pdf', 'fileUri' => $uri ] ];
                }
            } else {
                $pdf_info_lines[] = "📤 <span style='color:#9cdcfe'>{$pdf['filename']}</span> — non ancora su Gemini, verrà uploadato al momento dell'invio";
                $file_parts[] = [ 'fileData' => [ 'mimeType' => 'application/pdf', 'fileUri' => '(verrà assegnato dopo upload)' ] ];
            }
        }

        if ( ! empty( $pdf_info_lines ) ) {
            echo "<div class='info-box'><strong>PDF files (" . count( $pdf_files ) . "):</strong><br>" . implode( '<br>', $pdf_info_lines ) . "</div>";
        } else {
            echo "<p class='warn'>⚠️ Nessun PDF pubblicato trovato — il bilancio verrà generato senza contesto storico.</p>";
        }

        // Costruisci i file_parts con il prompt ORIGINALE (contiene tag HTML veri)
        $file_parts = [ [ 'text' => $full ] ];
        foreach ( $pdf_files as $pdf ) {
            $uri = $pdf['gemini_file_uri'] ?? '';
            $exp = $pdf['gemini_expiration_time'] ?? '';

            if ( ! empty( $uri ) ) {
                $file_parts[] = [ 'fileData' => [ 'mimeType' => 'application/pdf', 'fileUri' => $uri ] ];
            }
        }

        // Body con prompt ORIGINALE per mostrare esattamente cosa Gemini riceverà
        $body = [
            'systemInstruction' => [
                'parts' => [ [ 'text' => VulcanicaAJAXHandler::get_system_prompt() ] ]
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $file_parts,
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => VulcanicaSettings::get_max_tokens(),
                'temperature'     => VulcanicaSettings::get_temperature(),
                'topP'            => 0.9,
            ],
        ];

        // Visualizza il JSON escapato per HTML-safe (i tag HTML veri sono visibili ma escapati per il browser)
        echo "<pre>" . htmlspecialchars( json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . "</pre>";

        echo "<p style='margin-top:15px'>
            <a class='btn btn-primary' href='?section=gemini&item_id={$item_id}'>
                🤖 Invia a Gemini (reale) →
            </a>
        </p>";
    }

endif;

// =========================================================================
// TEST 5: INVIA A GEMINI + CREA CPT
// =========================================================================

if ( $section === 'gemini' ) :

    echo "<h2>TEST 5 — Invio a Gemini API + creazione CPT per item_id = {$item_id}</h2>";

    // Verifica API key
    $api_key = VulcanicaSettings::get_api_key();
    if ( empty( $api_key ) ) {
        echo "<p class='err'>❌ API Key non configurata. Vai su <a href='" . admin_url('admin.php?page=vulcanica-settings') . "' style='color:#4fc1ff'>Vulcanica → Impostazioni</a> e inserisci la chiave.</p>";
        goto end_section5;
    }

    // Carica aggregato
    $aggregated = VulcanicaDataAggregator::aggregate_item_data( $item_id );
    if ( is_wp_error( $aggregated ) ) {
        echo "<p class='err'>❌ Errore aggregazione: " . $aggregated->get_error_message() . "</p>";
        goto end_section5;
    }

    // Carica PDF files reali
    require_once __DIR__ . '/class-pdf-manager.php';
    $pdf_files = VulcanicaPDFManager::get_uploaded_pdfs_with_paths();

    $prompt        = VulcanicaAJAXHandler::build_prompt( $aggregated, $pdf_files );
    $system_prompt = VulcanicaAJAXHandler::get_system_prompt();
    $model         = VulcanicaSettings::get_model();
    $max_tokens    = VulcanicaSettings::get_max_tokens();

    // Info pre-invio
    echo "<div class='info-box'>";
    echo "<div class='meta-row'>";
    echo "<div class='meta-item'><strong>Modello</strong>{$model}</div>";
    echo "<div class='meta-item'><strong>Max token</strong>{$max_tokens}</div>";
    echo "<div class='meta-item'><strong>Temperatura</strong>" . VulcanicaSettings::get_temperature() . "</div>";
    echo "<div class='meta-item'><strong>Prompt</strong>" . strlen($prompt) . " caratteri</div>";
    echo "<div class='meta-item'><strong>Item ID</strong>{$item_id}</div>";
    echo "</div>";

    // Controlla se esiste già un CPT
    $existing = VulcanicaCPTBilancio::get_by_item( $item_id );
    if ( $existing ) {
        echo "<p class='warn'>⚠️ Esiste già un CPT per questo item: "
           . "<a href='" . get_edit_post_link( $existing->ID, 'raw' ) . "' style='color:#4fc1ff'>Bilancio #{$existing->ID}</a> — "
           . "procedendo verrà creato un duplicato.</p>";
    }
    echo "</div>";

    $do_send = isset( $_POST['action'] ) && $_POST['action'] === 'send_gemini'
               && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'test_gemini_' . $item_id );

    $do_create_cpt = isset( $_POST['action'] ) && $_POST['action'] === 'create_cpt'
                     && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'test_gemini_' . $item_id );

    // ── Pulsante invio se non ancora inviato ─────────────────────────────
    if ( ! $do_send && ! $do_create_cpt ) {
        $nonce = wp_create_nonce( 'test_gemini_' . $item_id );
        echo "<form method='post'>";
        echo "<input type='hidden' name='action' value='send_gemini'>";
        echo "<input type='hidden' name='_wpnonce' value='{$nonce}'>";
        echo "<button type='submit' class='btn btn-primary'>🤖 Invia prompt a Gemini</button>";
        echo "</form>";
        goto end_section5;
    }

    // ── INVIO A GEMINI ────────────────────────────────────────────────────
    if ( $do_send || $do_create_cpt ) {

        // Se abbiamo la risposta in sessione, riusala; altrimenti invia
        if ( ! session_id() ) session_start();
        $session_key = 'gemini_response_' . $item_id;

        if ( $do_send || empty( $_SESSION[ $session_key ] ) ) {
            $t_start = microtime( true );

            echo "<p>⏳ Invio in corso a <strong>{$model}</strong>...</p>";
            flush();

            $gemini   = new VulcanicaGeminiClient();
            $response = $gemini->generate( $prompt, [
                'system_prompt' => $system_prompt,
                'pdf_files'     => $pdf_files,
            ] );

            $elapsed = round( microtime( true ) - $t_start, 2 );

            if ( ! $response['success'] ) {
                echo "<p class='err'>❌ Errore Gemini: " . htmlspecialchars( $response['error'] ) . "</p>";

                // Pulsante riprova
                $nonce = wp_create_nonce( 'test_gemini_' . $item_id );
                echo "<form method='post'>";
                echo "<input type='hidden' name='action' value='send_gemini'>";
                echo "<input type='hidden' name='_wpnonce' value='{$nonce}'>";
                echo "<button type='submit' class='btn btn-danger'>🔄 Riprova</button>";
                echo "</form>";
                goto end_section5;
            }

            $ai_content = $response['content'];
            $_SESSION[ $session_key ] = $ai_content;

        } else {
            $ai_content = $_SESSION[ $session_key ];
            $elapsed    = 0;
        }

        // ── Mostra risposta ───────────────────────────────────────────────
        echo "<p class='ok'>✅ Risposta ricevuta";
        if ( $elapsed > 0 ) echo " in <strong>{$elapsed}s</strong>";
        echo " — <strong>" . strlen( $ai_content ) . " caratteri</strong></p>";

        echo "<h3>Risposta AI (rendering HTML)</h3>";
        echo "<div class='ai-response'>" . wp_kses_post( $ai_content ) . "</div>";

        echo "<h3>Risposta AI (HTML grezzo)</h3>";
        echo "<pre>" . htmlspecialchars( $ai_content ) . "</pre>";

        // ── Pulsante crea CPT ─────────────────────────────────────────────
        if ( ! $do_create_cpt ) {
            $nonce = wp_create_nonce( 'test_gemini_' . $item_id );
            echo "<form method='post' style='margin-top:20px'>";
            echo "<input type='hidden' name='action' value='create_cpt'>";
            echo "<input type='hidden' name='_wpnonce' value='{$nonce}'>";
            echo "<input type='hidden' name='ai_content' value='" . esc_attr( $ai_content ) . "'>";
            echo "<button type='submit' class='btn btn-success'>📄 Crea CPT Bilancio di Genere</button>";
            echo "&nbsp;<a href='?section=gemini&item_id={$item_id}' class='btn' style='background:#333;color:#d4d4d4'>✖ Annulla</a>";
            echo "</form>";
        }
    }

    // ── CREA CPT ──────────────────────────────────────────────────────────
    if ( $do_create_cpt ) {
        $ai_content = wp_kses_post( wp_unslash( $_POST['ai_content'] ?? '' ) );

        if ( empty( $ai_content ) ) {
            // ai_content troppo grande per hidden field: riprendi dalla sessione
            if ( ! session_id() ) session_start();
            $ai_content = $_SESSION[ 'gemini_response_' . $item_id ] ?? '';
        }

        if ( empty( $ai_content ) ) {
            echo "<p class='err'>❌ Contenuto AI non trovato. Riprova l'invio a Gemini.</p>";
            goto end_section5;
        }

        $cpt_id = VulcanicaCPTBilancio::create_from_ai(
            $item_id,
            $ai_content,
            get_current_user_id(),
            [
                'form_id'        => $aggregated['form_id'],
                'aggregate_data' => wp_json_encode( $aggregated ),
                'test_generated' => true,
            ]
        );

        if ( is_wp_error( $cpt_id ) ) {
            echo "<p class='err'>❌ Errore creazione CPT: " . $cpt_id->get_error_message() . "</p>";
        } else {
            VulcanicaCPTBilancio::update_item_status( $item_id, 'elaborato', [ 'cpt_id' => $cpt_id ] );

            $post_url = get_permalink( $cpt_id );
            $edit_url = get_edit_post_link( $cpt_id, 'raw' );

            echo "<div class='info-box' style='border-color:#4ec9b0'>";
            echo "<p class='ok' style='font-size:16px'>✅ CPT creato con successo! <strong>Post ID: {$cpt_id}</strong></p>";
            echo "<p style='margin:8px 0'>
                <a href='{$edit_url}' class='btn btn-primary' style='margin-right:10px'>✏️ Modifica in WP Admin</a>
                <a href='{$post_url}' class='btn btn-success' target='_blank'>🔗 Visualizza il bilancio →</a>
            </p>";
            echo "</div>";

            // Pulizia sessione
            if ( session_id() ) unset( $_SESSION[ 'gemini_response_' . $item_id ] );
        }
    }

    end_section5:

endif; // section === gemini

?>

</body>
</html>
