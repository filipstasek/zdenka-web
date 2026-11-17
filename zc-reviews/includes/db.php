<?php
function zcr_install() {
    global $wpdb;
    $t  = $wpdb->prefix . ZCR_TABLE;
    $ch = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$t} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        author_name VARCHAR(191) NOT NULL,
        author_role VARCHAR(191) DEFAULT '',
        body        TEXT NOT NULL,
        rating      TINYINT DEFAULT 5,
        avatar_url  VARCHAR(500) DEFAULT '',
        published   TINYINT(1) DEFAULT 1,
        sort_order  INT DEFAULT 0,
        source      VARCHAR(20) NOT NULL DEFAULT 'own',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$ch};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    zcr_ensure_source_column();
    update_option('zcr_db_version', ZCR_VERSION);
}

/**
 * Stĺpec „source" (own | google). Pri existujúcej tabuľke ho dbDelta nepridá –
 * kvôli „IF NOT EXISTS" v CREATE TABLE ho vôbec neporovnáva – preto ho
 * dopĺňame sami.
 */
function zcr_ensure_source_column() {
    static $ok = null;
    if ($ok !== null) return $ok;
    global $wpdb;
    $t = $wpdb->prefix . ZCR_TABLE;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return $ok = false;
    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t} LIKE %s", 'source'));
    if ($col !== 'source') {
        $wpdb->query("ALTER TABLE {$t} ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'own' AFTER sort_order");
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t} LIKE %s", 'source'));
    }
    return $ok = ($col === 'source');
}

/** Povolené zdroje recenzie. */
function zcr_sources() {
    return ['own' => 'Vlastná', 'google' => 'Google'];
}
function zcr_sanitize_source($v) {
    $v = sanitize_key((string) $v);
    return isset(zcr_sources()[$v]) ? $v : 'own';
}
add_action('plugins_loaded', function() {
    if (get_option('zcr_db_version') !== ZCR_VERSION) zcr_install();
});
function zcr_table() { global $wpdb; return $wpdb->prefix . ZCR_TABLE; }
