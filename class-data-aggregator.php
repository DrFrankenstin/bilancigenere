<?php
/**
 * Class: Aggregatore dati da wp_frm_item_metas
 *
 * Costruisce una mappatura esatta per ogni field_id del form 8,
 * interrogando il database reale di Formidable Forms.
 * La mappatura viene cachata in un transient per performance.
 *
 * Ogni field viene classificato con un "treatment":
 *   - skip     : campi HTML (intestazioni sezione) - ignorati
 *   - narrative: textarea con testo libero dell'utente
 *   - json     : campi text con dati ISTAT serializzati come JSON
 *   - radio    : campi Si/No (radio button)
 *   - numeric  : campi number o text con valori numerici aggiuntivi
 */
class VulcanicaDataAggregator {

    const FORM_ID         = 8;
    const TRANSIENT_KEY   = 'vulcanica_field_map_form8';
    const TRANSIENT_TTL   = DAY_IN_SECONDS;

    // =========================================================================
    // MAPPATURA CAMPI
    // =========================================================================

    /**
     * Restituisce la mappa completa dei field_id per il form 8.
     * La prima volta interroga il DB, poi usa il transient.
     *
     * @return array [ field_id => ['name'=>'...', 'type'=>'...', 'field_key'=>'...', 'treatment'=>'...'] ]
     */
    public static function get_field_map() {
        $map = get_transient( self::TRANSIENT_KEY );

        if ( false !== $map ) {
            return $map;
        }

        return self::build_field_map();
    }

