<?php
/**
 * Client per Google Gemini API
 *
 * Legge modello, temperatura e token massimi dalle impostazioni del plugin
 * (VulcanicaSettings), con possibilità di override per chiamata.
 *
 * Struttura richiesta corretta per Gemini API v1beta:
 *   systemInstruction → istruzione di sistema (separata dai contents)
 *   contents[]        → messaggi della conversazione
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VulcanicaGeminiClient {

    private $api_key;
    private $api_base = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct( $api_key = '' ) {
        // Se non passata, legge dal settings
        $this->api_key = $api_key ?: VulcanicaSettings::get_api_key();
    }

    /**
     * Invia un prompt a Gemini e restituisce la risposta.
     *
     * @param string $prompt   Testo del prompt utente
     * @param array  $options  Override opzionali:
     *                           'model'         string
     *                           'max_tokens'    int
     *                           'temperature'   float
     *                           'system_prompt' string
     *                           'pdf_files'     array - Array di ['file_path' => '...', 'filename' => '...']
     * @return array {
     *   bool   success
     *   string content   Testo risposta (vuoto se errore)
     *   string error     Messaggio errore (vuoto se success)
     * }
     */
    public function generate( $prompt, $options = [] ) {

        if ( empty( $this->api_key ) ) {
            return $this->error( 'API Key non configurata. Vai su Impostazioni → Bilancio di Genere.' );
        }

        // Parametri: opzioni chiamata > settings plugin > default codice
        $model       = $options['model']       ?? VulcanicaSettings::get_model();
        $max_tokens  = $options['max_tokens']  ?? VulcanicaSettings::get_max_tokens();
        $temperature = $options['temperature'] ?? VulcanicaSettings::get_temperature();
        $sys_prompt  = $options['system_prompt'] ?? '';
        $pdf_files   = $options['pdf_files']   ?? [];

        // ── Costruisce body richiesta ──────────────────────────────────────
        $body = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [ [ 'text' => $prompt ] ],
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => intval( $max_tokens ),
                'temperature'     => floatval( $temperature ),
                'topP'            => 0.9,
            ],
        ];

        // ── NUOVO: Upload PDF files a Gemini Files API ──────────────────────
        if ( ! empty( $pdf_files ) ) {
            error_log( "[Vulcanica] Starting PDF upload to Gemini: " . count( $pdf_files ) . " files" );

            $file_parts = [];

            foreach ( $pdf_files as $pdf ) {
                $file_path = $pdf['file_path'] ?? '';
                $filename  = $pdf['filename'] ?? '';

                if ( empty( $file_path ) || empty( $filename ) ) {
                    error_log( "[Vulcanica] Invalid PDF entry, skipping" );
                    continue;
                }

                // Effettua l'upload
                $upload_result = $this->upload_file_to_gemini( $file_path, $filename );

                if ( $upload_result['success'] ) {
                    $file_parts[] = [
                        'fileData' => [
                            'mimeType' => 'application/pdf',
                            'fileUri'  => $upload_result['file_uri'],
                        ]
                    ];
                } else {
                    // Non bloccare il flusso se un file fallisce - continua
                    error_log( "[Vulcanica] PDF upload to Gemini FAILED: {$filename} - " . $upload_result['error'] );
                }
            }

            // Aggiungi i fileData parts al body
            foreach ( $file_parts as $file_part ) {
                $body['contents'][0]['parts'][] = $file_part;
            }

            if ( count( $file_parts ) > 0 ) {
                error_log( "[Vulcanica] Gemini generateContent will include " . count( $file_parts ) . " PDF files" );
            }
        }

        // systemInstruction è un campo separato in Gemini API (non va nei contents)
        if ( ! empty( $sys_prompt ) ) {
            $body['systemInstruction'] = [
                'parts' => [ [ 'text' => $sys_prompt ] ],
            ];
        }

        $url = $this->api_base . $model . ':generateContent?key=' . $this->api_key;

        error_log( "[Vulcanica] Gemini: invio a {$model}, max_tokens={$max_tokens}, temp={$temperature}" );
        
        // TODO DEBUG: log body richiesta
        // var_dump($body);
        // exit();
        
        
        // ── Effettua la richiesta ──────────────────────────────────────────
        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 300,
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error( 'Errore di connessione: ' . $response->get_error_message() );
        }

        $http_status   = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        // ── Parsing risposta ───────────────────────────────────────────────
        if ( $http_status === 200 ) {
            $text          = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $finish_reason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN';

            // STOP e MAX_TOKENS = risposta valida (MAX_TOKENS = troncata ma utilizzabile)
            // SAFETY / RECITATION / OTHER = risposta effettivamente bloccata
            $blocked_reasons = [ 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' ];
            if ( in_array( $finish_reason, $blocked_reasons, true ) ) {
                return $this->error( "Risposta bloccata da Gemini per: {$finish_reason}" );
            }

            if ( empty( $text ) && $finish_reason === 'UNKNOWN' ) {
                return $this->error( 'Nessun testo ricevuto da Gemini (motivo sconosciuto).' );
            }

            error_log( "[Vulcanica] Gemini: risposta ricevuta, " . strlen( $text ) . " caratteri, finishReason=$finish_reason" );

            return [
                'success' => true,
                'content' => $text,
                'error'   => '',
            ];
        }

        // Errore HTTP
        $api_error = $data['error']['message']
            ?? ( 'Errore API Gemini — HTTP ' . $http_status );

        error_log( "[Vulcanica] Gemini: errore HTTP {$http_status} — {$api_error}" );

        return $this->error( $api_error );
    }

    /**
     * Testa la connessione API con una chiamata minimale.
     *
     * @return array { success: bool, message: string }
     */
    public function test_connection() {
        $result = $this->generate(
            'Rispondi solo con: "Connessione OK"',
            [ 'temperature' => 0 ]  // Nessun limite token: usa il default del modello
        );

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? '✅ ' . trim( $result['content'] )
                : '❌ ' . $result['error'],
        ];
    }

    /**
     * PREPROCESSING: Analizza i PDF storici in batch e combina i risultati.
     *
     * Divide i PDF in batch da BATCH_SIZE file, analizza ogni batch separatamente
     * (evitando il limite token di 1M), con retry automatico per "high demand".
     * Se ci sono più batch, sintetizza i risultati parziali in un unico riassunto.
     *
     * @param array $pdf_files Array di PDF files con file_path e filename
     * @param array $options   Override opzionali (model, max_tokens, temperature, batch_size)
     * @return array { success: bool, content: string, error: string }
     */
    public function analyze_pdfs_for_preprocessing( $pdf_files, $options = [] ) {

        if ( empty( $pdf_files ) ) {
            return $this->error( 'Nessun PDF da analizzare.' );
        }

        $model       = $options['model']       ?? VulcanicaSettings::get_model();
        $temperature = $options['temperature'] ?? 0.3;
        $batch_size  = $options['batch_size']  ?? 5; // Max 5 PDF per batch (~25MB = ~850k token)

        // Usa il prompt configurabile dalle impostazioni (Vulcanica → Impostazioni → Prompt Analisi Storica)
        $sys_prompt   = VulcanicaSettings::get_system_prompt();
        $batch_prompt = VulcanicaSettings::get_preprocessing_prompt();

        $batches = array_chunk( $pdf_files, $batch_size );
        $total   = count( $batches );
        error_log( '[Vulcanica] PDF preprocessing: ' . count( $pdf_files ) . ' PDFs → ' . $total . ' batch(es) of ' . $batch_size );

        $batch_results = [];

        foreach ( $batches as $i => $batch ) {
            $batch_num = $i + 1;
            error_log( "[Vulcanica] PDF preprocessing: batch {$batch_num}/{$total} (" . count( $batch ) . ' files)' );

            // Retry automatico per "high demand" (max 3 tentativi, attesa crescente)
            $result    = null;
            $max_tries = 3;
            for ( $try = 1; $try <= $max_tries; $try++ ) {
                $result = $this->generate( $batch_prompt, [
                    'model'         => $model,
                    'max_tokens'    => 6000,
                    'temperature'   => $temperature,
                    'system_prompt' => $sys_prompt,
                    'pdf_files'     => $batch,
                ] );

                if ( $result['success'] ) {
                    break; // Successo — esci dal loop retry
                }

                // Se è "high demand", aspetta e riprova
                $is_high_demand = stripos( $result['error'], 'high demand' ) !== false
                               || stripos( $result['error'], 'overloaded' ) !== false
                               || stripos( $result['error'], '503' ) !== false;

                if ( $is_high_demand && $try < $max_tries ) {
                    $wait = $try * 15; // 15s, 30s
                    error_log( "[Vulcanica] PDF preprocessing batch {$batch_num}: high demand, retry {$try}/{$max_tries} after {$wait}s" );
                    sleep( $wait );
                } else {
                    error_log( "[Vulcanica] PDF preprocessing batch {$batch_num} FAILED (try {$try}): " . $result['error'] );
                    break;
                }
            }

            if ( $result && $result['success'] ) {
                $batch_results[] = "### BATCH {$batch_num}/{$total}\n\n" . $result['content'];
                error_log( "[Vulcanica] PDF preprocessing batch {$batch_num}/{$total} OK: " . strlen( $result['content'] ) . ' chars' );
            } else {
                // Batch fallito: continua con gli altri (graceful degradation)
                error_log( "[Vulcanica] PDF preprocessing batch {$batch_num}/{$total} skipped after retries" );
            }
        }

        if ( empty( $batch_results ) ) {
            return $this->error( 'Tutti i batch di analisi PDF sono falliti. Riprova più tardi.' );
        }

        // Se c'è un solo batch, ritorna direttamente
        if ( count( $batch_results ) === 1 ) {
            return [ 'success' => true, 'content' => $batch_results[0], 'error' => '' ];
        }

        // Più batch: sintetizza in un unico riassunto
        error_log( '[Vulcanica] PDF preprocessing: synthesizing ' . count( $batch_results ) . ' batch results' );

        $combined = implode( "\n\n---\n\n", $batch_results );
        $synth_prompt = "Hai analizzato " . count( $batch_results ) . " gruppi di bilanci di genere storici. "
            . "Sintetizza i risultati in un unico riassunto coerente con le stesse sezioni (PERIODO, TENDENZE, DATI QUANTITATIVI, GAP, PUNTI DI FORZA, CRITICITÀ, RACCOMANDAZIONI). "
            . "Elimina le ripetizioni, mantieni i dati numerici più significativi.\n\n"
            . $combined;

        $synth = $this->generate( $synth_prompt, [
            'model'         => $model,
            'max_tokens'    => 8000,
            'temperature'   => $temperature,
            'system_prompt' => $sys_prompt,
        ] );

        // Se la sintesi fallisce, ritorna comunque i batch concatenati (meglio di niente)
        if ( ! $synth['success'] ) {
            error_log( '[Vulcanica] PDF preprocessing: synthesis failed, returning raw batch results' );
            return [ 'success' => true, 'content' => $combined, 'error' => '' ];
        }

        return $synth;
    }

    /**
     * Carica un file PDF a Gemini Files API usando Resumable Upload Protocol
     * Se il file ha un cache valido, lo riusa senza reuploadadlo
     *
     * @param string $file_path Percorso completo del file
     * @param string $filename Nome del file per display
     * @return array { success: bool, file_id: string, file_uri: string, error: string }
     */
    public function upload_file_to_gemini( $file_path, $filename ) {

        // ── Verifica cache Gemini ──────────────────────────────────────────
        // Se il file è già stato uploadato e il cache è ancora valido, riusalo
        require_once __DIR__ . '/class-pdf-manager.php';
        if ( VulcanicaPDFManager::is_gemini_cache_valid( $filename ) ) {
            $pdfs = VulcanicaPDFManager::get_uploaded_pdfs();
            foreach ( $pdfs as $pdf ) {
                if ( $pdf['filename'] === $filename ) {
                    error_log( "[Vulcanica] ✅ Using cached Gemini file_id for: {$filename}" );
                    return [
                        'success' => true,
                        'file_id' => $pdf['gemini_file_id'],
                        'file_uri' => $pdf['gemini_file_uri'],
                        'error' => '',
                    ];
                }
            }
        }

        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => "File non trovato o non leggibile: {$file_path}",
            ];
        }

        if ( ! function_exists( 'curl_init' ) ) {
            error_log( "[Vulcanica] cURL non è disponibile sul server" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => 'cURL not available on server',
            ];
        }

        $file_size = filesize( $file_path );
        error_log( "[Vulcanica] PDF file: {$filename}, size={$file_size} bytes" );

        if ( $file_size === 0 || $file_size === false ) {
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => "File vuoto o non leggibile: {$file_path}",
            ];
        }

        // ───────────────────────────────────────────────────────────────
        // FASE 1: Inizializzazione Resumable Upload (chiedi upload URL)
        // ───────────────────────────────────────────────────────────────

        $init_url = 'https://generativelanguage.googleapis.com/upload/v1beta/files?key=' . $this->api_key;

        $init_headers = [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $file_size,
            'X-Goog-Upload-Header-Content-Type: application/pdf',
            'Content-Type: application/json',
        ];

        $init_body = wp_json_encode( [
            'file' => [
                'display_name' => $filename,
            ],
        ] );

        error_log( "[Vulcanica] FASE 1 - Initializing resumable upload for: {$filename}" );

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL            => $init_url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $init_headers,
            CURLOPT_POSTFIELDS     => $init_body,
            CURLOPT_HEADER         => true,  // Include header in output
        ] );

        $full_response = curl_exec( $ch );
        $init_http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_error = curl_error( $ch );

        curl_close( $ch );

        if ( $full_response === false ) {
            error_log( "[Vulcanica] cURL error in FASE 1: {$curl_error}" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => "cURL error: {$curl_error}",
            ];
        }

        // Separa header e body dalla risposta
        if ( strpos( $full_response, "\r\n\r\n" ) !== false ) {
            list( $response_headers, $response_body ) = explode( "\r\n\r\n", $full_response, 2 );
        } else {
            $response_headers = '';
            $response_body = $full_response;
        }

        error_log( "[Vulcanica] FASE 1 response - HTTP {$init_http_code}, headers: {$response_headers}" );

        if ( $init_http_code !== 200 ) {
            $init_data = json_decode( $response_body, true );
            $api_error = $init_data['error']['message'] ?? "HTTP {$init_http_code}";
            error_log( "[Vulcanica] FASE 1 FAILED: {$filename} - {$api_error}" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => $api_error,
            ];
        }

        // ───────────────────────────────────────────────────────────────
        // Estrai l'upload URL dai headers della risposta FASE 1
        // ───────────────────────────────────────────────────────────────

        $upload_url = '';
        foreach ( explode( "\r\n", $response_headers ) as $header_line ) {
            if ( stripos( $header_line, 'x-goog-upload-url:' ) === 0 ) {
                $upload_url = trim( substr( $header_line, strlen( 'x-goog-upload-url:' ) ) );
                break;
            }
        }

        if ( empty( $upload_url ) ) {
            error_log( "[Vulcanica] FASE 2 FAILED: Could not find x-goog-upload-url header in: {$response_headers}" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => 'Could not get upload URL from server',
            ];
        }

        error_log( "[Vulcanica] FASE 2 - Upload URL obtained: {$upload_url}" );

        // ───────────────────────────────────────────────────────────────
        // FASE 2: Upload del file binario
        // ───────────────────────────────────────────────────────────────

        // Leggi il contenuto del file
        $file_content = file_get_contents( $file_path );
        if ( $file_content === false ) {
            error_log( "[Vulcanica] FASE 2 FAILED: Could not read file: {$file_path}" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => 'Could not read file',
            ];
        }

        // Upload il file binario
        $upload_headers = [
            'X-Goog-Upload-Command: upload, finalize',
            'X-Goog-Upload-Offset: 0',
            'Content-Length: ' . strlen( $file_content ),
        ];

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL            => $upload_url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $upload_headers,
            CURLOPT_POSTFIELDS     => $file_content,
            CURLOPT_HEADER         => true,
        ] );

        $upload_response = curl_exec( $ch );
        $upload_http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_error = curl_error( $ch );

        curl_close( $ch );

        if ( $upload_response === false ) {
            error_log( "[Vulcanica] cURL error in FASE 2: {$curl_error}" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => "cURL error: {$curl_error}",
            ];
        }

        error_log( "[Vulcanica] FASE 2 response - HTTP {$upload_http_code}" );

        // Separa header e body dalla risposta
        if ( strpos( $upload_response, "\r\n\r\n" ) !== false ) {
            list( $upload_headers_out, $upload_body ) = explode( "\r\n\r\n", $upload_response, 2 );
        } else {
            $upload_body = $upload_response;
        }

        error_log( "[Vulcanica] FASE 2 body: {$upload_body}" );

        if ( $upload_http_code !== 200 ) {
            $upload_data = json_decode( $upload_body, true );
            $api_error = $upload_data['error']['message'] ?? "HTTP {$upload_http_code}";
            error_log( "[Vulcanica] PDF upload to Gemini FAILED: {$filename} - {$api_error}" );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => $api_error,
            ];
        }

        $data = json_decode( $upload_body, true );
        // La risposta ha struttura: { "file": { "name": "files/...", "uri": "...", "expirationTime": "...", ... } }
        $file_id = $data['file']['name'] ?? '';
        $file_uri = $data['file']['uri'] ?? '';
        $expiration_time = $data['file']['expirationTime'] ?? '';

        if ( empty( $file_id ) ) {
            error_log( "[Vulcanica] PDF upload FAILED: {$filename} - No file_id in response: " . wp_json_encode( $data ) );
            return [
                'success' => false,
                'file_id' => '',
                'file_uri' => '',
                'error' => 'No file_id in response',
            ];
        }

        error_log( "[Vulcanica] PDF uploaded to Gemini: {$filename}, file_id={$file_id}" );

        // ── Conta i token reali via countTokens API ───────────────────────────
        $token_count = 0;
        $token_result = $this->count_tokens_for_file( $file_uri );
        if ( $token_result['success'] ) {
            $token_count = $token_result['token_count'];
        }

        // ── Salva il cache Gemini + token count nel metadata del PDF ──────────
        VulcanicaPDFManager::update_pdf_gemini_cache( $filename, $file_id, $file_uri, $expiration_time, $token_count );
        error_log( "[Vulcanica] ✅ Cached Gemini file_id for {$filename}, expires: {$expiration_time}, tokens: {$token_count}" );

        return [
            'success'     => true,
            'file_id'     => $file_id,
            'file_uri'    => $file_uri,
            'token_count' => $token_count,
            'error'       => '',
        ];
    }

    /**
     * Conta i token reali di un file già caricato su Gemini.
     * Usa l'endpoint countTokens con la fileUri restituita dall'upload.
     *
     * @param string $file_uri  URI del file Gemini (es. https://generativelanguage.googleapis.com/v1beta/files/...)
     * @return array { success: bool, token_count: int, error: string }
     */
    public function count_tokens_for_file( $file_uri ) {
        if ( empty( $this->api_key ) ) {
            return [ 'success' => false, 'token_count' => 0, 'error' => 'API Key mancante' ];
        }

        $model = VulcanicaSettings::get_model();
        $url   = $this->api_base . $model . ':countTokens?key=' . $this->api_key;

        $body = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'fileData' => [
                                'mimeType' => 'application/pdf',
                                'fileUri'  => $file_uri,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'token_count' => 0, 'error' => $response->get_error_message() ];
        }

        $http_status = wp_remote_retrieve_response_code( $response );
        $data        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_status !== 200 || ! isset( $data['totalTokens'] ) ) {
            $err = $data['error']['message'] ?? "HTTP {$http_status}";
            error_log( "[Vulcanica] countTokens FAILED for {$file_uri}: {$err}" );
            return [ 'success' => false, 'token_count' => 0, 'error' => $err ];
        }

        $count = (int) $data['totalTokens'];
        error_log( "[Vulcanica] countTokens: {$count} token per {$file_uri}" );
        return [ 'success' => true, 'token_count' => $count, 'error' => '' ];
    }

    /**
     * Costruisce il corpo multipart per l'upload di un file a Gemini
     *
     * @param string $file_content Il contenuto binario del file
     * @param string $filename Nome del file
     * @param string $boundary Boundary string per il multipart
     * @return string Corpo multipart formattato
     */
    private function build_multipart_body( $file_content, $filename, $boundary ) {
        $eol = "\r\n";
        $body = '';

        // Parte 1: metadata.display_name
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"metadata.display_name\"{$eol}";
        $body .= "{$eol}";
        $body .= $filename . $eol;

        // Parte 2: file (binary)
        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"{$eol}";
        $body .= "Content-Type: application/pdf{$eol}";
        $body .= "{$eol}";
        $body .= $file_content . $eol;

        // Chiusura boundary
        $body .= "--{$boundary}--{$eol}";

        return $body;
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function error( $message ) {
        return [
            'success' => false,
            'content' => '',
            'error'   => $message,
        ];
    }
}
?>
