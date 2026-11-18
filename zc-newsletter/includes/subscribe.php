<?php
defined('ABSPATH') || exit;
// AJAX subscribe handler
add_action('wp_ajax_zcn_subscribe',        'zcn_handle_subscribe');
add_action('wp_ajax_nopriv_zcn_subscribe', 'zcn_handle_subscribe');

function zcn_handle_subscribe() {
    check_ajax_referer('zcn_nonce', 'nonce');

    // SECURITY: rate limit – max 5 subscribe requests / 10 min per IP
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = 'zcn_sub_' . md5($ip);
    $attempts = (int) get_transient($key);
    if ($attempts >= 5) {
        wp_send_json_error(['message' => 'Príliš veľa pokusov. Skúste neskôr.']);
    }
    set_transient($key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

    $email = strtolower(sanitize_email($_POST['email'] ?? ''));
    $name  = sanitize_text_field($_POST['name']  ?? '');
    $interest = function_exists('zcn_sanitize_interests')
        ? zcn_sanitize_interests($_POST['interest'] ?? '')
        : '';
    $interest_provided = !empty($_POST['interest_provided']);
    $source = function_exists('zcn_merge_sources')
        ? zcn_merge_sources('', $_POST['source'] ?? 'newsletter')
        : sanitize_key($_POST['source'] ?? 'newsletter');

    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Zadajte platnú e-mailovú adresu.']);
    }
    if (function_exists('zcn_ensure_table_ready') && !zcn_ensure_table_ready()) {
        wp_send_json_error(['message' => 'Databáza newslettera momentálne nie je dostupná. Skúste to, prosím, znova.']);
    }

    global $wpdb;
    $table = zcn_table();

    // Check existing
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE email = %s", $email
    ));

    if ($existing) {
        if ($existing->status === 'active') {
            if ($interest_provided || $interest || ($name && !$existing->name) || $source) {
                $data = ['source' => zcn_merge_sources($existing->source ?? '', $source)];
                if ($interest_provided || $interest) $data['interest'] = $interest;
                if ($name && !$existing->name) $data['name'] = $name;
                if ($data) $wpdb->update($table, $data, ['id' => (int) $existing->id]);
            }
            if ($interest_provided) {
                wp_send_json_success(['message' => 'Hotovo – vaše VIP preferencie sme aktualizovali.']);
            }
            wp_send_json_error(['message' => 'Táto adresa už je vo VIP. Preferencie si môžete zmeniť odkazom v ktoromkoľvek e-maile.']);
        }
        // Čakajúci aj kedysi odhlásený sa prihlásia rovno – potvrdzovací
        // krok sme zrušili. Token vždy obnovíme, aby starý odkaz z e-mailu
        // po odhlásení už nikoho nevedel prihlásiť späť.
        $token = zcn_generate_token();
        $data  = [
            'status'       => 'active',
            'token'        => $token,
            'confirmed_at' => current_time('mysql'),
            'source'       => zcn_merge_sources($existing->source ?? '', $source),
        ];
        if ($existing->status === 'unsubscribed') $data['subscribed_at'] = current_time('mysql');
        if ($interest !== '')                     $data['interest']      = $interest;
        if ($name && !$existing->name)            $data['name']          = $name;
        if ($wpdb->update($table, $data, ['id' => (int) $existing->id]) === false) {
            wp_send_json_error(['message' => 'Prihlásenie sa nepodarilo uložiť. Skúste to, prosím, znova.']);
        }
        zcn_send_welcome($email, $name ?: (string) $existing->name);
        wp_send_json_success(['message' => 'Vitajte vo VIP – poslali sme vám uvítací e-mail.']);
    }

    // Nový odberateľ – rovno aktívny, bez potvrdzovacieho e-mailu.
    // Prázdna kategória znamená „všetko"; kto si vo formulári niečo označil,
    // dostane presne to.
    $token = zcn_generate_token();
    $saved = $wpdb->insert($table, [
        'email'        => $email,
        'name'         => $name,
        'interest'     => $interest,
        'status'       => 'active',
        'token'        => $token,
        'source'       => $source,
        'confirmed_at' => current_time('mysql'),
    ]);
    if ($saved === false) {
        wp_send_json_error(['message' => 'Prihlásenie sa nepodarilo uložiť. Skúste to, prosím, znova.']);
    }

    zcn_send_welcome($email, $name);
    wp_send_json_success(['message' => 'Vitajte vo VIP – poslali sme vám uvítací e-mail.']);
}

