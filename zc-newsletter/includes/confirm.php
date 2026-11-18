<?php
defined('ABSPATH') || exit;
// Confirmation + Unsubscribe URL handler
add_action('init', function() {
    $action = sanitize_text_field($_GET['zcn_action'] ?? '');
    $token  = sanitize_text_field($_GET['token']      ?? '');
    if (!$action || !$token) return;

    global $wpdb;
    $table = zcn_table();
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token = %s", $token));

    if (!$row) {
        wp_die('<h2>Neplatný odkaz.</h2><p><a href="' . home_url() . '">← Späť na web</a></p>', 'Chyba', ['response' => 400]);
    }

    if ($action === 'confirm') {
        $already = ($row->status === 'active');

        // Samotné otvorenie odkazu ešte nič nepotvrdzuje. Kontakt sa aktivuje
        // až po vedomom výbere aspoň jednej ponuky a odoslaní formulára.
        if (isset($_POST['zcn_pick']) && wp_verify_nonce($_POST['_zcnpick'] ?? '', 'zcn_pick_' . $token)) {
            $selected = array_values(array_intersect(
                zcn_interest_list($_POST['interest'] ?? []),
                array_keys(zcn_offer_interests())
            ));
            if (!$selected) {
                wp_die(
                    zcn_interest_picker_page($token, $row, $already, 'confirm', 'Vyberte aspoň jeden typ ponuky.'),
                    'Vyberte ponuky'
                );
            }

            $data = ['interest' => zcn_sanitize_interests($selected)];
            if (!$already) {
                $data['status'] = 'active';
                $data['confirmed_at'] = current_time('mysql');
            }
            $updated = $wpdb->update($table, $data, ['token' => $token]);
            if ($updated === false) {
                wp_die(
                    zcn_interest_picker_page($token, $row, $already, 'confirm', 'Výber sa nepodarilo uložiť. Skúste to znova.'),
                    'Chyba'
                );
            }
            if (!$already && function_exists('zcn_send_welcome')) {
                zcn_send_welcome($row->email, $row->name);
            }
            wp_die(zcn_page_response(
                $already ? 'Výber uložený' : 'Vitajte vo VIP!',
                'Budeme vám posielať iba vybrané typy ponúk. Výber môžete kedykoľvek zmeniť odkazom v e-maile.',
                '← Späť na web'
            ), 'Hotovo');
        }

        wp_die(zcn_interest_picker_page($token, $row, $already), 'Vyberte ponuky');
    }

    if ($action === 'prefs') {
        if (isset($_POST['zcn_pick']) && wp_verify_nonce($_POST['_zcnpick'] ?? '', 'zcn_pick_' . $token)) {
            $wpdb->update($table,
                ['interest' => zcn_sanitize_interests($_POST['interest'] ?? []), 'status' => 'active'],
                ['token' => $token]
            );
            wp_die(zcn_page_response(
                'Zmeny uložené',
                'Odteraz vám budeme posielať presne to, čo ste si vybrali.',
                '← Späť na web'
            ), 'Hotovo');
        }
        wp_die(zcn_interest_picker_page($token, $row, true, 'prefs'), 'Moje témy');
    }

    if ($action === 'unsubscribe') {
        // Poštový klient (Gmail, Apple Mail) posiela podľa RFC 8058 rovno POST
        // s týmto telom. Vtedy sa nesmieme nič pýtať – odhlásime hneď.
        $one_click = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
            && (($_POST['List-Unsubscribe'] ?? '') === 'One-Click');

        // Namiesto odchodu si človek môže upraviť témy priamo na medzistránke
        if (!$one_click && isset($_POST['zcn_pick'])
            && wp_verify_nonce($_POST['_zcnpick'] ?? '', 'zcn_pick_' . $token)) {
            $wpdb->update($table,
                ['interest' => zcn_sanitize_interests($_POST['interest'] ?? []), 'status' => 'active'],
                ['token' => $token]
            );
            wp_die(zcn_page_response(
                'Zostávate s nami',
                'Odteraz vám budeme posielať len to, čo ste si vybrali. Odhlásiť sa viete kedykoľvek.',
                '← Späť na web'
            ), 'Hotovo');
        }

        // Prvé, menej výrazné tlačidlo iba otvorí finálny medzikrok.
        $review = isset($_POST['zcn_unsub_review'])
            && wp_verify_nonce($_POST['_zcnunsub'] ?? '', 'zcn_unsub_' . $token);

        // Až druhé vedomé potvrdenie kontakt skutočne odhlási.
        $confirmed = isset($_POST['zcn_unsub_confirm'])
            && wp_verify_nonce($_POST['_zcnunsub'] ?? '', 'zcn_unsub_' . $token);

        if ($row->status === 'unsubscribed') {
            wp_die(zcn_page_response(
                'Už ste odhlásený',
                'Táto adresa je z odberu odhlásená. Žiadne ďalšie e-maily vám neposielame.',
                '← Späť na web'
            ), 'Odhlásenie');
        }

        if (!$one_click && $review) {
            wp_die(zcn_unsubscribe_confirm_page($token), 'Potvrdenie odhlásenia');
        }

        // Kliknutie na odkaz v e-maile najprv ponúkne úpravu tém.
        if (!$one_click && !$confirmed) {
            wp_die(zcn_interest_picker_page($token, $row, true, 'unsub'), 'Odhlásenie');
        }

        $wpdb->update($table,
            ['status' => 'unsubscribed'],
            ['token'  => $token]
        );

        if ($one_click) { status_header(200); exit; } // klient HTML nečaká

        wp_die(zcn_page_response(
            'Odhlásenie úspešné',
            'Boli ste odhlásený z VIP odberu. Ďalšie VIP e-maily vám už nebudeme posielať.',
            '← Späť na web'
        ), 'Odhlásenie');
    }
});

