<?php
/**
 * Script helper per creare il form Formidable di login (ID 4)
 * Esegui questo una sola volta, poi elimina il file
 *
 * Uso: Da WordPress admin o via PHP direct include
 */

// Se già esiste il form ID 4, non ricrearlo
global $wpdb;
$existing_form = $wpdb->get_row(
    "SELECT id FROM {$wpdb->prefix}frm_forms WHERE id = 4 LIMIT 1"
);

if ( $existing_form ) {
    echo "❌ Form ID 4 esiste già. Salta creazione.";
    return;
}

// ========================================================================
// STEP 1: Crea il form principale (wp_frm_forms)
// ========================================================================

$form_data = [
    'id'                 => 4,
    'form_key'           => 'login_form',
    'name'               => 'Login',
    'description'        => 'Form di login personalizzato',
    'created_at'         => current_time( 'mysql' ),
    'updated_at'         => current_time( 'mysql' ),
    'user_id'            => get_current_user_id() ?: 1,
    'logged_in'          => 0,
    'post_content'       => '',
    'auto_responder'     => 0,
    'layout'             => 'outlined',
    'css_classes'        => '',
    'return_print'       => 'form',
    'customize_html'     => 0,
    'in_footer'          => 0,
    'ajax_submit'        => 0,
    'hide_untouched'     => 0,
    'editable'           => 0,
    'status'             => 'published',
    'default_confirmation' => 'Grazie! Il tuo messaggio è stato inviato.',
    'submit_value'       => 'Accedi',
];

$inserted = $wpdb->insert(
    "{$wpdb->prefix}frm_forms",
    $form_data,
    [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
);

if ( ! $inserted ) {
    echo "❌ Errore nella creazione del form: " . $wpdb->last_error;
    return;
}

echo "✅ Form creato (ID 4)\n";

// ========================================================================
// STEP 2: Crea i campi del form (wp_frm_fields)
// ========================================================================

// CAMPO 1: Email
$field1 = [
    'form_id'       => 4,
    'field_key'     => 'user_login',
    'name'          => 'Email',
    'description'   => '',
    'type'          => 'text',
    'options'       => maybe_serialize( [ 'placeholder' => 'email@example.com' ] ),
    'required'      => 1,
    'created_at'    => current_time( 'mysql' ),
    'updated_at'    => current_time( 'mysql' ),
];

$wpdb->insert( "{$wpdb->prefix}frm_fields", $field1 );
$field1_id = $wpdb->insert_id;
echo "✅ Campo Email creato (ID $field1_id)\n";

// CAMPO 2: Password
$field2 = [
    'form_id'       => 4,
    'field_key'     => 'user_password',
    'name'          => 'Password',
    'description'   => '',
    'type'          => 'password',
    'options'       => maybe_serialize( [ 'placeholder' => 'La tua password' ] ),
    'required'      => 1,
    'created_at'    => current_time( 'mysql' ),
    'updated_at'    => current_time( 'mysql' ),
];

$wpdb->insert( "{$wpdb->prefix}frm_fields", $field2 );
$field2_id = $wpdb->insert_id;
echo "✅ Campo Password creato (ID $field2_id)\n";

// CAMPO 3: Submit button
$field3 = [
    'form_id'       => 4,
    'field_key'     => 'submit_button',
    'name'          => 'Accedi',
    'description'   => '',
    'type'          => 'submit',
    'options'       => maybe_serialize( [ 'label' => 'Accedi' ] ),
    'required'      => 0,
    'created_at'    => current_time( 'mysql' ),
    'updated_at'    => current_time( 'mysql' ),
];

$wpdb->insert( "{$wpdb->prefix}frm_fields", $field3 );
$field3_id = $wpdb->insert_id;
echo "✅ Pulsante Submit creato (ID $field3_id)\n";

echo "\n🎉 Form Formidable Login (ID 4) creato con successo!\n";
echo "Campo Email ID: $field1_id\n";
echo "Campo Password ID: $field2_id\n";
echo "Campo Submit ID: $field3_id\n";
?>
