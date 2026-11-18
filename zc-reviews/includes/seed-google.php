<?php
/**
 * Jednorazové vloženie recenzií z Google profilu Zdenky (prepísané ručne
 * zo screenshotov, november 2026). Vkladajú sa so zdrojom „google",
 * pred doterajšie recenzie. Rovnakú recenziu (meno + text) nevloží druhýkrát,
 * takže keď už niektorú niekto pridal ručne, ostane len raz.
 */
defined('ABSPATH') || exit;

function zcr_google_seed_rows() {
    // [meno, text, približne pred koľkými dňami]
    return [
        ['Zuzana Madlušková', "S realitnou maklérkou Zdenkou Cibulovou som bola veľmi spokojná. Oceňujem predovšetkým jej rýchly, profesionálny a precízny prístup pri prenájme môjho dvojizbového bytu. Veľmi pozitívne hodnotím najmä starostlivý výber podnájomníkov, ktorému venovala dostatočnú pozornosť. Celý proces prebehol rýchlo, hladko a bez zbytočných komplikácií.\n\nZdenku môžem jednoznačne odporučiť každému, kto hľadá spoľahlivú, zodpovednú a ochotnú realitnú maklérku. Ďakujem za skvelú spoluprácu! 😊", 6],
        ['Jana Horniaková', "Ľudskosť a komplexnosť. Aj taká bola moja nedávna skúsenosť s p. Zdenkou.\n\nVeľmi ma oslovil jej ľudský, pokojný, ale zároveň profesionálny prístup. Mala som pocit, že ma naozaj počúva, vníma to, čo potrebujem a čo je pre mňa dôležité. Hlavne, že vie, čo robí, a že som sa na ňu mohla spoľahnúť.\n\nV realitách je podľa mňa veľmi dôležitá dôvera. To je totálny základ. No a práve preto pani Zdenku veľmi rada odporúčam každému, kto hľadá spoľahlivý, ale hlavne profesionálny prístup s ľudskou tvárou.\nJanka Horniaková, Poviem to za Vás.", 30],
        ['Stefan Jankular', "Pani maklérke Zdenke Cibuľovej chcem vyjadriť veľkú vďaku za jej profesionálny prístup. Od prvého stretnutia až po odovzdanie nehnuteľnosti prebiehalo všetko absolútne profesionálne, transparentne a bez akýchkoľvek komplikácií. Oceňujem najmä ľudský prístup, skvelú komunikáciu a ochotu odpovedať na všetky moje otázky v ktorúkoľvek hodinu. Ak hľadáte makléra, na ktorého sa môžete 100% spoľahnúť a predať či kúpiť nehnuteľnosť bez stresu, jednoznačne odporúčam!", 120],
        ['Lubica Janotkova', "Pani Zdenku som si vybrala po telefonickom rozhovore a to som absolvovala mnoho rozhovorov s inými realitkami. Ona jediná sa mi intuitívne javila ako spoľahlivá a autentická. A veru nesklamala. Mgr. Zdenku môžem len odporučiť, je férová, spoľahlivá a už od začiatku viete, že všetko dobre dopadne.", 300],
        ['Martina Vozarova', "S pracou pani maklerky Zdenky som bola nadmieru spokojna a dakujem za priatelsky pristup a profesionalne sprostredkovanie prenajmu mojho bytu. Ocenujem kvalitne fotky bytu a inzerciu na spravnych miestach. K vyberu najomnika som nemala vyhrady a splnila moje ocakavania. Vsetko prebehlo rychlo vratane pripravy zmluvy a odovzdania bytu. Dakujem za profesionalitu a zodpovedny pristup. Prajem vela spokojnych klientov.", 210],
        ['Marek Ivan', "S prácou pani maklerky Zdenky Cibuľovej som maximálne spokojný,. Hneď od prvej chvíle som mal z nej veľmi dobrý pocit, bola ústretová, komunikatívna, ľudská, trpezlivo zodpovedala všetky moje otázky a postarala sa o všetky potrebné kroky a detaily. Môžem len odporučiť!", 120],
        ['Ing. Michaela Žlnková', "Maximálne profesionálny prístup, spoľahlivá a milá pani maklérka, celý proces predaja nehnuteľnosti prebehol bez problémov, môžem len odporúčať ďalej a ďakujem 😊", 150],
        ['Dusan Skoda', "Ďakujem za profesionálny prístup, veľká spokojnosť so službami, určite odporúčam ďalej.", 1],
        ['Miroslav Slizik', "Veľká spokojnosť s viacročnými profesionálnymi službami realitného makléra. Ľudský prístup pri riešení požiadaviek a potrieb . Spolupráca a služby neskončili kúpou nehnuteľností.... Ďakujem.", 120],
        ['Viera Pirochová', "Ďakujeme za poradenstvo pri kúpe nehnuteľnosti, ochotný a odborný prístup.", 21],
        ['Jakub Stančík', "Veľká spokojnosť so spoluprácou. Pani realitná maklérka bola veľmi profesionálna, spoľahlivá a zároveň ľudská.", 330],
        ['Radovan Uhorskai', "Veľmi dobrá a príjemná skúsenosť, aj keď som počas našej spolupráce zmenil svoje rozhodnutie, bolo akceptované s porozumením.", 30],
        ['Juraj Hajkovsky', "Skvelý človek , výborná komunikácia. Ďakujem", 1],
    ];
}

add_action('init', function () {
    if (get_option('zcr_google_seeded') === '1') return;
    if (!function_exists('zcr_ensure_source_column') || !zcr_ensure_source_column()) return;

    global $wpdb;
    $t    = zcr_table();
    $rows = zcr_google_seed_rows();
    // Pred doterajšie recenzie: záporné poradie pod najnižším existujúcim
    $min  = (int) $wpdb->get_var("SELECT COALESCE(MIN(sort_order),0) FROM {$t}");
    $pos  = $min - count($rows);
    $n    = 0;

    foreach ($rows as [$name, $body, $days]) {
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE author_name = %s AND (body = %s OR source = 'google')", $name, $body));
        $pos++;
        if ($exists) continue;
        $wpdb->insert($t, [
            'author_name' => $name,
            'author_role' => '',
            'body'        => $body,
            'rating'      => 5,
            'avatar_url'  => '',
            'published'   => 1,
            'sort_order'  => $pos,
            'source'      => 'google',
            'created_at'  => gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS),
        ]);
        if ($wpdb->insert_id) $n++;
    }

    update_option('zcr_google_seeded', '1', false);
    if ($n) update_option('zcr_google_seed_count', $n, false);
}, 25);

add_action('admin_notices', function () {
    $n = (int) get_option('zcr_google_seed_count', 0);
    if (!$n || !current_user_can('edit_posts')) return;
    delete_option('zcr_google_seed_count');
    printf('<div class="notice notice-success is-dismissible"><p><strong>Recenzie z Google:</strong> '
        . 'pridali sme %d recenzií z Google profilu (5★). Poradie a texty môžeš upraviť v Recenziách.</p></div>', $n);
});
