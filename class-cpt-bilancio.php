<?php
/**
 * Custom Post Type: Bilancio di Genere
 * Memorizza i risultati dell'analisi AI
 */

class VulcanicaCPTBilancio {

    public static function register() {
        register_post_type( 'bilancio_genere', [
            'labels' => [
                'name' => 'Bilanci di Genere',
                'singular_name' => 'Bilancio di Genere',
                'add_new' => 'Aggiungi Nuovo',
                'add_new_item' => 'Aggiungi Nuovo Bilancio',
                'edit_item' => 'Modifica Bilancio',
                'new_item' => 'Nuovo Bilancio',
                'view_item' => 'Visualizza Bilancio',
                'search_items' => 'Cerca Bilanci',
                'not_found' => 'Nessun bilancio trovato',
            ],
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => true,
            'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author' ],
            'has_archive' => true,
            'rewrite' => [ 'slug' => 'bilancio' ],
            'menu_icon' => 'dashicons-chart-bar',
            'capability_type' => 'post',
        ] );
    }

    /**
     * Crea un nuovo CPT con i dati dell'elaborazione
     *
     * @param int $item_id ID del form item
     * @param string $ai_content Contenuto generato dall'AI
     * @param int $user_id ID dell'utente
     * @param array $metadata Metadati aggiuntivi
     * @return int|WP_Error ID del post creato, o WP_Error
     */
    public static function create_from_ai( $item_id, $ai_content, $user_id, $metadata = [] ) {

        // Genera titolo
        $title = "Bilancio di Genere — Voce modulo #" . $item_id;

        // Crea il post
        $post_id = wp_insert_post( [
            'post_type' => 'bilancio_genere',
            'post_title' => sanitize_text_field( $title ),
            'post_content' => wp_kses_post( $ai_content ),
            'post_status' => 'publish',
            'post_author' => intval( $user_id ),
        ] );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Salva metadati
        update_post_meta( $post_id, 'frm_item_id', intval( $item_id ) );
        update_post_meta( $post_id, 'user_id', intval( $user_id ) );
        update_post_meta( $post_id, 'generated_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, 'ai_model', 'gemini-2.0-flash' );

        // Salva metadati aggiuntivi
        if ( ! empty( $metadata ) ) {
            foreach ( $metadata as $key => $value ) {
                update_post_meta( $post_id, $key, is_array( $value ) ? wp_json_encode( $value ) : $value );
            }
        }

        return $post_id;
    }

    /**
     * Aggiorna lo stato di elaborazione nell'item form
     *
     * @param int $item_id
     * @param string $status 'elaborato', 'errore', etc
     * @param array $data Dati aggiuntivi
     */
    public static function update_item_status( $item_id, $status, $data = [] ) {
        global $wpdb;

        $status_data = [
            'status' => $status,
            'updated_at' => current_time( 'mysql' ),
        ];

        // Merge con dati aggiuntivi
        if ( ! empty( $data ) ) {
            $status_data = array_merge( $status_data, $data );
        }

        $wpdb->update(
            $wpdb->prefix . 'frm_items',
            [ 'description' => wp_json_encode( $status_data ) ],
            [ 'id' => intval( $item_id ) ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Recupera il CPT associato a un item form
     *
     * @param int $item_id
     * @return WP_Post|null
     */
    public static function get_by_item( $item_id ) {
        $args = [
            'post_type' => 'bilancio_genere',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'frm_item_id',
                    'value' => intval( $item_id ),
                ]
            ]
        ];

        $posts = get_posts( $args );
        return ! empty( $posts ) ? $posts[0] : null;
    }
}

// Registra il CPT quando WordPress si inizializza
add_action( 'init', [ 'VulcanicaCPTBilancio', 'register' ] );
?>