/**
 * Posledná chyba odoslania v rámci aktuálneho requestu.
 * Hodnota sa používa iba na bezpečnú diagnostiku v realitnom paneli.
 */
function zcn_last_mail_error() {
    return sanitize_text_field((string) ($GLOBALS['zcn_last_mail_error'] ?? ''));
}

function zcn_send_confirmation($email, $name, $token, $source = 'web') {
    $email = strtolower(sanitize_email($email));
    $name  = sanitize_text_field($name);
    $token = sanitize_text_field($token);
    $source = sanitize_key($source);
    $GLOBALS['zcn_last_mail_error'] = '';

    if (!is_email($email) || $token === '') {
        $GLOBALS['zcn_last_mail_error'] = 'Neplatná e-mailová adresa alebo potvrdzovací token.';
        return false;
    }

    $confirm_url = add_query_arg(['zcn_action' => 'confirm', 'token' => $token], home_url('/'));
    $site        = function_exists('zc_agent') ? zc_agent('name', 'Mgr. Zdenka Cibuľová') : 'Mgr. Zdenka Cibuľová';
    $from_email  = function_exists('zc_mail_from') ? zc_mail_from() : (get_theme_mod('zc_email_from', '') ?: get_option('admin_email'));
    $greeting    = $name ? "Dobrý deň {$name}," : 'Dobrý deň,';

    $manual_invitation = in_array($source, ['manual_admin', 'manual_panel', 'manual_batch', 'import'], true);
    if ($manual_invitation) {
        $subject = "{$site} vás pozýva do VIP";
        $intro = "
            <strong>{$site}</strong> vás pozýva do svojho VIP klubu s ponukami nehnuteľností.
            Ak chcete pozvánku prijať, otvorte si výber a označte typy ponúk, ktoré vás zaujímajú.
        ";
        $button_label = 'Prijať VIP pozvánku';
        $footer_note = 'Ak pozvánku nechcete prijať, tento e-mail jednoducho ignorujte.<br>
            Bez výberu ponúk a finálneho potvrdenia vás do VIP nezaradíme.';
    } else {
        $subject = "Dokončite vstup do VIP – {$site}";
        $intro = "
            ďakujeme za váš záujem o <strong>VIP odber ponúk</strong> od {$site}.
            Otvorte si výber a označte typy ponúk, ktoré vás zaujímajú.
        ";
        $button_label = 'Vybrať ponuky a dokončiť vstup';
        $footer_note = 'Ak ste VIP odber vo formulári neoznačili, tento e-mail jednoducho ignorujte.<br>
            Bez výberu ponúk a finálneho potvrdenia vás do VIP nezaradíme.';
    }

    $body    = zcn_email_wrap($subject, "
        <p style='font-size:16px;color:#2C2825;margin:0 0 18px'>{$greeting}</p>
        <p style='color:#555;line-height:1.75;margin:0 0 18px'>
            {$intro}
        </p>
        <p style='color:#555;line-height:1.75;margin:0 0 10px'><strong>Čo získate vo VIP:</strong></p>
        <ul style='color:#555;line-height:1.9;margin:0 0 18px;padding-left:20px'>
            <li>nové ponuky nehnuteľností skôr, než sa dostanú na inzertné portály,</li>
            <li>zníženia cien a novinky pri ponukách, ktoré vás zaujímajú,</li>
            <li>užitočné informácie k ponukám a obhliadkam.</li>
        </ul>
        <p style='color:#555;line-height:1.75;margin:0 0 24px'>
            Vybrať sa dá aj viac možností. Do VIP vás zaradíme až po označení ponúk
            a stlačení finálneho potvrdzovacieho tlačidla na nasledujúcej stránke.
        </p>
        <div style='text-align:center;margin:30px 0'>
            <a href='" . esc_url($confirm_url) . "'
               style='display:inline-block;padding:14px 32px;background:#B8A47A;color:#1C1A18;
                      text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;
                      letter-spacing:.5px;font-family:DM Sans,sans-serif'>
                {$button_label}
            </a>
        </div>
        <p style='font-size:12px;color:#6B6560;text-align:center'>
            {$footer_note}
        </p>
    ");

    $mail_error = null;
    $capture_mail_error = static function ($error) use (&$mail_error) {
        if (is_wp_error($error)) {
            $mail_error = $error->get_error_message();
        }
    };
    add_action('wp_mail_failed', $capture_mail_error);
    $sent = wp_mail($email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$site} <{$from_email}>",
    ]);
    remove_action('wp_mail_failed', $capture_mail_error);

    if (!$sent) {
        $GLOBALS['zcn_last_mail_error'] = $mail_error
            ? sanitize_text_field($mail_error)
            : 'WordPress e-mail odmietol odoslať. Skontrolujte nastavenie WP Mail SMTP.';
    }

    return (bool) $sent;
}