    /**
     * Interroga il DB e costruisce la mappa, poi la salva nel transient.
     *
     * @return array
     */
    public static function build_field_map() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key, name, type
             FROM {$wpdb->prefix}frm_fields
             WHERE form_id = %d
             ORDER BY id ASC",
            self::FORM_ID
        ) );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row->id ] = [
                'name'      => $row->name,
                'type'      => $row->type,
                'field_key' => $row->field_key,
                'treatment' => self::resolve_treatment( $row->type, $row->field_key ),
            ];
        }

        set_transient( self::TRANSIENT_KEY, $map, self::TRANSIENT_TTL );

        return $map;
    }

    /**
     * Invalida il transient (utile se si modificano i campi del form).
     */
    public static function flush_field_map() {
        delete_transient( self::TRANSIENT_KEY );
    }

    /**
     * Deduce il "treatment" da type e field_key.
     *
     * Regole:
     *  - html              → skip      (intestazioni di sezione)
     *  - textarea          → narrative (testo libero utente)
     *  - radio             → radio     (Si / No)
     *  - text, field_key inizia con bg_2_ o bg_3_1 ... bg_3_5 → json (ISTAT)
     *  - text, field_key contiene '_agg' → numeric (valori aggiuntivi)
     *  - number            → numeric
     *  - text (default)    → text      (testo generico)
     *
     * @param string $type
     * @param string $field_key
     * @return string
     */
    private static function resolve_treatment( $type, $field_key ) {
        switch ( $type ) {
            case 'html':
            case 'submit':
            case 'captcha':
            case 'break':
                return 'skip';

            case 'textarea':
                return 'narrative';

            case 'radio':
            case 'checkbox':
            case 'select':
                return 'radio';

            case 'number':
                return 'numeric';

            case 'file':
                return 'file';

            case 'text':
                // Campi ISTAT con JSON serializzato: field_key tipo bg_2_1_1, bg_3_4, ecc.
                if ( preg_match( '/^bg_/', $field_key ) ) {
                    return 'json';
                }
                // Campi aggiuntivi numerici tipo p1_231_dateco_a_ua, p1_242_pilcom_ua ecc.
                if ( preg_match( '/^p1_/', $field_key ) ) {
                    return 'numeric';
                }
                // Campi sezione 3 sub-campi: p2_31_*, p2_32_*, p2_321_*, p2_322_* (percentuali/numerici)
                // Eccezione: p2_322_disobb è testo descrittivo
                if ( preg_match( '/^p2_/', $field_key ) ) {
                    if ( $field_key === 'p2_322_disobb' ) {
                        return 'text';
                    }
                    return 'numeric';
                }
                return 'text';

            default:
                return 'skip'; // Tipi sconosciuti: ignoriamo
        }
    }

    // =========================================================================
    // AGGREGAZIONE DATI ITEM
    // =========================================================================

    /**
     * Recupera e aggrega tutti i dati di un invio form.
     *
     * @param int $item_id ID in wp_frm_items
     * @return array|WP_Error
     */
    public static function aggregate_item_data( $item_id ) {
        global $wpdb;

        // Recupera item base
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}frm_items WHERE id = %d",
            $item_id
        ) );

        if ( ! $item ) {
            return new WP_Error( 'item_not_found', "Item {$item_id} non trovato" );
        }

        // Recupera tutti i metas
        $metas = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, meta_value
             FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id = %d",
            $item_id
        ) );

        if ( empty( $metas ) ) {
            return new WP_Error( 'no_metas', "Nessun meta trovato per item {$item_id}" );
        }

        // Mappa dei field_id → definizione campo
        $field_map = self::get_field_map();

        // Struttura risultato
        $aggregated = [
            'item_id'    => (int) $item_id,
            'form_id'    => (int) $item->form_id,
            'created_at' => $item->created_at,
            'user_id'    => (int) $item->user_id,
            'fields'     => [],   // field_id => { name, treatment, value }
        ];

        // Processa ogni meta
        foreach ( $metas as $meta ) {
            $fid = (int) $meta->field_id;

            // Ignora field_id = 0 (meta di sistema Formidable)
            if ( $fid === 0 ) {
                continue;
            }

            // Recupera la definizione del campo dalla mappa
            $def = $field_map[ $fid ] ?? null;

            // Se il campo non è nel form 8 o non lo conosciamo, saltiamo
            if ( ! $def ) {
                continue;
            }

            // Salta le intestazioni HTML
            if ( $def['treatment'] === 'skip' ) {
                continue;
            }

            // Processa il valore in base al treatment
            $value = self::process_value( $meta->meta_value, $def['treatment'] );

            $aggregated['fields'][ $fid ] = [
                'field_id'  => $fid,
                'field_key' => $def['field_key'],
                'name'      => $def['name'],
                'type'      => $def['type'],
                'treatment' => $def['treatment'],
                'value'     => $value,
            ];
        }

        return $aggregated;
    }

    /**
     * Processa un meta_value in base al treatment del campo.
     *
     * @param string $raw
     * @param string $treatment
     * @return mixed
     */
    private static function process_value( $raw, $treatment ) {
        switch ( $treatment ) {
            case 'json':
                $decoded = json_decode( $raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return $raw;
                }
                // Arrotonda tutti i float a 2 decimali per evitare 51.670000000000002
                return self::round_floats( $decoded );

            case 'numeric':
                if ( ! is_numeric( $raw ) ) {
                    return $raw;
                }
                $num = (float) $raw;
                // Se è un numero intero non modificarlo (es. anni come 2025)
                return ( $num == (int) $num ) ? (int) $num : round( $num, 2 );

            case 'file':
                // Formidable salva l'attachment ID (o array di ID) nel meta_value
                $attachment_id = intval( $raw );
                if ( ! $attachment_id ) {
                    return null;
                }
                $file_path = get_attached_file( $attachment_id );
                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    return null;
                }
                return [
                    'attachment_id' => $attachment_id,
                    'file_path'     => $file_path,
                    'filename'      => basename( $file_path ),
                    'mime_type'     => get_post_mime_type( $attachment_id ) ?: mime_content_type( $file_path ),
                ];

            case 'radio':
            case 'narrative':
            case 'text':
            default:
                return $raw;
        }
    }

    /**
     * Estrae i file allegati (CSV e PDF bilanci comunali) dall'array aggregato.
     * Restituisce un array con chiavi: csv_file, pdf_bilancio_1, pdf_bilancio_2.
     *
     * @param array $aggregated  Output di aggregate_item_data()
     * @return array
     */
    public static function extract_uploaded_files( $aggregated ) {
        $files = [
            'csv_file'       => null,
            'pdf_bilancio_1' => null,
            'pdf_bilancio_2' => null,
        ];

        $key_map = [
            'vcm_csv_dati'       => 'csv_file',
            'vcm_pdf_bilancio_1' => 'pdf_bilancio_1',
            'vcm_pdf_bilancio_2' => 'pdf_bilancio_2',
        ];

        foreach ( $aggregated['fields'] as $field ) {
            $field_key = $field['field_key'] ?? '';
            if ( isset( $key_map[ $field_key ] ) && ! empty( $field['value'] ) ) {
                $files[ $key_map[ $field_key ] ] = $field['value'];
            }
        }

        return $files;
    }

    /**
     * Arrotonda ricorsivamente tutti i float in un array a 2 decimali.
     *
     * @param mixed $data
     * @return mixed
     */
    private static function round_floats( $data ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $k => $v ) {
                $data[ $k ] = self::round_floats( $v );
            }
            return $data;
        }
        if ( is_float( $data ) ) {
            return round( $data, 2 );
        }
        return $data;
    }

    // =========================================================================
    // FORMATTAZIONE PROMPT
    // =========================================================================

    /**
     * Formatta un numero per il prompt: interi senza decimali, float con max 2 decimali
     * senza trailing zeros. Usa number_format (stringa) per evitare i bug di
     * serialize_precision in json_encode con float IEEE 754.
     *
     * @param mixed $value
     * @return string
     */
    private static function fmt_num( $value ) {
        if ( ! is_numeric( $value ) ) {
            return (string) $value;
        }
        $f = (float) $value;
        // Intero: nessun decimale
        if ( $f == floor( $f ) && abs( $f ) < 1.0e15 ) {
            return number_format( $f, 0, '.', '' );
        }
        // Float: 2 decimali, rimuove trailing zeros
        return rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
    }

    /**
     * Espande una chiave abbreviata ISTAT in testo italiano leggibile.
     * UA/PA/TA = livello geografico (Comune / Provincia / Area Vasta).
     *
     * @param string $key
     * @return string
     */
    private static function expand_istat_key( $key ) {
        // UA = Ultimo Anno, PA = Penultimo Anno, TA = Terzultimo Anno
        // Gli anni effettivi sono nel campo "anno" dello stesso blocco JSON
        static $map = [
            // Metadato
            'anno'      => 'Anni di riferimento (più recente → meno recente)',
            'provincia' => 'Provincia',

            // ── Popolazione per genere ─────────────────────────────────────
            'UA_d' => '% donne (anno più recente)',    'UA_u' => '% uomini (anno più recente)',
            'PA_d' => '% donne (anno precedente)',     'PA_u' => '% uomini (anno precedente)',
            'TA_d' => '% donne (due anni fa)',         'TA_u' => '% uomini (due anni fa)',

            // ── Demografici ────────────────────────────────────────────────
            'UA_nati'      => 'nati (anno più recente)',
            'PA_nati'      => 'nati (anno precedente)',
            'TA_nati'      => 'nati (due anni fa)',
            'UA_morti'     => 'morti (anno più recente)',
            'PA_morti'     => 'morti (anno precedente)',
            'TA_morti'     => 'morti (due anni fa)',
            'UA_trasf_in'  => 'trasferimenti in entrata (anno più recente)',
            'PA_trasf_in'  => 'trasferimenti in entrata (anno precedente)',
            'TA_trasf_in'  => 'trasferimenti in entrata (due anni fa)',
            'UA_trasf_out' => 'trasferimenti in uscita (anno più recente)',
            'PA_trasf_out' => 'trasferimenti in uscita (anno precedente)',
            'TA_trasf_out' => 'trasferimenti in uscita (due anni fa)',

            // ── Densità / indice di vecchiaia (scalare per anno) ──────────
            'UA' => 'valore (anno più recente)',
            'PA' => 'valore (anno precedente)',
            'TA' => 'valore (due anni fa)',

            // ── Famiglie ───────────────────────────────────────────────────
            'UA_single'  => 'famiglie unipersonali (anno più recente)',
            'PA_single'  => 'famiglie unipersonali (anno precedente)',
            'TA_single'  => 'famiglie unipersonali (due anni fa)',
            'UA_coppias' => 'coppie sposate (anno più recente)',
            'PA_coppias' => 'coppie sposate (anno precedente)',
            'TA_coppias' => 'coppie sposate (due anni fa)',
            'UA_coppiac' => 'coppie conviventi (anno più recente)',
            'PA_coppiac' => 'coppie conviventi (anno precedente)',
            'TA_coppiac' => 'coppie conviventi (due anni fa)',
            'UA_mono'    => 'famiglie monogenitoriali (anno più recente)',
            'PA_mono'    => 'famiglie monogenitoriali (anno precedente)',
            'TA_mono'    => 'famiglie monogenitoriali (due anni fa)',
            'UA_mf'      => 'madri sole con figli (anno più recente)',
            'PA_mf'      => 'madri sole con figli (anno precedente)',
            'TA_mf'      => 'madri sole con figli (due anni fa)',
            'UA_pf'      => 'padri soli con figli (anno più recente)',
            'PA_pf'      => 'padri soli con figli (anno precedente)',
            'TA_pf'      => 'padri soli con figli (due anni fa)',

            // ── Stato civile ───────────────────────────────────────────────
            'UA_nubili'     => 'donne nubili (anno più recente)',
            'PA_nubili'     => 'donne nubili (anno precedente)',
            'TA_nubili'     => 'donne nubili (due anni fa)',
            'UA_celibi'     => 'uomini celibi (anno più recente)',
            'PA_celibi'     => 'uomini celibi (anno precedente)',
            'TA_celibi'     => 'uomini celibi (due anni fa)',
            'UA_congiunte'  => 'donne coniugate (anno più recente)',
            'PA_congiunte'  => 'donne coniugate (anno precedente)',
            'TA_congiunte'  => 'donne coniugate (due anni fa)',
            'UA_congiunti'  => 'uomini coniugati (anno più recente)',
            'PA_congiunti'  => 'uomini coniugati (anno precedente)',
            'TA_congiunti'  => 'uomini coniugati (due anni fa)',
            'UA_divorziate' => 'donne divorziate (anno più recente)',
            'PA_divorziate' => 'donne divorziate (anno precedente)',
            'TA_divorziate' => 'donne divorziate (due anni fa)',
            'UA_divorziati' => 'uomini divorziati (anno più recente)',
            'PA_divorziati' => 'uomini divorziati (anno precedente)',
            'TA_divorziati' => 'uomini divorziati (due anni fa)',
            'UA_vedove'     => 'donne vedove (anno più recente)',
            'PA_vedove'     => 'donne vedove (anno precedente)',
            'TA_vedove'     => 'donne vedove (due anni fa)',
            'UA_vedovi'     => 'uomini vedovi (anno più recente)',
            'PA_vedovi'     => 'uomini vedovi (anno precedente)',
            'TA_vedovi'     => 'uomini vedovi (due anni fa)',

            // ── Istruzione ─────────────────────────────────────────────────
            'dnoel_ua' => 'donne senza titolo (anno più recente)',
            'dnoel_pa' => 'donne senza titolo (anno precedente)',
            'dnoel_ta' => 'donne senza titolo (due anni fa)',
            'del_ua'   => 'donne lic. elementare (anno più recente)',
            'del_pa'   => 'donne lic. elementare (anno precedente)',
            'del_ta'   => 'donne lic. elementare (due anni fa)',
            'dmed_ua'  => 'donne lic. media (anno più recente)',
            'dmed_pa'  => 'donne lic. media (anno precedente)',
            'dmed_ta'  => 'donne lic. media (due anni fa)',
            'ddip_ua'  => 'donne diplomate (anno più recente)',
            'ddip_pa'  => 'donne diplomate (anno precedente)',
            'ddip_ta'  => 'donne diplomate (due anni fa)',
            'dlau_ua'  => 'donne laureate (anno più recente)',
            'dlau_pa'  => 'donne laureate (anno precedente)',
            'dlau_ta'  => 'donne laureate (due anni fa)',
            'dsup_ua'  => 'donne post-laurea (anno più recente)',
            'dsup_pa'  => 'donne post-laurea (anno precedente)',
            'dsup_ta'  => 'donne post-laurea (due anni fa)',
            'unoel_ua' => 'uomini senza titolo (anno più recente)',
            'unoel_pa' => 'uomini senza titolo (anno precedente)',
            'unoel_ta' => 'uomini senza titolo (due anni fa)',
            'uel_ua'   => 'uomini lic. elementare (anno più recente)',
            'uel_pa'   => 'uomini lic. elementare (anno precedente)',
            'uel_ta'   => 'uomini lic. elementare (due anni fa)',
            'umed_ua'  => 'uomini lic. media (anno più recente)',
            'umed_pa'  => 'uomini lic. media (anno precedente)',
            'umed_ta'  => 'uomini lic. media (due anni fa)',
            'udip_ua'  => 'uomini diplomati (anno più recente)',
            'udip_pa'  => 'uomini diplomati (anno precedente)',
            'udip_ta'  => 'uomini diplomati (due anni fa)',
            'ulau_ua'  => 'uomini laureati (anno più recente)',
            'ulau_pa'  => 'uomini laureati (anno precedente)',
            'ulau_ta'  => 'uomini laureati (due anni fa)',
            'usup_ua'  => 'uomini post-laurea (anno più recente)',
            'usup_pa'  => 'uomini post-laurea (anno precedente)',
            'usup_ta'  => 'uomini post-laurea (due anni fa)',

            // ── Occupazione ────────────────────────────────────────────────
            'docc_ua' => 'donne occupate (anno più recente)',
            'docc_pa' => 'donne occupate (anno precedente)',
            'docc_ta' => 'donne occupate (due anni fa)',
            'uocc_ua' => 'uomini occupati (anno più recente)',
            'uocc_pa' => 'uomini occupati (anno precedente)',
            'uocc_ta' => 'uomini occupati (due anni fa)',

            // ── Disoccupazione e inattività ────────────────────────────────
            'ddis_ua'    => 'donne disoccupate (anno più recente)',
            'ddis_pa'    => 'donne disoccupate (anno precedente)',
            'ddis_ta'    => 'donne disoccupate (due anni fa)',
            'udis_ua'    => 'uomini disoccupati (anno più recente)',
            'udis_pa'    => 'uomini disoccupati (anno precedente)',
            'udis_ta'    => 'uomini disoccupati (due anni fa)',
            'dina_ua'    => 'donne inattive (anno più recente)',
            'dina_pa'    => 'donne inattive (anno precedente)',
            'dina_ta'    => 'donne inattive (due anni fa)',
            'uina_ua'    => 'uomini inattivi (anno più recente)',
            'uina_pa'    => 'uomini inattivi (anno precedente)',
            'uina_ta'    => 'uomini inattivi (due anni fa)',
            'dis1524_ua' => 'disoccupati 15-24 anni (anno più recente)',
            'dis1524_pa' => 'disoccupati 15-24 anni (anno precedente)',
            'dis1524_ta' => 'disoccupati 15-24 anni (due anni fa)',
            'dis2549_ua' => 'disoccupati 25-49 anni (anno più recente)',
            'dis2549_pa' => 'disoccupati 25-49 anni (anno precedente)',
            'dis2549_ta' => 'disoccupati 25-49 anni (due anni fa)',
            'dis5064_ua' => 'disoccupati 50-64 anni (anno più recente)',
            'dis5064_pa' => 'disoccupati 50-64 anni (anno precedente)',
            'dis5064_ta' => 'disoccupati 50-64 anni (due anni fa)',
            'dis65_ua'   => 'disoccupati 65+ anni (anno più recente)',
            'dis65_pa'   => 'disoccupati 65+ anni (anno precedente)',
            'dis65_ta'   => 'disoccupati 65+ anni (due anni fa)',
            'ina1524_ua' => 'inattivi 15-24 anni (anno più recente)',
            'ina1524_pa' => 'inattivi 15-24 anni (anno precedente)',
            'ina1524_ta' => 'inattivi 15-24 anni (due anni fa)',
            'ina2549_ua' => 'inattivi 25-49 anni (anno più recente)',
            'ina2549_pa' => 'inattivi 25-49 anni (anno precedente)',
            'ina2549_ta' => 'inattivi 25-49 anni (due anni fa)',
            'ina5064_ua' => 'inattivi 50-64 anni (anno più recente)',
            'ina5064_pa' => 'inattivi 50-64 anni (anno precedente)',
            'ina5064_ta' => 'inattivi 50-64 anni (due anni fa)',
            'ina65_ua'   => 'inattivi 65+ anni (anno più recente)',
            'ina65_pa'   => 'inattivi 65+ anni (anno precedente)',
            'ina65_ta'   => 'inattivi 65+ anni (due anni fa)',

            // ── Reddito e PIL ──────────────────────────────────────────────
            'redd_ua'     => 'reddito medio pro capite € (anno più recente)',
            'redd_pa'     => 'reddito medio pro capite € (anno precedente)',
            'redd_ta'     => 'reddito medio pro capite € (due anni fa)',
            'pilprov_ua'  => 'PIL provinciale pro capite € (anno più recente)',
            'pilprov_pa'  => 'PIL provinciale pro capite € (anno precedente)',
            'pilprov_ta'  => 'PIL provinciale pro capite € (due anni fa)',
            'contr10k_ua' => 'contribuenti con reddito < 10.000€ (anno più recente)',
            'contr10k_pa' => 'contribuenti con reddito < 10.000€ (anno precedente)',
            'contr10k_ta' => 'contribuenti con reddito < 10.000€ (due anni fa)',
        ];

        return $map[ $key ] ?? $key; // fallback: chiave originale se non mappata
    }

    /**
     * Converte un array ISTAT decodificato da JSON in testo leggibile,
     * espandendo le chiavi e formattando i numeri senza artifacts IEEE 754.
     *
     * @param mixed $data  Array o valore scalare
     * @return string
     */
    private static function format_istat_array( $data ) {
        if ( ! is_array( $data ) ) {
            return (string) $data;
        }

        $lines = [];
        foreach ( $data as $key => $value ) {
            $label = self::expand_istat_key( $key );
            if ( $value === null ) {
                $lines[] = "  - {$label}: n.d.";
            } elseif ( is_numeric( $value ) ) {
                $lines[] = "  - {$label}: " . self::fmt_num( $value );
            } else {
                $lines[] = "  - {$label}: {$value}";
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Converte l'aggregato in un prompt leggibile per Gemini.
     * I campi ISTAT vengono espansi con chiavi complete in italiano.
     * I numeri sono formattati senza artifacts float.
     *
     * @param array $aggregated
     * @return string
     */
    public static function format_for_prompt( $aggregated ) {
        $sections = [
            'narrative' => [],
            'json'      => [],
            'radio'     => [],
            'numeric'   => [],
            'text'      => [],
        ];

        foreach ( $aggregated['fields'] as $fid => $field ) {
            $sections[ $field['treatment'] ][ $field['name'] ] = $field['value'];
        }

        $prompt  = "# BILANCIO DI GENERE — DATI INVIATI\n\n";
        $prompt .= "- ID voce del modulo: {$aggregated['item_id']}\n";
        $prompt .= "- Data compilazione: {$aggregated['created_at']}\n\n";

        // 1. Risposte narrative (testo libero)
        if ( ! empty( $sections['narrative'] ) ) {
            $prompt .= "## RISPOSTE NARRATIVE\n\n";
            foreach ( $sections['narrative'] as $name => $value ) {
                $prompt .= "### {$name}\n{$value}\n\n";
            }
        }

        // 2. Statistiche ISTAT — chiavi espanse, numeri puliti
        if ( ! empty( $sections['json'] ) ) {
            $prompt .= "## DATI STATISTICI DEL COMUNE (ISTAT)\n";
            $prompt .= "> Fonte: ISTAT. Ogni indicatore è riportato per tre anni consecutivi: "
                     . "**anno più recente**, **anno precedente**, **due anni fa** "
                     . "(gli anni effettivi sono indicati nel campo \"Anni di riferimento\" di ogni sezione).\n\n";
            foreach ( $sections['json'] as $name => $value ) {
                $prompt .= "### {$name}\n";
                $prompt .= self::format_istat_array( $value );
                $prompt .= "\n\n";
            }
        }

        // 3. Risposte Si/No (radio)
        if ( ! empty( $sections['radio'] ) ) {
            $prompt .= "## POLITICHE E STRATEGIE (Sì/No)\n\n";
            foreach ( $sections['radio'] as $name => $value ) {
                $prompt .= "- **{$name}**: {$value}\n";
            }
            $prompt .= "\n";
        }

        // 4. Valori numerici — salta solo valori effettivamente vuoti
        if ( ! empty( $sections['numeric'] ) ) {
            $prompt .= "## DATI NUMERICI AGGIUNTIVI\n\n";
            foreach ( $sections['numeric'] as $name => $value ) {
                if ( $value === '' || $value === null ) {
                    continue;
                }
                $formatted = is_numeric( $value ) ? self::fmt_num( $value ) : $value;
                $prompt .= "- **{$name}**: {$formatted}\n";
            }
            $prompt .= "\n";
        }

        // 5. Testo generico
        if ( ! empty( $sections['text'] ) ) {
            $prompt .= "## ALTRI DATI\n\n";
            foreach ( $sections['text'] as $name => $value ) {
                $prompt .= "- **{$name}**: {$value}\n";
            }
            $prompt .= "\n";
        }

        return $prompt;
    }
}
