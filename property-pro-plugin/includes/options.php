<?php
/**
 * Číselníky pri zadávaní ponuky (Stav, Vlastníctvo).
 *
 * Doteraz boli tieto možnosti napísané natvrdo v kóde na dvoch miestach –
 * vo wp-admine aj v realitnom paneli. Teraz sa spravujú na jednom mieste
 * a obe formy z nich čerpajú.
 *
 * Do meta poľa ponuky sa naďalej ukladá samotný názov (napr. „Novostavba"),
 * nie kľúč – takto to bolo od začiatku a menia to filtre aj šablóny. Preto
 * pri premenovaní položky rovno prepíšeme aj už uložené ponuky, aby na webe
 * nezostal starý názov.
 */
defined('ABSPATH') || exit;

/** Zoznamy, ktoré sa dajú spravovať. */
function pp_option_lists() {
    return [
        'stav' => [
            'title' => 'Stav nehnuteľnosti',
            'meta'  => '_property_stav',
            'help'  => 'Vyberá sa pri zadávaní ponuky v poli „Stav".',
        ],
        'vlastnictvo' => [
            'title' => 'Vlastníctvo',
            'meta'  => '_property_vlastnictvo',
            'help'  => 'Vyberá sa pri zadávaní ponuky v poli „Vlastníctvo".',
        ],
    ];
}

/** Pôvodné položky – použijú sa, kým si ich maklérka neupraví. */
function pp_option_defaults($key) {
    $d = [
        'stav' => [
            'Novostavba',
            'Kompletná rekonštrukcia',
            'Čiastočná rekonštrukcia',
            'Pôvodný stav',
        ],
        'vlastnictvo' => ['Osobné', 'Družstevné', 'Štátne', 'V príprave prevodu'],
    ];
    $out = [];
    foreach ($d[$key] ?? [] as $label) $out[] = ['label' => $label, 'hidden' => 0];
    return $out;
}

/** Uložený zoznam aj s nastavením skrytia. */
function pp_options($key) {
    if (!isset(pp_option_lists()[$key])) return [];
    $saved = get_option('pp_options_' . $key, null);
    if (!is_array($saved) || !$saved) return pp_option_defaults($key);

    $out = [];
    foreach ($saved as $row) {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') continue;
        $out[] = ['label' => $label, 'hidden' => !empty($row['hidden']) ? 1 : 0];
    }
    return $out ?: pp_option_defaults($key);
}

function pp_options_save($key, $rows) {
    if (!isset(pp_option_lists()[$key])) return;
    update_option('pp_options_' . $key, array_values($rows), false);
}

/**
 * Možnosti do rozbaľovacieho poľa.
 *
 * Skryté položky sa novým ponukám neponúkajú, ale ak ich niektorá ponuka
 * už má uloženú, necháme ju vidieť – inak by sa pri prvom uložení ticho
 * stratila.
 */
function pp_option_choices($key, $current = '') {
    $current = trim((string) $current);
    $out = [];
    foreach (pp_options($key) as $row) {
        if ($row['hidden'] && $row['label'] !== $current) continue;
        $out[] = $row['label'];
    }
    if ($current !== '' && !in_array($current, $out, true)) $out[] = $current;
    return $out;
}

/** Koľko ponúk má danú hodnotu uloženú. */
function pp_option_usage($key, $label) {
    $meta = pp_option_lists()[$key]['meta'] ?? '';
    if (!$meta || $label === '') return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'property'",
        $meta, $label
    ));
}

/** Premenovanie položky prepíše aj už uložené ponuky. */
function pp_option_rename($key, $stary, $novy) {
    $meta = pp_option_lists()[$key]['meta'] ?? '';
    if (!$meta || $stary === '' || $novy === '' || $stary === $novy) return 0;
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT m.post_id FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'property'",
        $meta, $stary
    ));
    foreach ($ids as $id) update_post_meta((int) $id, $meta, $novy);
    return count($ids);
}

/** Hodnoty, ktoré v ponukách sú, ale v zozname už nie sú. */
function pp_option_orphans($key) {
    $meta = pp_option_lists()[$key]['meta'] ?? '';
    if (!$meta) return [];
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT m.meta_value AS hodnota, COUNT(*) AS pocet FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type = 'property'
         GROUP BY m.meta_value", $meta
    ));
    $zoznam = array_column(pp_options($key), 'label');
    $out = [];
    foreach ($rows as $r) {
        if (!in_array($r->hodnota, $zoznam, true)) $out[] = [$r->hodnota, (int) $r->pocet];
    }
    return $out;
}

