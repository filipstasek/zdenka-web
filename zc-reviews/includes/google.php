<?php
/**
 * Recenzie z Google – ručne, bez Google Places API.
 *
 * Doteraz sa recenzie sťahovali cez Places API. To je dnes už iba platené
 * (vyžaduje aktívny billing účet), a aj tak vracalo najviac 5 recenzií.
 * Recenzie z Google sa preto pridávajú ručne ako bežné recenzie so zdrojom
 * „Google" – na webe dostanú značku „Recenzia z Google" a dajú sa radiť,
 * skrývať aj upravovať ako ostatné.
 *
 * Tento súbor už nerobí žiadne volanie na Google. Obsahuje len jednorazový
 * prevod: recenzie, ktoré boli naposledy stiahnuté a ležia v medzipamäti,
 * uloží do databázy, aby z webu nezmizli, a zmaže uložený API kľúč.
 */
defined('ABSPATH') || exit;

/* Staré názvy funkcií ponechané – keby ich niečo ešte volalo, nespadne to. */
function zcr_google_enabled() { return false; }
function zcr_google_fetch($force = false) { return []; }

/**
 * Jednorazový prevod pri prvom načítaní po aktualizácii.
 * Beží aj na frontende (init), aby recenzie nezmizli ani vtedy, keď sa
 * nikto neprihlási do administrácie skôr, ako medzipamäť vyprší.
 */
add_action('init', function () {
    if (get_option('zcr_google_migrated') === '1') return;
    if (!function_exists('zcr_table') || !function_exists('zcr_ensure_source_column')) return;
    if (!zcr_ensure_source_column()) return;          // tabuľka ešte nie je – skúsime nabudúce

    global $wpdb;
    $t        = zcr_table();
    $imported = 0;
    $cached   = get_transient('zcr_google_reviews');

    if (is_array($cached)) {
        $order = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort_order),0) FROM {$t}");
        foreach ($cached as $g) {
            $name = sanitize_text_field($g->author_name ?? '');
            $body = sanitize_textarea_field($g->body ?? '');
            if ($name === '' || $body === '') continue;

            // Rovnakú recenziu nevkladáme druhýkrát
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE author_name = %s AND body = %s", $name, $body));
            if ($exists) continue;

            $wpdb->insert($t, [
                'author_name' => $name,
                // „pred 2 mesiacmi" by časom klamalo – zdroj ukáže značka Google
                'author_role' => '',
                'body'        => $body,
                'rating'      => max(1, min(5, (int) ($g->rating ?? 5))),
                'avatar_url'  => esc_url_raw($g->avatar_url ?? ''),
                'published'   => 1,
                'sort_order'  => ++$order,
                'source'      => 'google',
            ]);
            if ($wpdb->insert_id) $imported++;
        }
    }

    // API kľúč a nastavenia napojenia už netreba – kľúč nenechávame v databáze
    foreach (['zcr_google_reviews'] as $tr) delete_transient($tr);
    foreach (['zcr_google_key', 'zcr_google_place', 'zcr_google_on', 'zcr_google_min_rating',
              'zcr_google_error', 'zcr_google_meta', 'zcr_google_position'] as $opt) {
        delete_option($opt);
    }

    update_option('zcr_google_migrated', '1', false);
    if ($imported) update_option('zcr_google_imported', $imported, false);
}, 20);

/** Upozornenie vo wp-admine po prevode – raz. */
add_action('admin_notices', function () {
    $n = (int) get_option('zcr_google_imported', 0);
    if (!$n || !current_user_can('edit_posts')) return;
    delete_option('zcr_google_imported');
    printf('<div class="notice notice-success is-dismissible"><p><strong>Recenzie z Google:</strong> '
        . '%d %s z Google sme uložili medzi ostatné recenzie, aby z webu nezmizli. '
        . 'Napojenie na Google API je zrušené – ďalšie recenzie z Google pridávaj ručne '
        . 'a pri zdroji vyber „Google".</p></div>',
        $n, $n === 1 ? 'recenziu' : ($n < 5 ? 'recenzie' : 'recenzií'));
});