/**
 * Bezpečne odošle nový potvrdzovací e-mail existujúcemu kontaktu.
 * Pri chybe wp_mail() vráti pôvodný stav, token aj dátumy.
 *
 * @return true|WP_Error
 */
function zcn_resend_confirmation($subscriber_id, $source = 'manual_panel') {
    if (function_exists('zcn_ensure_table_ready') && !zcn_ensure_table_ready()) {
        return new WP_Error('zcn_table_missing', 'Databáza newslettera nie je dostupná.');
    }

    global $wpdb;
    $table = zcn_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id=%d",
        absint($subscriber_id)
    ));

    if (!$row || !is_email($row->email)) {
        return new WP_Error('zcn_subscriber_missing', 'Kontakt sa v databáze nenašiel.');
    }
    if ($row->status === 'active') {
        return new WP_Error('zcn_already_active', 'Kontakt je už aktívny.');
    }

    $original = [
        'status'        => $row->status,
        'token'         => $row->token,
        'subscribed_at' => $row->subscribed_at,
        'confirmed_at'  => $row->confirmed_at,
    ];
    $token = zcn_generate_token();
    $source = sanitize_key($source) ?: 'manual_panel';
    $saved = $wpdb->update(
        $table,
        [
            'status'        => 'pending',
            'token'         => $token,
            'subscribed_at' => current_time('mysql'),
            'confirmed_at'  => null,
            'source'        => zcn_merge_sources($row->source ?? '', $source),
        ],
        ['id' => (int) $row->id]
    );
    if ($saved === false) {
        return new WP_Error('zcn_save_failed', 'Nový potvrdzovací token sa nepodarilo uložiť.');
    }

    if (!zcn_send_confirmation($row->email, $row->name, $token, $source)) {
        // Odošlanie neprešlo: kontakt nesmie zostať v inom stave než pred kliknutím.
        $wpdb->update($table, $original, ['id' => (int) $row->id]);
        $detail = zcn_last_mail_error();
        return new WP_Error(
            'zcn_mail_failed',
            'Potvrdzovací e-mail sa nepodarilo odoslať.'
            . ($detail ? ' Dôvod: ' . $detail : '')
        );
    }

    return true;
}

/**
 * Priamy ručný zápis bez potvrdzovacieho ani uvítacieho e-mailu.
 * confirmed_at zostáva NULL, aby databáza netvrdila, že kontakt klikol na súhlas.
 *
 * @return string|WP_Error created|reactivated|updated
 */
function zcn_upsert_manual_active($email, $name = '', $source = 'manual_batch', $interest = '') {
    $email = strtolower(sanitize_email($email));
    $name = sanitize_text_field($name);
    $source = sanitize_key($source) ?: 'manual_batch';
    $interest = function_exists('zcn_sanitize_interests') ? zcn_sanitize_interests($interest) : '';

    if (!is_email($email)) {
        return new WP_Error('zcn_invalid_email', 'Neplatná e-mailová adresa.');
    }
    if (function_exists('zcn_ensure_table_ready') && !zcn_ensure_table_ready()) {
        return new WP_Error('zcn_table_missing', 'Databáza newslettera nie je dostupná.');
    }

    global $wpdb;
    $table = zcn_table();
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s", $email));

    if ($existing) {
        $data = [
            'source' => zcn_merge_sources($existing->source ?? '', $source),
        ];
        if ($name !== '') $data['name'] = $name;
        if ($interest !== '') $data['interest'] = $interest;

        if ($existing->status !== 'active') {
            $data['status'] = 'active';
            $data['token'] = zcn_generate_token();
            $data['subscribed_at'] = current_time('mysql');
            $data['confirmed_at'] = null;
        }

        if ($wpdb->update($table, $data, ['id' => (int) $existing->id]) === false) {
            return new WP_Error('zcn_update_failed', 'Kontakt sa nepodarilo aktualizovať.');
        }
        return $existing->status === 'active' ? 'updated' : 'reactivated';
    }

    $saved = $wpdb->insert($table, [
        'email'        => $email,
        'name'         => $name,
        'status'       => 'active',
        'token'        => zcn_generate_token(),
        'source'       => $source,
        'interest'     => $interest,
        'confirmed_at' => null,
    ]);

    return $saved === false
        ? new WP_Error('zcn_insert_failed', 'Kontakt sa nepodarilo uložiť.')
        : 'created';
}

