<?php
/**
 * Test diretto dell'upload PDF - RIMUOVERE DOPO IL DEBUG
 * Accedi via: http://verso/wp-content/plugins/vulcanica-comuni-manager/test-upload.php
 */

// Abilita tutti gli errori
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test Upload PDF Debug</h2>";
echo "<p>PHP Version: " . PHP_VERSION . "</p>";

// Test estensioni
echo "<h3>Estensioni PHP</h3>";
echo "<p>fileinfo: " . (extension_loaded('fileinfo') ? '<span style="color:green">OK</span>' : '<span style="color:red">MANCANTE</span>') . "</p>";
echo "<p>mime_content_type: " . (function_exists('mime_content_type') ? '<span style="color:green">OK</span>' : '<span style="color:red">MANCANTE</span>') . "</p>";
echo "<p>finfo_open: " . (function_exists('finfo_open') ? '<span style="color:green">OK</span>' : '<span style="color:red">MANCANTE</span>') . "</p>";

// Test directory upload
define('ABSPATH', dirname(__FILE__, 4) . '/');
$wp_uploads = ABSPATH . 'wp-content/uploads/vulcanica-pdfs';
echo "<h3>Directory Upload</h3>";
echo "<p>Path: " . $wp_uploads . "</p>";
echo "<p>Esiste: " . (is_dir($wp_uploads) ? '<span style="color:green">SI</span>' : '<span style="color:red">NO</span>') . "</p>";
if (is_dir($wp_uploads)) {
    echo "<p>Scrivibile: " . (is_writable($wp_uploads) ? '<span style="color:green">SI</span>' : '<span style="color:red">NO</span>') . "</p>";
}

// Test wp_check_filetype_and_ext (carica WordPress)
define('WPINC', 'wp-includes');
echo "<h3>Test caricamento WordPress</h3>";

$wp_load = ABSPATH . 'wp-load.php';
if (file_exists($wp_load)) {
    echo "<p>wp-load.php trovato</p>";
    // Non carichiamo WP qui per evitare loop, solo testiamo i path
} else {
    echo "<p style='color:red'>wp-load.php NON trovato in: $wp_load</p>";
}

// Test write permessi
echo "<h3>Test scrittura</h3>";
$test_file = $wp_uploads . '/test-write.tmp';
if (is_dir($wp_uploads)) {
    if (file_put_contents($test_file, 'test') !== false) {
        echo "<p style='color:green'>Scrittura OK</p>";
        unlink($test_file);
    } else {
        echo "<p style='color:red'>Scrittura FALLITA - permessi insufficienti</p>";
    }
} else {
    // Prova a creare la directory
    if (mkdir($wp_uploads, 0755, true)) {
        echo "<p style='color:green'>Directory creata con successo</p>";
    } else {
        echo "<p style='color:red'>Impossibile creare la directory - permessi insufficienti</p>";
    }
}

echo "<hr><p><strong>Rimuovere questo file dopo il debug!</strong></p>";
?>