/* ─────────────── Typy ponúk, stavy ponúk a farby odznakov ────────────── */

/** Bezpečné farebné presety. Ukladá sa iba kľúč, nie ľubovoľné CSS. */
function pp_badge_presets() {
    return [
        'gold'   => ['label'=>'Zlatá',    'bg'=>'linear-gradient(135deg,#E8D39F,#B89450)', 'fg'=>'#2A2114', 'shadow'=>'0 5px 16px rgba(184,148,80,.42)'],
        'blue'   => ['label'=>'Modrá',    'bg'=>'linear-gradient(135deg,#2A7EA4,#174B78)', 'fg'=>'#FFFFFF', 'shadow'=>'0 5px 16px rgba(23,75,120,.42)'],
        'green'  => ['label'=>'Zelená',   'bg'=>'linear-gradient(135deg,#5A9B71,#2E684A)', 'fg'=>'#FFFFFF', 'shadow'=>'0 5px 16px rgba(46,104,74,.42)'],
        'amber'  => ['label'=>'Oranžová', 'bg'=>'linear-gradient(135deg,#F6B944,#C77912)', 'fg'=>'#3D2705', 'shadow'=>'0 5px 16px rgba(199,121,18,.46)'],
        'red'    => ['label'=>'Červená',  'bg'=>'linear-gradient(135deg,#F04444,#A90F1B)', 'fg'=>'#FFFFFF', 'shadow'=>'0 0 10px rgba(220,38,38,.82),0 6px 18px rgba(169,15,27,.42)'],
        'purple' => ['label'=>'Fialová',  'bg'=>'linear-gradient(135deg,#9B72CF,#62419A)', 'fg'=>'#FFFFFF', 'shadow'=>'0 5px 16px rgba(98,65,154,.44)'],
        'slate'  => ['label'=>'Sivá',     'bg'=>'linear-gradient(135deg,#7B8794,#46515E)', 'fg'=>'#FFFFFF', 'shadow'=>'0 5px 16px rgba(70,81,94,.4)'],
    ];
}

function pp_badge_preset($key) {
    $all = pp_badge_presets();
    return $all[$key] ?? $all['gold'];
}

function pp_catalog_defaults($kind) {
    if ($kind === 'types') {
        return [
            ['value'=>'predaj',   'label'=>'Na predaj',   'hidden'=>0, 'color'=>'gold',  'rental'=>0],
            ['value'=>'prenajom', 'label'=>'Na prenájom', 'hidden'=>0, 'color'=>'blue',  'rental'=>1],
            ['value'=>'pozemok',  'label'=>'Pozemok',     'hidden'=>0, 'color'=>'green', 'rental'=>0],
        ];
    }
    if ($kind === 'statuses') {
        return [
            ['value'=>'rezervovane', 'label'=>'Rezervované', 'rental_label'=>'Rezervované', 'hidden'=>0, 'color'=>'amber'],
            ['value'=>'predane', 'label'=>'Predané', 'rental_label'=>'Prenajaté', 'hidden'=>0, 'color'=>'red'],
        ];
    }
    return [];
}

/** Načíta katalóg a opraví staršie/neúplné uložené riadky. */
function pp_catalog_rows($kind) {
    if (!in_array($kind, ['types','statuses'], true)) return [];
    $saved = get_option('pp_catalog_' . $kind, null);
    if (!is_array($saved) || !$saved) return pp_catalog_defaults($kind);
    $out = [];
    foreach ($saved as $row) {
        $value = sanitize_key($row['value'] ?? '');
        $label = trim((string) ($row['label'] ?? ''));
        if ($value === '' || $label === '') continue;
        $color = sanitize_key($row['color'] ?? 'gold');
        if (!isset(pp_badge_presets()[$color])) $color = 'gold';
        $clean = ['value'=>$value, 'label'=>$label, 'hidden'=>!empty($row['hidden']) ? 1 : 0, 'color'=>$color];
        if ($kind === 'types') $clean['rental'] = !empty($row['rental']) || $value === 'prenajom' ? 1 : 0;
        if ($kind === 'statuses') {
            $clean['rental_label'] = trim((string) ($row['rental_label'] ?? '')) ?: $label;
        }
        $out[] = $clean;
    }
    return $out ?: pp_catalog_defaults($kind);
}