// Uvítací e-mail po potvrdení / priamom prihlásení na odber
function zcn_send_welcome($email, $name = '') {
    if (!is_email($email)) return;
    global $wpdb;
    $site       = function_exists('zc_agent') ? zc_agent('name', 'Mgr. Zdenka Cibuľová') : 'Mgr. Zdenka Cibuľová';
    $from_email = function_exists('zc_mail_from') ? zc_mail_from() : (get_theme_mod('zc_email_from', '') ?: get_option('admin_email'));
    $greeting   = $name ? "Dobrý deň {$name}," : 'Dobrý deň,';
    $subject    = "Vitajte vo VIP — {$site}";

    // Token odberateľa → odkaz na úpravu tém aj na odhlásenie
    $row = $wpdb->get_row($wpdb->prepare("SELECT token, interest FROM " . zcn_table() . " WHERE email=%s", $email));
    $token = $row ? (string) $row->token : '';
    $interest = $row ? (string) $row->interest : '';
    $unsub = $token ? zcn_unsubscribe_url($token) : home_url('/');
    $prefs = $token
        ? add_query_arg(['zcn_action' => 'prefs', 'token' => $token], home_url('/'))
        : home_url('/vip/');
    $footer = 'Dostávate tento e-mail, pretože ste vstúpili do VIP odberu. · <a href="' . esc_url($unsub) . '" style="color:#9A8660">Odhlásiť sa</a><br>';
    $interest_text = function_exists('zcn_interest_label')
        ? zcn_interest_label($interest, 'Všetky nové ponuky')
        : 'Všetky nové ponuky';

    // Prihlásenie je okamžité. E-mail nič nepotvrdzuje – zhrnie uložený výber
    // a ponúkne bezpečný odkaz na jeho neskoršiu zmenu alebo odhlásenie.
    $body = zcn_email_wrap($subject, "
        <p style='font-size:16px;color:#2C2825;margin:0 0 18px'>{$greeting}</p>
        <p style='color:#555;line-height:1.75;margin:0 0 18px'>
            ďakujem za vstup do VIP. Nové ponuky podľa vášho výberu vám budú
            chodiť priamo do e-mailu.
        </p>
        <p style='color:#555;line-height:1.75;margin:0 0 10px'><strong>Váš výber:</strong> " . esc_html($interest_text) . "</p>
        <ul style='color:#555;line-height:1.9;margin:0 0 20px;padding-left:20px'>
            <li>nové ponuky nehnuteľností skôr, než sa dostanú na inzertné portály,</li>
            <li>zníženia cien a novinky pri ponukách, ktoré vás zaujímajú,</li>
            <li>užitočné informácie k ponukám a obhliadkam.</li>
        </ul>
        <p style='color:#555;line-height:1.75;margin:0 0 22px'>
            Výber môžete kedykoľvek zmeniť. Označiť sa dá aj viac typov ponúk naraz.
        </p>
        <div style='text-align:center;margin:26px 0 20px'>
            <a href='" . esc_url($prefs) . "'
               style='display:inline-block;padding:14px 32px;background:#B8A47A;color:#1C1A18;
                      text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;
                      letter-spacing:.5px;font-family:DM Sans,sans-serif'>
                Zmeniť VIP preferencie
            </a>
        </div>
        <p style='font-size:12.5px;color:#8A8078;text-align:center;line-height:1.7;margin:0 0 4px'>
            Neprihlasovali ste sa vy? Nič sa nedeje –
            <a href='" . esc_url($unsub) . "' style='color:#7C5E33'>odhláste sa jedným klikom</a>
            a viac vám nenapíšeme.
        </p>
        <p style='color:#2C2825;margin:26px 0 0'>S pozdravom,<br><strong>{$site}</strong></p>
    ", $footer);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$site} <{$from_email}>",
    ];
    if ($token) $headers[] = 'List-Unsubscribe: <' . esc_url_raw($unsub) . '>';
    wp_mail($email, $subject, $body, $headers);
}