function zcn_page_response($title, $text, $btn_label) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>@font-face{font-family:"DM Sans";font-weight:100 1000;font-display:swap;src:url(' . esc_url(get_stylesheet_directory_uri() . '/assets/fonts/DMSans.woff2') . ') format("woff2")}@font-face{font-family:"Playfair Display";font-weight:400 900;font-display:swap;src:url(' . esc_url(get_stylesheet_directory_uri() . '/assets/fonts/PlayfairDisplay.woff2') . ') format("woff2")}</style>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{background:linear-gradient(135deg,#F5EEDF 0%,#EBDCC0 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:"DM Sans",sans-serif;padding:24px}
    /* 100vh na mobile počíta so skrytou lištou prehliadača, takže stránka
       bola vždy o kúsok vyššia ako obrazovka a dala sa zbytočne scrollovať.
       dvh meria skutočne viditeľnú výšku. */
    @supports(min-height:100dvh){body{min-height:100dvh}}
    .card{background:#fff;border:1px solid #E0D8CE;border-radius:20px;padding:48px 40px;max-width:440px;width:100%;text-align:center;box-shadow:0 16px 56px rgba(40,32,20,.14)}
    h2{font-family:"Playfair Display",serif;font-size:26px;color:#1C1A18;margin-bottom:14px}
    p{font-size:15px;color:#6B6560;line-height:1.75;margin-bottom:28px}
    a{display:inline-block;padding:12px 28px;background:#B8A47A;color:#1C1A18;text-decoration:none;border-radius:8px;font-weight:700;font-size:13px;transition:background .2s}
    a:hover{background:#9A8660}</style>
    </head><body><div class="card">
    <h2>' . esc_html($title) . '</h2>
    <p>' . esc_html($text) . '</p>
    <a href="' . home_url() . '">' . esc_html($btn_label) . '</a>
    </div></body></html>';
}

/** Finálny medzikrok pred úplným odhlásením z VIP. */
function zcn_unsubscribe_confirm_page($token) {
    ob_start(); ?>
    <form method="post" class="final-unsub"
          action="<?php echo esc_url(add_query_arg(['zcn_action' => 'unsubscribe', 'token' => $token], home_url('/'))); ?>">
        <?php wp_nonce_field('zcn_unsub_' . $token, '_zcnunsub'); ?>
        <input type="hidden" name="zcn_unsub_confirm" value="1">
        <button type="submit">Áno, úplne ma odhlásiť z VIP</button>
    </form>
    <?php
    $form = ob_get_clean();
    $html = zcn_page_response(
        'Naozaj sa chcete odhlásiť?',
        'Ak vám chodí priveľa e-mailov, môžete sa vrátiť a ponechať si iba vybrané typy ponúk. Úplným odhlásením prestanete dostávať všetky VIP e-maily.',
        'Nie, zostať vo VIP'
    );
    $css = '<style>
    .final-unsub{margin-top:8px}
    .final-unsub button{display:block;width:100%;padding:8px 6px 16px;border:0;background:none;
        color:#8A8078;font:600 12.5px/1.4 "DM Sans",sans-serif;text-decoration:underline;
        text-underline-offset:3px;cursor:pointer}
    .final-unsub button:hover{color:#b91c1c;background:none}
    </style>';
    $html = str_replace('</head>', $css . '</head>', $html);
    $home_link = '<a href="' . home_url() . '">Nie, zostať vo VIP</a>';
    $back_link = '<a href="' . esc_url(zcn_unsubscribe_url($token)) . '">Nie, zostať vo VIP</a>';
    return str_replace($home_link, $back_link . $form, $html);
}

/**
 * Stránka po potvrdení odberu – výber tém.
 * Zaškrtnúť sa dá viac možností; nič nezaškrtnuté = posielame všetko.
 */
function zcn_interest_picker_page($token, $row, $already = false, $mode = 'confirm', $error = '') {
    $current = zcn_interest_list($row->interest ?? '');
    // Pri prvom potvrdení ide VIP výhradne o typy ponúk. V neskorších
    // preferenciách ponechávame aj staršie vlastné kategórie správcu.
    $offers = zcn_offer_interests();
    $groups = ($mode === 'confirm' && $offers)
        ? ['Ponuky' => $offers]
        : zcn_interest_groups();

    ob_start(); ?>
    <form method="post" style="text-align:left">
        <?php wp_nonce_field('zcn_pick_' . $token, '_zcnpick'); ?>
        <input type="hidden" name="zcn_pick" value="1">
        <?php foreach ($groups as $glabel => $items): ?>
        <div class="grp"><?php echo esc_html($glabel); ?></div>
        <?php foreach ($items as $value => $label): ?>
        <label class="opt">
            <input type="checkbox" name="interest[]" value="<?php echo esc_attr($value); ?>"
                <?php checked(in_array($value, $current, true)); ?>>
            <span><?php echo esc_html($label); ?></span>
        </label>
        <?php endforeach; endforeach; ?>
        <?php if ($error): ?><div class="pick-error" role="alert"><?php echo esc_html($error); ?></div><?php endif; ?>
        <p class="hint"><?php echo $mode === 'confirm'
            ? 'Vyberte aspoň jednu možnosť. VIP sa aktivuje až po odoslaní tohto výberu.'
            : 'Nič nezaškrtnuté = pošleme vám všetko. Vybrať sa dá aj viac možností naraz.'; ?></p>
        <button type="submit" class="btn"><?php
            echo $mode === 'unsub'
                ? 'Uložiť výber a zostať'
                : ($mode === 'confirm' && !$already ? 'Potvrdiť vstup do VIP' : 'Uložiť výber');
        ?></button>
    </form>
    <?php if ($mode === 'prefs' || $mode === 'unsub'): ?>
    <?php /* Odhlásenie je vlastný formulár – formuláre sa nesmú vnárať.
             Cieľ je vždy adresa odhlásenia, aby to fungovalo aj zo stránky „Moje témy". */ ?>
    <form method="post" class="unsub-form"
          action="<?php echo esc_url(add_query_arg(['zcn_action' => 'unsubscribe', 'token' => $token], home_url('/'))); ?>">
        <?php wp_nonce_field('zcn_unsub_' . $token, '_zcnunsub'); ?>
        <input type="hidden" name="zcn_unsub_review" value="1">
        <button type="submit" class="unsub">Odhlásiť sa úplne z VIP</button>
    </form>
    <?php endif; ?>
    <?php
    $form = ob_get_clean();

    if ($mode === 'unsub') {
        $title = 'Škoda, že odchádzate';
        $text  = 'Možno vám len chodí priveľa e-mailov. Vyberte si, čo vám máme posielať – '
               . 'a zostanete prihlásený. Ak chcete odísť úplne, nájdete to pod výberom.';
    } elseif ($mode === 'prefs') {
        $title = 'Moje témy';
        $text  = 'Označte, čo vám máme posielať. Zmeny sa uložia okamžite.';
    } else {
        $title = $already ? 'Upravte svoj VIP výber' : 'Dokončite vstup do VIP';
        $text  = $already
            ? 'Vyberte si typy ponúk, ktoré vás zaujímajú.'
            : 'Označte aspoň jeden typ ponuky. Až potom sa VIP aktivuje.';
    }

    $html = zcn_page_response($title, $text, '← Späť na web');
    // Formulár vložíme nad tlačidlo „Späť na web"
    $extra_css = '<style>
    .card{max-width:520px;text-align:left}
    .card h2,.card > p{text-align:center}
    .grp{font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:#7C5E33;margin:18px 0 8px}
    .opt{display:flex;align-items:center;gap:10px;padding:11px 14px;border:1.5px solid #E0D8CE;border-radius:10px;
         margin-bottom:8px;cursor:pointer;font-size:14.5px;color:#2C2825;background:#fff;transition:border-color .15s,background .15s}
    .opt:hover{border-color:#B8A47A;background:#FBF8F2}
    .opt input{width:18px;height:18px;accent-color:#B8A47A;flex:0 0 auto}
    .hint{font-size:12.5px;color:#7C5E33;line-height:1.6;margin:14px 0 18px;text-align:left}
    .pick-error{padding:11px 13px;margin:14px 0 0;border:1px solid #fecaca;border-radius:8px;
         background:#fef2f2;color:#b91c1c;font-size:13px;font-weight:700;line-height:1.5}
    .btn{width:100%;padding:13px;background:#B8A47A;color:#1C1A18;border:none;border-radius:8px;
         font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;margin-bottom:14px}
    .btn:hover{background:#9A8660}
    .card > a{display:block;text-align:center;background:none;color:#7C5E33;font-weight:600}
    .unsub-form{margin:0}
    .unsub{display:block;width:100%;text-align:center;font-size:12.5px;color:#6B6560;text-decoration:underline;
           padding:4px 0 10px;background:none;border:none;cursor:pointer;font-family:inherit}
    .unsub:hover{color:#dc2626;background:none}
    .card > a:hover{background:none;color:#7C5E33}
    </style>';

    $html = str_replace('</head>', $extra_css . '</head>', $html);
    $html = str_replace('<a href="' . home_url() . '">', $form . '<a href="' . home_url() . '">', $html);
    return $html;
}