function pp_catalog_save($kind, $rows) {
    if (!in_array($kind, ['types','statuses'], true)) return false;
    return update_option('pp_catalog_' . $kind, array_values($rows), false);
}

function pp_catalog_meta_key($kind) {
    return $kind === 'types' ? '_property_typ' : ($kind === 'statuses' ? '_property_stav_predaja' : '');
}

function pp_catalog_usage($kind, $value) {
    $meta = pp_catalog_meta_key($kind);
    if (!$meta || $value === '') return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
         WHERE m.meta_key=%s AND m.meta_value=%s AND p.post_type='property'",
        $meta, $value
    ));
}

/** Viditeľné možnosti; aktuálna skrytá hodnota ostane vo formulári ponuky. */
function pp_catalog_choices($kind, $current = '') {
    $out = [];
    foreach (pp_catalog_rows($kind) as $row) {
        if (!empty($row['hidden']) && $row['value'] !== $current) continue;
        $out[$row['value']] = $row['label'];
    }
    if ($current !== '' && !isset($out[$current])) $out[$current] = $current;
    return $out;
}

function pp_property_types($current = '') {
    return pp_catalog_choices('types', $current);
}

function pp_offer_status_row($value) {
    foreach (pp_catalog_rows('statuses') as $row) if ($row['value'] === $value) return $row;
    return null;
}

function pp_property_type_row($value) {
    foreach (pp_catalog_rows('types') as $row) if ($row['value'] === $value) return $row;
    return null;
}

function pp_property_type_label($value) {
    $row = pp_property_type_row($value);
    return $row ? $row['label'] : (string) $value;
}

function pp_property_type_is_rental($value) {
    $row = pp_property_type_row($value);
    return $row ? !empty($row['rental']) : $value === 'prenajom';
}

function pp_offer_status_rows() {
    return pp_catalog_rows('statuses');
}

function pp_offer_status_label($value, $type = '') {
    if ($value === '') return 'Aktívna';
    foreach (pp_offer_status_rows() as $row) {
        if ($row['value'] !== $value) continue;
        return pp_property_type_is_rental($type) && !empty($row['rental_label']) ? $row['rental_label'] : $row['label'];
    }
    return (string) $value;
}

function pp_offer_badge($value, $type = '') {
    if ($value === '') return null;
    foreach (pp_offer_status_rows() as $row) {
        if ($row['value'] !== $value) continue;
        $preset = pp_badge_preset($row['color']);
        $label = pp_offer_status_label($value, $type);
        $label = function_exists('mb_strtoupper') ? mb_strtoupper($label) : strtoupper($label);
        return ['label'=>$label, 'bg'=>$preset['bg'], 'fg'=>$preset['fg'], 'shadow'=>$preset['shadow'], 'color'=>$row['color']];
    }
    return null;
}

function pp_property_type_badge($value) {
    $row = pp_property_type_row($value);
    if (!$row) return null;
    $preset = pp_badge_preset($row['color']);
    return ['label'=>$row['label'], 'bg'=>$preset['bg'], 'fg'=>$preset['fg'], 'shadow'=>$preset['shadow'], 'color'=>$row['color']];
}

/* ───────────────────────── Stránka vo wp-admine ───────────────────────── */

add_action('admin_menu', function () {
    $parent = function_exists('zc_hub_slug') ? zc_hub_slug() : 'edit.php?post_type=property';
    add_submenu_page($parent, 'Možnosti pri ponukách', 'Možnosti ponúk', 'manage_options',
        'zc-moznosti', 'pp_options_admin_page');
}, 12);