// Forced subscription - direct active (no confirmation email, used from form opt-in)
function zcn_subscribe_forced($email, $name = '', $source = 'form', $interest = '') {
    if (!is_email($email)) return false;
    if (function_exists('zcn_ensure_table_ready') && !zcn_ensure_table_ready()) return false;
    $email = strtolower(sanitize_email($email));
    $interest = function_exists('zcn_sanitize_interests') ? zcn_sanitize_interests($interest) : '';
    global $wpdb;
    $table = zcn_table();
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s", $email));
    if ($existing) {
        if ($existing->status === 'active') {
            $data = ['source' => zcn_merge_sources($existing->source ?? '', $source)];
            if ($name) $data['name'] = sanitize_text_field($name);
            if ($interest) $data['interest'] = $interest;
            return $wpdb->update($table, $data, ['id' => (int) $existing->id]) !== false;
        }
        $token = zcn_generate_token();
        // Prázdne meno ani prázdna kategória nesmú prepísať to, čo už máme
        $data = ['status'=>'active','token'=>$token,
                 'subscribed_at'=>current_time('mysql'),'confirmed_at'=>current_time('mysql'),
                 'source'=>zcn_merge_sources($existing->source ?? '', $source)];
        if ($name !== '')     $data['name']     = sanitize_text_field($name);
        if ($interest !== '') $data['interest'] = $interest;
        $saved = $wpdb->update($table, $data, ['email'=>$email]);
        if ($saved === false) return false;
    } else {
        $token = zcn_generate_token();
        $saved = $wpdb->insert($table, [
            'email'        => $email,
            'name'         => $name,
            'status'       => 'active',
            'token'        => $token,
            'source'       => $source,
            'interest'     => $interest,
            'confirmed_at' => current_time('mysql'),
        ]);
        if ($saved === false) return false;
    }
    zcn_send_welcome($email, $name);
    return true;
}

// Direct subscription (from other forms) - sends confirmation email
function zcn_subscribe_direct($email, $name = '', $source = 'web', $interest = '') {
    if (!is_email($email)) return false;
    if (function_exists('zcn_ensure_table_ready') && !zcn_ensure_table_ready()) return false;
    $email = strtolower(sanitize_email($email));
    $interest = function_exists('zcn_sanitize_interests') ? zcn_sanitize_interests($interest) : '';
    global $wpdb;
    $table = zcn_table();
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s", $email));
    if ($existing && in_array($existing->status, ['active','pending'])) return false;

    $token = zcn_generate_token();
    if ($existing) {
        $data = ['status'=>'pending','token'=>$token,'subscribed_at'=>current_time('mysql'),
                 'confirmed_at'=>null,'source'=>zcn_merge_sources($existing->source ?? '', $source)];
        if ($name !== '')     $data['name']     = sanitize_text_field($name);
        if ($interest !== '') $data['interest'] = $interest;
        $saved = $wpdb->update($table, $data, ['email'=>$email]);
    } else {
        $saved = $wpdb->insert($table, ['email'=>$email,'name'=>$name,'status'=>'pending','token'=>$token,'source'=>$source,'interest'=>$interest]);
    }
    if ($saved === false) return false;
    return zcn_send_confirmation($email, $name, $token, $source);
}

/* ── Ručné pridávanie kontaktov (wp-admin aj realitný panel) ──────────────
 * Jedna logika pre obe miesta, nech sa nesprávajú rozdielne.
 * Ručné pridanie zámerne NEPOSIELA žiadny e-mail – ani uvítací, ani
 * potvrdzovací. Kontakt vkladá maklérka, ktorá súhlas už má.
 */

/**
 * Načíta riadky vo formáte Meno;Priezvisko;email.
 * Akceptuje aj čiarku alebo tabulátor, hlavičku CSV a samostatný e-mail.
 */
function zcn_parse_contacts($raw, $limit = 500) {
    $lines = preg_split('/\R/u', trim((string) $raw)) ?: [];
    $contacts = [];
    $invalid_rows = [];
    $duplicate_rows = 0;
    $truncated = count($lines) > $limit;

    foreach (array_slice($lines, 0, $limit) as $index => $line) {
        $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $line));
        if ($line === '') continue;

        $delimiter = strpos($line, ';') !== false
            ? ';'
            : (strpos($line, "\t") !== false ? "\t" : (strpos($line, ',') !== false ? ',' : ''));
        $parts = $delimiter ? str_getcsv($line, $delimiter) : [$line];
        $parts = array_map(static function ($value) {
            return trim((string) $value);
        }, $parts);

        if ($index === 0) {
            $header = strtolower(remove_accents(implode(' ', $parts)));
            if (strpos($header, 'email') !== false || strpos($header, 'e-mail') !== false) {
                continue;
            }
        }

        if (count($parts) >= 3) {
            $first_name = $parts[0];
            $last_name = $parts[1];
            $email = $parts[2];
        } elseif (count($parts) === 2) {
            $first_name = $parts[0];
            $last_name = '';
            $email = $parts[1];
        } else {
            $first_name = '';
            $last_name = '';
            $email = $parts[0];
        }

        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) {
            $invalid_rows[] = $index + 1;
            continue;
        }
        if (isset($contacts[$email])) {
            $duplicate_rows++;
            continue;
        }

        $contacts[$email] = [
            'email' => $email,
            'name'  => trim(sanitize_text_field($first_name) . ' ' . sanitize_text_field($last_name)),
        ];
    }

    return [
        'contacts'       => array_values($contacts),
        'invalid_rows'   => $invalid_rows,
        'duplicate_rows' => $duplicate_rows,
        'truncated'      => $truncated,
    ];
}

