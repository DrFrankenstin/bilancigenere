<?php
/**
 * Shortcode [vulcanica_bilanci_list]
 * Lista frontend dei Bilanci di Genere — visibile solo agli amministratori.
 */

class VulcanicaFrontendList {

    public static function init() {
        add_shortcode( 'vulcanica_bilanci_list', [ __CLASS__, 'render' ] );
    }

    /**
     * Renderizza la lista dei bilanci.
     *
     * @return string HTML
     */
    public static function render() {

        // Solo amministratori
        if ( ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'Accesso riservato agli amministratori.', 'vulcanica' ) . '</p>';
        }

        $query = new WP_Query( [
            'post_type'      => 'bilancio_genere',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        if ( ! $query->have_posts() ) {
            return '<p>Nessun bilancio di genere trovato.</p>';
        }

        ob_start();
        ?>
        <div class="vcm-bilanci-list">
            <table class="vcm-bilanci-table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Data</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                    <tr>
                        <td class="vcm-col-title">
                            <?php the_title(); ?>
                        </td>
                        <td class="vcm-col-date">
                            <?php echo esc_html( get_the_date( 'd/m/Y' ) ); ?>
                        </td>
                        <td class="vcm-col-status">
                            <?php
                            $status = get_post_status();
                            echo $status === 'publish'
                                ? '<span class="vcm-badge vcm-badge--published">Pubblicato</span>'
                                : '<span class="vcm-badge vcm-badge--draft">Bozza</span>';
                            ?>
                        </td>
                        <td class="vcm-col-actions">
                            <?php if ( get_post_status() === 'publish' ) : ?>
                                <a href="<?php echo esc_url( get_permalink() ); ?>" class="vcm-btn vcm-btn--view" target="_blank">
                                    Visualizza
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( get_edit_post_link( get_the_ID(), 'raw' ) ); ?>" class="vcm-btn vcm-btn--edit">
                                Modifica
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>
        <style>
            .vcm-bilanci-list { overflow-x: auto; }
            .vcm-bilanci-table { width: 100%; border-collapse: collapse; font-size: 14px; }
            .vcm-bilanci-table th,
            .vcm-bilanci-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e5e5; vertical-align: middle; }
            .vcm-bilanci-table thead th { background: #f8f8f8; font-weight: 600; color: #333; }
            .vcm-bilanci-table tbody tr:hover { background: #fafafa; }
            .vcm-col-date { white-space: nowrap; color: #666; }
            .vcm-col-actions { white-space: nowrap; }
            .vcm-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
            .vcm-badge--published { background: #d4edda; color: #155724; }
            .vcm-badge--draft     { background: #fff3cd; color: #856404; }
            .vcm-btn { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; text-decoration: none; margin-right: 4px; }
            .vcm-btn--view { background: #0073aa; color: #fff; }
            .vcm-btn--view:hover { background: #005a87; color: #fff; }
            .vcm-btn--edit { background: #f0f0f0; color: #333; border: 1px solid #ccc; }
            .vcm-btn--edit:hover { background: #e0e0e0; color: #333; }
        </style>
        <?php
        return ob_get_clean();
    }
}

add_action( 'init', [ 'VulcanicaFrontendList', 'init' ] );