add_action('admin_init', function () {
    if (empty($_POST['pp_opts_key']) || ($_GET['page'] ?? '') !== 'zc-moznosti') return;
    if (!current_user_can('manage_options') || !check_admin_referer('pp_options')) return;

    $key = sanitize_key($_POST['pp_opts_key']);
    if (!isset(pp_option_lists()[$key])) return;

    if (isset($_POST['pp_opts_reset'])) {
        delete_option('pp_options_' . $key);
        pp_options_notice('Možnosti sú vrátené na pôvodné.', true);
        pp_options_redirect($key);
    }

    $rows        = [];
    $premenovane = 0;
    $dotknute    = 0;
    foreach ((array) ($_POST['opt'] ?? []) as $row) {
        $label = trim(sanitize_text_field(wp_unslash($row['label'] ?? '')));
        if ($label === '') continue;                        // prázdny názov = zmazané
        $povodny = trim(sanitize_text_field(wp_unslash($row['orig'] ?? '')));
        if ($povodny !== '' && $povodny !== $label) {
            $n = pp_option_rename($key, $povodny, $label);
            if ($n) { $premenovane++; $dotknute += $n; }
        }
        $rows[] = ['label' => $label, 'hidden' => !empty($row['hidden']) ? 1 : 0];
    }

    $nova = trim(sanitize_text_field(wp_unslash($_POST['pp_opts_new'] ?? '')));
    if ($nova !== '') $rows[] = ['label' => $nova, 'hidden' => 0];

    if (!$rows) {
        pp_options_notice('Aspoň jedna možnosť musí ostať.', false);
        pp_options_redirect($key);
    }

    // Rovnaký názov dvakrát nemá zmysel
    $videne = [];
    $rows = array_values(array_filter($rows, function ($r) use (&$videne) {
        $k = function_exists('mb_strtolower') ? mb_strtolower($r['label']) : strtolower($r['label']);
        if (isset($videne[$k])) return false;
        $videne[$k] = 1;
        return true;
    }));

    pp_options_save($key, $rows);
    $sprava = 'Možnosti uložené.';
    if ($premenovane) {
        $sprava .= sprintf(' Premenované: %d – upravili sme aj %d %s.',
            $premenovane, $dotknute, $dotknute === 1 ? 'ponuku' : 'ponúk');
    }
    pp_options_notice($sprava, true);
    pp_options_redirect($key);
});

/* Hromadné nahradenie hodnoty, ktorá už v zozname nie je */
add_action('admin_init', function () {
    if (empty($_POST['pp_opts_migrate']) || ($_GET['page'] ?? '') !== 'zc-moznosti') return;
    if (!current_user_can('manage_options') || !check_admin_referer('pp_options')) return;

    $key   = sanitize_key($_POST['pp_opts_key'] ?? '');
    $stara = trim(sanitize_text_field(wp_unslash($_POST['pp_opts_from'] ?? '')));
    $nova  = trim(sanitize_text_field(wp_unslash($_POST['pp_opts_to'] ?? '')));
    if (!isset(pp_option_lists()[$key]) || $stara === '' || $nova === '') return;

    $n = pp_option_rename($key, $stara, $nova);
    pp_options_notice(sprintf('Hodnota „%s" bola v %d %s nahradená za „%s".',
        $stara, $n, $n === 1 ? 'ponuke' : 'ponukách', $nova), true);
    pp_options_redirect($key);
});

function pp_options_notice($msg, $ok = true) {
    set_transient('pp_opts_notice_' . get_current_user_id(),
        ['msg' => (string) $msg, 'ok' => (bool) $ok], MINUTE_IN_SECONDS);
}

function pp_options_redirect($key) {
    wp_safe_redirect(add_query_arg(['page' => 'zc-moznosti', 'zoznam' => $key], admin_url('admin.php')));
    exit;
}