/**
 * Pridá naraz celú dávku kontaktov.
 * Vracia počty [created, reactivated, updated, failed] + poznámky k vstupu.
 */
function zcn_add_contacts_bulk($raw, $interest = '', $source = 'manual_batch', $mode = 'active') {
    $batch  = zcn_parse_contacts($raw);
    $counts = ['created' => 0, 'reactivated' => 0, 'updated' => 0, 'failed' => 0];
    $mode   = ($mode === 'pending') ? 'pending' : 'active';

    foreach ($batch['contacts'] as $contact) {
        if ($mode === 'pending') {
            // Vedomá voľba – každému odíde potvrdzovací e-mail
            $ok = zcn_subscribe_direct($contact['email'], $contact['name'], $source, $interest);
            $ok ? $counts['created']++ : $counts['failed']++;
            continue;
        }
        $result = zcn_upsert_manual_active($contact['email'], $contact['name'], $source, $interest);
        if (is_wp_error($result))          $counts['failed']++;
        elseif (isset($counts[$result]))   $counts[$result]++;
    }

    return $counts + ['mode' => $mode] + [
        'total'          => count($batch['contacts']),
        'invalid_rows'   => $batch['invalid_rows'],
        'duplicate_rows' => $batch['duplicate_rows'],
        'truncated'      => $batch['truncated'],
    ];
}

/** Zrozumiteľná veta o výsledku dávky – rovnaká vo wp-admine aj v paneli. */
function zcn_bulk_notice($counts) {
    if (empty($counts['total'])) {
        return ['Nenašiel sa žiadny platný kontakt. Použi jeden riadok na osobu: Meno;Priezvisko;email.', false];
    }
    $msg = sprintf(
        'Hotovo: %d nových, %d znovu aktivovaných, %d existujúcich aktualizovaných.',
        $counts['created'], $counts['reactivated'], $counts['updated']
    );
    if (!empty($counts['duplicate_rows'])) $msg .= ' Duplicity v zozname preskočené: ' . (int) $counts['duplicate_rows'] . '.';
    if (!empty($counts['invalid_rows']))   $msg .= ' Neplatné riadky: ' . implode(', ', array_slice($counts['invalid_rows'], 0, 12)) . '.';
    if (!empty($counts['failed']))         $msg .= ' Neuložené pre chybu databázy: ' . (int) $counts['failed'] . '.';
    if (!empty($counts['truncated']))      $msg .= ' Naraz sa spracuje najviac 500 riadkov; zvyšok vlož v ďalšej dávke.';
    $msg .= (($counts['mode'] ?? 'active') === 'pending')
        ? ' Každému odišla VIP pozvánka na potvrdenie.'
        : ' Žiadne e-maily sa neposielali.';

    $ok = ($counts['created'] + $counts['reactivated'] + $counts['updated']) > 0 && empty($counts['failed']);
    return [$msg, $ok];
}

/**
 * Pridá jeden kontakt. $mode: 'active' = rovno aktívny bez e-mailu,
 * 'pending' = pošle sa potvrdzovací e-mail (vedomá voľba používateľa).
 * Vracia [hláška, úspech].
 */
function zcn_add_contact($email, $name = '', $interest = '', $mode = 'active', $source = 'manual_panel') {
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) return ['Zadaj platnú e-mailovú adresu.', false];

    if ($mode === 'pending') {
        $ok = zcn_subscribe_direct($email, $name, $source, $interest);
        if ($ok) return ['Kontakt bol pridaný a dostal VIP pozvánku na potvrdenie.', true];
        $err = function_exists('zcn_last_mail_error') ? zcn_last_mail_error() : '';
        return [$err
            ? 'Kontakt bol uložený, ale e-mail sa nepodarilo odoslať. Dôvod: ' . $err
            : 'Kontakt už je aktívny alebo čaká na potvrdenie.', false];
    }

    $result = zcn_upsert_manual_active($email, $name, $source, $interest);
    if (is_wp_error($result)) return [$result->get_error_message(), false];

    $labels = [
        'created'     => 'Kontakt bol pridaný medzi aktívnych odberateľov.',
        'reactivated' => 'Kontakt bol znovu aktivovaný.',
        'updated'     => 'Kontakt už v databáze bol – údaje sme aktualizovali.',
    ];
    return [($labels[$result] ?? 'Kontakt uložený.') . ' Žiadny e-mail sa neposielal.', true];
}

/* ── Doplnenie kategórie hneď po odoslaní formulára ───────────────────────
 * Vo formulári sa už na typ nehnuteľnosti nepýtame – nezaťažuje to človeka,
 * ktorý chce len napísať správu. Keď si zaškrtne newsletter, opýtame sa ho
 * až potom, v malom okne. Aby nikto nemohol prepísať kategóriu cudziemu
 * kontaktu, dostane jednorazový token platný 30 minút.
 */

/** Vytvorí jednorazový token na doplnenie kategórie. */
function zcn_interest_token($email) {
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) return '';
    $token = wp_generate_password(24, false);
    set_transient('zcn_int_' . $token, $email, 30 * MINUTE_IN_SECONDS);
    return $token;
}

add_action('wp_ajax_zcn_set_interest',        'zcn_handle_set_interest');
add_action('wp_ajax_nopriv_zcn_set_interest', 'zcn_handle_set_interest');

function zcn_handle_set_interest() {
    check_ajax_referer('zcn_nonce', 'nonce');

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $email = $token ? get_transient('zcn_int_' . $token) : '';
    if (!$email || !is_email($email)) {
        wp_send_json_error(['message' => 'Platnosť voľby vypršala. Kategóriu ti nastavíme na požiadanie.']);
    }

    $interest = zcn_sanitize_interests($_POST['interest'] ?? '');
    if (function_exists('zcn_ensure_table_ready')) zcn_ensure_table_ready();

    global $wpdb;
    $updated = $wpdb->update(zcn_table(), ['interest' => $interest], ['email' => $email]);
    // Token zámerne nemažeme – v okne na stránke sa dá výber upraviť viackrát
    // alebo sa hneď potom odhlásiť. Platnosť aj tak vyprší po 30 minútach.

    if ($updated === false) {
        wp_send_json_error(['message' => 'Voľbu sa nepodarilo uložiť.']);
    }
    wp_send_json_success([
        'message'  => zcn_wants_offers($interest)
            ? 'Ďakujeme, nastavené. Nové ponuky vám budú chodiť na e-mail.'
            : 'Ďakujeme. Budeme vám posielať len novinky a ebook, žiadne ponuky.',
        'interest' => $interest,
    ]);
}

/** Možnosti pre okno s výberom – zoskupené, pre JavaScript. */
function zcn_interest_choices() {
    $out = [];
    foreach (zcn_interest_groups() as $glabel => $items) {
        $opts = [];
        foreach ($items as $value => $label) $opts[] = ['value' => $value, 'label' => $label];
        if ($opts) $out[] = ['group' => $glabel, 'items' => $opts];
    }
    return $out;
}

/* ── Úprava tém pre existujúceho odberateľa ──────────────────────────────
 * Na stránke Newsletter si človek vypýta odkaz, ktorý mu pošleme e-mailom.
 * Nikde tak neprezradíme, či je daná adresa v databáze, a nikto cudzí sa
 * k nastaveniam nedostane.
 */
add_action('wp_ajax_zcn_prefs_link',        'zcn_handle_prefs_link');
add_action('wp_ajax_nopriv_zcn_prefs_link', 'zcn_handle_prefs_link');