function pp_options_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Bez oprávnenia.');
    if (function_exists('zc_hub_styles')) zc_hub_styles();

    $lists = pp_option_lists();
    $key   = sanitize_key($_GET['zoznam'] ?? '');
    if (!isset($lists[$key])) $key = array_key_first($lists);
    $rows  = pp_options($key);
    $siroty = pp_option_orphans($key);

    $notice_key = 'pp_opts_notice_' . get_current_user_id();
    $notice = get_transient($notice_key);
    if ($notice) delete_transient($notice_key);
    ?>
    <div class="wrap zch">
        <div class="zch-head"><h1>Možnosti pri ponukách</h1></div>

        <?php if ($notice && !empty($notice['msg'])): ?>
        <div class="notice notice-<?php echo !empty($notice['ok']) ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo esc_html($notice['msg']); ?></p></div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper" style="margin-bottom:18px">
            <?php foreach ($lists as $k => $l): ?>
            <a class="nav-tab<?php echo $k === $key ? ' nav-tab-active' : ''; ?>"
               href="<?php echo esc_url(add_query_arg(['page' => 'zc-moznosti', 'zoznam' => $k], admin_url('admin.php'))); ?>">
                <?php echo esc_html($l['title']); ?></a>
            <?php endforeach; ?>
        </h2>

        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:26px;max-width:820px">
            <p style="color:#555;font-size:13px;line-height:1.7;margin:0 0 18px">
                <?php echo esc_html($lists[$key]['help']); ?><br>
                <strong>Skryté</strong> možnosti sa pri novej ponuke neponúkajú, ale ponuky,
                ktoré ich už majú, si ich nechajú.<br>
                Možnosť <strong>zmažeš tak, že vymažeš jej názov</strong> a uložíš.
                Keď názov <strong>prepíšeš</strong>, opravíme ho aj vo všetkých ponukách,
                ktoré ho majú uložený.
            </p>

            <form method="post">
                <?php wp_nonce_field('pp_options'); ?>
                <input type="hidden" name="pp_opts_key" value="<?php echo esc_attr($key); ?>">
                <table class="wp-list-table widefat striped" style="margin-bottom:16px">
                    <thead><tr>
                        <th style="width:58%">Názov</th>
                        <th style="width:16%">Skryť</th>
                        <th style="width:26%">Použité v ponukách</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $i => $row): $pouzite = pp_option_usage($key, $row['label']); ?>
                    <tr>
                        <td>
                            <input type="text" name="opt[<?php echo $i ?>][label]"
                                   value="<?php echo esc_attr($row['label']); ?>" style="width:100%">
                            <input type="hidden" name="opt[<?php echo $i ?>][orig]"
                                   value="<?php echo esc_attr($row['label']); ?>">
                        </td>
                        <td><label><input type="checkbox" name="opt[<?php echo $i ?>][hidden]" value="1"
                                   <?php checked(!empty($row['hidden'])); ?>> skryť</label></td>
                        <td><?php echo $pouzite
                            ? '<strong>' . $pouzite . '</strong> ' . ($pouzite === 1 ? 'ponuka' : 'ponúk')
                            : '<span style="color:#6B6560">zatiaľ nikde</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><input type="text" name="pp_opts_new" placeholder="Nová možnosť…" style="width:100%"></td>
                        <td colspan="2" style="color:#6B6560;font-size:12px">pridá sa na koniec</td>
                    </tr>
                    </tbody>
                </table>
                <button type="submit" class="button button-primary">Uložiť</button>
                <button type="submit" name="pp_opts_reset" value="1" class="button"
                        onclick="return confirm('Naozaj vrátiť pôvodné možnosti? Ponuky sa nezmenia.')">
                    Vrátiť pôvodné</button>
            </form>
        </div>

        <?php if ($siroty): ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:26px;max-width:820px;margin-top:18px">
            <h3 style="margin:0 0 6px">Hodnoty, ktoré v zozname nie sú</h3>
            <p style="color:#555;font-size:13px;line-height:1.7;margin:0 0 16px">
                Tieto ponuky majú uloženú hodnotu, ktorá už medzi možnosťami nie je –
                napríklad z čias pred úpravou zoznamu. Na webe sa zobrazujú tak, ako sú.
                Tu ich vieš hromadne nahradiť.
            </p>
            <table class="wp-list-table widefat striped">
                <thead><tr><th>Hodnota</th><th style="width:90px">Ponúk</th><th style="width:340px">Nahradiť za</th></tr></thead>
                <tbody>
                <?php foreach ($siroty as [$hodnota, $pocet]): ?>
                <tr>
                    <td><strong><?php echo esc_html($hodnota); ?></strong></td>
                    <td><?php echo $pocet; ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:8px">
                            <?php wp_nonce_field('pp_options'); ?>
                            <input type="hidden" name="pp_opts_migrate" value="1">
                            <input type="hidden" name="pp_opts_key" value="<?php echo esc_attr($key); ?>">
                            <input type="hidden" name="pp_opts_from" value="<?php echo esc_attr($hodnota); ?>">
                            <select name="pp_opts_to" style="flex:1">
                                <?php foreach ($rows as $r): ?>
                                <option value="<?php echo esc_attr($r['label']); ?>"><?php echo esc_html($r['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button">Nahradiť</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ───────────────── Správa položiek priamo v realitnom paneli ─────────── */

function pp_panel_options_notice($message, $ok = true) {
    set_transient('pp_panel_options_' . get_current_user_id(), [
        'message' => sanitize_text_field($message), 'ok' => (bool) $ok,
    ], MINUTE_IN_SECONDS);
}

function pp_panel_options_redirect($section) {
    $url = function_exists('pp_panel_url')
        ? pp_panel_url('action=options&section=' . rawurlencode($section))
        : home_url('/realitny-panel/?action=options&section=' . rawurlencode($section));
    wp_safe_redirect($url);
    exit;
}

add_action('admin_post_pp_panel_options_save', function () {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_die('Nemáte oprávnenie upravovať položky ponúk.', 'Prístup zamietnutý', ['response'=>403]);
    }
    check_admin_referer('pp_panel_options', '_ppoptnonce');
    $section = sanitize_key($_POST['section'] ?? 'types');
    if (!in_array($section, ['types','statuses','stav','vlastnictvo'], true)) $section = 'types';

    if (!empty($_POST['reset'])) {
        if (in_array($section, ['types','statuses'], true)) {
            $defaults = pp_catalog_defaults($section);
            $default_values = array_column($defaults, 'value');
            foreach (pp_catalog_rows($section) as $old_row) {
                if (!in_array($old_row['value'], $default_values, true) && pp_catalog_usage($section, $old_row['value'])) {
                    $old_row['hidden'] = 1;
                    $defaults[] = $old_row;
                }
            }
            pp_catalog_save($section, $defaults);
        } else delete_option('pp_options_' . $section);
        pp_panel_options_notice('Pôvodné možnosti boli obnovené.', true);
        pp_panel_options_redirect($section);
    }

    if (in_array($section, ['types','statuses'], true)) {
        $rows = [];
        $seen = [];
        $presets = pp_badge_presets();
        foreach ((array) ($_POST['item'] ?? []) as $item) {
            $value = sanitize_key(wp_unslash($item['value'] ?? ''));
            $label = trim(sanitize_text_field(wp_unslash($item['label'] ?? '')));
            if ($value === '' || isset($seen[$value])) continue;
            $used = pp_catalog_usage($section, $value);
            if ($label === '') {
                // Použitú položku nikdy potichu nezničíme; iba ju skryjeme.
                if (!$used) continue;
                $label = trim(sanitize_text_field(wp_unslash($item['original_label'] ?? ''))) ?: $value;
                $item['hidden'] = 1;
            }
            $color = sanitize_key(wp_unslash($item['color'] ?? 'gold'));
            if (!isset($presets[$color])) $color = 'gold';
            $row = ['value'=>$value, 'label'=>$label, 'hidden'=>!empty($item['hidden']) ? 1 : 0, 'color'=>$color];
            if ($section === 'types') $row['rental'] = !empty($item['rental']) ? 1 : 0;
            if ($section === 'statuses') {
                $row['rental_label'] = trim(sanitize_text_field(wp_unslash($item['rental_label'] ?? ''))) ?: $label;
            }
            $seen[$value] = 1;
            $rows[] = $row;
        }

        $new_label = trim(sanitize_text_field(wp_unslash($_POST['new_label'] ?? '')));
        if ($new_label !== '') {
            $base = sanitize_title($new_label) ?: 'polozka';
            $value = $base;
            for ($i=2; isset($seen[$value]); $i++) $value = $base . '-' . $i;
            $color = sanitize_key(wp_unslash($_POST['new_color'] ?? 'gold'));
            if (!isset($presets[$color])) $color = 'gold';
            $row = ['value'=>$value, 'label'=>$new_label, 'hidden'=>0, 'color'=>$color];
            if ($section === 'types') $row['rental'] = !empty($_POST['new_rental']) ? 1 : 0;
            if ($section === 'statuses') {
                $row['rental_label'] = trim(sanitize_text_field(wp_unslash($_POST['new_rental_label'] ?? ''))) ?: $new_label;
            }
            $rows[] = $row;
        }
        if (!$rows) {
            pp_panel_options_notice('Aspoň jedna položka musí zostať.', false);
            pp_panel_options_redirect($section);
        }
        pp_catalog_save($section, $rows);
    } else {
        $rows = [];
        $seen = [];
        $renamed = 0;
        foreach ((array) ($_POST['item'] ?? []) as $item) {
            $label = trim(sanitize_text_field(wp_unslash($item['label'] ?? '')));
            if ($label === '') continue;
            $original = trim(sanitize_text_field(wp_unslash($item['original_label'] ?? '')));
            $dedupe = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
            if (isset($seen[$dedupe])) continue;
            if ($original !== '' && $original !== $label) $renamed += pp_option_rename($section, $original, $label);
            $seen[$dedupe] = 1;
            $rows[] = ['label'=>$label, 'hidden'=>!empty($item['hidden']) ? 1 : 0];
        }
        $new_label = trim(sanitize_text_field(wp_unslash($_POST['new_label'] ?? '')));
        if ($new_label !== '') {
            $dedupe = function_exists('mb_strtolower') ? mb_strtolower($new_label) : strtolower($new_label);
            if (!isset($seen[$dedupe])) $rows[] = ['label'=>$new_label, 'hidden'=>0];
        }
        if (!$rows) {
            pp_panel_options_notice('Aspoň jedna položka musí zostať.', false);
            pp_panel_options_redirect($section);
        }
        pp_options_save($section, $rows);
        if ($renamed) pp_panel_options_notice('Položky uložené a názov bol upravený aj v ' . $renamed . ' ponukách.', true);
    }

    if (!get_transient('pp_panel_options_' . get_current_user_id())) {
        pp_panel_options_notice('Položky ponúk boli uložené.', true);
    }
    pp_panel_options_redirect($section);
});

function pp_panel_color_picker($name, $current) {
    echo '<div class="pp-color-presets" role="radiogroup" aria-label="Farba odznaku">';
    foreach (pp_badge_presets() as $key => $preset) {
        echo '<label title="' . esc_attr($preset['label']) . '">';
        echo '<input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($key) . '" ' . checked($current, $key, false) . '>';
        echo '<span style="background:' . esc_attr($preset['bg']) . ';color:' . esc_attr($preset['fg']) . '"></span>';
        echo '</label>';
    }
    echo '</div>';
}

/** Kompletná obrazovka číselníkov v realitnom paneli. */
function panel_property_options() {
    $sections = [
        'types'       => ['Typ ponuky', 'Názov a farba štítku „Na predaj / Na prenájom / Pozemok“.'],
        'statuses'    => ['Stav ponuky', 'Stavy ako rezervované či predané. Pri prenájme môže mať stav vlastný názov.'],
        'stav'        => ['Stav nehnuteľnosti', 'Technický stav: novostavba, rekonštrukcia a podobne.'],
        'vlastnictvo' => ['Vlastníctvo', 'Možnosti vlastníctva zobrazované pri tvorbe ponuky.'],
    ];
    $section = sanitize_key($_GET['section'] ?? 'types');
    if (!isset($sections[$section])) $section = 'types';
    $catalog = in_array($section, ['types','statuses'], true);
    $rows = $catalog ? pp_catalog_rows($section) : pp_options($section);
    $notice_key = 'pp_panel_options_' . get_current_user_id();
    $notice = get_transient($notice_key);
    if ($notice) delete_transient($notice_key);
    ?>
    <div class="pp-options-page">
        <div class="pp-options-head">
            <div><h1>Položky ponúk</h1><p>Upravte rozbaľovacie možnosti a vzhľad štítkov bez zásahu do kódu.</p></div>
            <a class="btn btn-primary" href="?action=add">+ Nová ponuka</a>
        </div>
        <?php if ($notice): ?><div class="pp-options-notice <?php echo !empty($notice['ok']) ? 'ok' : 'bad' ?>"><?php echo esc_html($notice['message']) ?></div><?php endif; ?>
        <nav class="pp-options-tabs">
            <?php foreach ($sections as $key => $info): ?><a href="?action=options&amp;section=<?php echo esc_attr($key) ?>" class="<?php echo $key===$section?'active':'' ?>"><?php echo esc_html($info[0]) ?></a><?php endforeach; ?>
        </nav>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')) ?>" class="pp-options-card">
            <?php wp_nonce_field('pp_panel_options', '_ppoptnonce') ?>
            <input type="hidden" name="action" value="pp_panel_options_save">
            <input type="hidden" name="section" value="<?php echo esc_attr($section) ?>">
            <h2><?php echo esc_html($sections[$section][0]) ?></h2>
            <p class="pp-options-help"><?php echo esc_html($sections[$section][1]) ?> Skrytá položka zostane v starších ponukách, ale pri novej sa už neponúkne.</p>
            <div class="pp-options-list" id="ppOptionsList">
                <?php foreach ($rows as $i => $row):
                    $value = $catalog ? $row['value'] : $row['label'];
                    $used = $catalog ? pp_catalog_usage($section, $value) : pp_option_usage($section, $row['label']); ?>
                <div class="pp-option-row">
                    <span class="pp-option-move" title="Potiahnutím zmeníte poradie">⋮⋮</span>
                    <?php if ($catalog): ?><input type="hidden" name="item[<?php echo $i ?>][value]" value="<?php echo esc_attr($row['value']) ?>"><?php endif; ?>
                    <input type="hidden" name="item[<?php echo $i ?>][original_label]" value="<?php echo esc_attr($row['label']) ?>">
                    <label><small>Názov</small><input type="text" name="item[<?php echo $i ?>][label]" value="<?php echo esc_attr($row['label']) ?>"></label>
                    <?php if ($section === 'statuses'): ?><label><small>Názov pri prenájme</small><input type="text" name="item[<?php echo $i ?>][rental_label]" value="<?php echo esc_attr($row['rental_label'] ?? $row['label']) ?>" placeholder="napr. Prenajaté"></label><?php endif; ?>
                    <?php if ($catalog): ?><div><small>Farba štítku</small><?php pp_panel_color_picker('item['.$i.'][color]', $row['color']) ?></div><?php endif; ?>
                    <?php if ($section === 'types'): ?><label class="pp-option-hide"><input type="checkbox" name="item[<?php echo $i ?>][rental]" value="1" <?php checked(!empty($row['rental'])) ?>> Typ prenájmu</label><?php endif; ?>
                    <label class="pp-option-hide"><input type="checkbox" name="item[<?php echo $i ?>][hidden]" value="1" <?php checked(!empty($row['hidden'])) ?>> Skryť</label>
                    <span class="pp-option-used"><?php echo $used ? '<strong>'.$used.'</strong> '.($used===1?'ponuka':'ponúk') : 'nepoužité' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="pp-option-new">
                <label><small>Pridať novú položku</small><input type="text" name="new_label" placeholder="Názov novej položky"></label>
                <?php if ($section === 'statuses'): ?><label><small>Názov pri prenájme</small><input type="text" name="new_rental_label" placeholder="Voliteľné"></label><?php endif; ?>
                <?php if ($catalog): ?><div><small>Farba štítku</small><?php pp_panel_color_picker('new_color', 'purple') ?></div><?php endif; ?>
                <?php if ($section === 'types'): ?><label class="pp-option-hide"><input type="checkbox" name="new_rental" value="1"> Typ prenájmu</label><?php endif; ?>
            </div>
            <div class="pp-options-actions">
                <button class="btn btn-primary" type="submit">Uložiť zmeny</button>
                <button class="btn btn-ghost" type="submit" name="reset" value="1" onclick="return confirm('Naozaj obnoviť pôvodné možnosti tejto sekcie? Existujúce ponuky sa nezmažú.')">Obnoviť pôvodné</button>
            </div>
        </form>
    </div>
    <script>
    (function(){var list=document.getElementById('ppOptionsList'),drag=null;if(!list)return;
    list.querySelectorAll('.pp-option-row').forEach(function(row){row.draggable=true;row.addEventListener('dragstart',function(){drag=row;row.classList.add('dragging')});row.addEventListener('dragend',function(){row.classList.remove('dragging');drag=null});});
    list.addEventListener('dragover',function(e){if(!drag)return;e.preventDefault();var target=e.target.closest('.pp-option-row');if(!target||target===drag)return;var box=target.getBoundingClientRect();list.insertBefore(drag,e.clientY<box.top+box.height/2?target:target.nextSibling)});
    })();
    </script>
    <?php
}