function zcn_handle_prefs_link() {
    check_ajax_referer('zcn_nonce', 'nonce');

    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = 'zcn_prefs_' . md5($ip);
    if ((int) get_transient($key) >= 5) {
        wp_send_json_error(['message' => 'Príliš veľa pokusov. Skúste to o chvíľu.']);
    }
    set_transient($key, (int) get_transient($key) + 1, 10 * MINUTE_IN_SECONDS);

    $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
    if (!is_email($email)) wp_send_json_error(['message' => 'Zadajte platnú e-mailovú adresu.']);

    // Rovnaká odpoveď bez ohľadu na to, či adresu poznáme
    $answer = 'Ak je táto adresa v našej databáze, poslali sme na ňu odkaz na úpravu tém.';

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . zcn_table() . " WHERE email = %s", $email
    ));
    if (!$row) wp_send_json_success(['message' => $answer]);

    $url  = add_query_arg(['zcn_action' => 'prefs', 'token' => $row->token], home_url('/'));
    $site = function_exists('zc_agent') ? zc_agent('name', 'Mgr. Zdenka Cibuľová') : 'Mgr. Zdenka Cibuľová';
    $from = function_exists('zc_mail_from') ? zc_mail_from() : get_option('admin_email');

    $subject = "Úprava odoberaných tém – {$site}";
    $body    = zcn_email_wrap($subject, "
        <p style='font-size:16px;color:#2C2825;margin:0 0 18px'>Dobrý deň,</p>
        <p style='color:#555;line-height:1.75;margin:0 0 24px'>
            požiadali ste o úpravu tém, ktoré vám posielame. Kliknutím nižšie si
            vyberiete, čo vás zaujíma – alebo sa jedným klikom odhlásite.
        </p>
        <div style='text-align:center;margin:30px 0'>
            <a href='" . esc_url($url) . "'
               style='display:inline-block;padding:14px 32px;background:#B8A47A;color:#1C1A18;
                      text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;
                      letter-spacing:.5px;font-family:DM Sans,sans-serif'>
                Upraviť moje témy
            </a>
        </div>
        <p style='font-size:12px;color:#6B6560;text-align:center'>
            Ak ste o to nežiadali, e-mail pokojne ignorujte – nič sa nezmení.
        </p>
    ");

    wp_mail($email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$site} <{$from}>",
    ]);

    wp_send_json_success(['message' => $answer]);
}

/**
 * Otvorenie úpravy tém priamo na stránke (bez e-mailu).
 * Vráti krátkodobý token, ktorým sa potom uloží výber cez zcn_set_interest.
 */
add_action('wp_ajax_zcn_prefs_open',        'zcn_handle_prefs_open');
add_action('wp_ajax_nopriv_zcn_prefs_open', 'zcn_handle_prefs_open');

function zcn_handle_prefs_open() {
    check_ajax_referer('zcn_nonce', 'nonce');

    // Bez obmedzenia by sa dal zoznam adries skúšať dokola
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = 'zcn_open_' . md5($ip);
    if ((int) get_transient($key) >= 12) {
        wp_send_json_error(['message' => 'Príliš veľa pokusov. Skúste to o chvíľu.']);
    }
    set_transient($key, (int) get_transient($key) + 1, 10 * MINUTE_IN_SECONDS);

    $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
    if (!is_email($email)) wp_send_json_error(['message' => 'Zadajte platnú e-mailovú adresu.']);

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . zcn_table() . " WHERE email = %s", $email
    ));
    if (!$row) {
        wp_send_json_error(['message' => 'Túto adresu v odbere nemáme. Prihláste sa formulárom vyššie.']);
    }
    if ($row->status === 'unsubscribed') {
        wp_send_json_error(['message' => 'Táto adresa je odhlásená. Prihláste sa znova formulárom vyššie.']);
    }

    wp_send_json_success([
        'token'     => zcn_interest_token($email),
        'interests' => zcn_interest_list($row->interest ?? ''),
        'name'      => (string) $row->name,
    ]);
}

/** Odhlásenie z okna úpravy tém – rovnaký jednorazový token. */
add_action('wp_ajax_zcn_prefs_unsub',        'zcn_handle_prefs_unsub');
add_action('wp_ajax_nopriv_zcn_prefs_unsub', 'zcn_handle_prefs_unsub');

function zcn_handle_prefs_unsub() {
    check_ajax_referer('zcn_nonce', 'nonce');

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $email = $token ? get_transient('zcn_int_' . $token) : '';
    if (!$email || !is_email($email)) {
        wp_send_json_error(['message' => 'Platnosť voľby vypršala. Skúste to, prosím, znova.']);
    }

    global $wpdb;
    $wpdb->update(zcn_table(), ['status' => 'unsubscribed'], ['email' => $email]);
    delete_transient('zcn_int_' . $token);

    wp_send_json_success(['message' => 'Odhlásili sme vás. Už vám nebudeme nič posielať.', 'closed' => true]);
}
