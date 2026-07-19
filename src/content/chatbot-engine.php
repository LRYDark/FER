<?php
/**
 * Moteur du chatbot public — Forbach en Rose
 *
 * Moteur à RÈGLES, 100 % local : aucune donnée n'est envoyée à un service
 * externe. Il reconnaît l'intention d'un message en français (avec tolérance
 * aux fautes courantes, accents absents, variantes t-shirt/tshirt/tee-shirt…)
 * et construit la réponse à partir des réglages du site (table `setting`) et
 * des inscriptions (vérification par e-mail, sans jamais divulguer de données
 * personnelles : uniquement oui/non + compte).
 *
 * Utilisé par public/chatbot-api.php.
 */

/** Normalise un message : minuscules, sans accents, ponctuation → espaces. */
function chatbot_normalize(string $s): string
{
    // Défense : certains proxys/WAF convertissent les POST UTF-8 en Latin-1 —
    // on re-convertit pour ne pas perdre les accents (sinon "où"→"o ").
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }
    $s = mb_strtolower(trim($s), 'UTF-8');
    // Translittération des accents français + ligatures
    $map = [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'î'=>'i','ï'=>'i','í'=>'i',
        'ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
        'ç'=>'c','ñ'=>'n','œ'=>'oe','æ'=>'ae',
    ];
    $s = strtr($s, $map);
    // Variantes t-shirt → forme canonique "tshirt"
    $s = preg_replace('/\bt[\s\-_.]?shirts?\b|\btee[\s\-]?shirts?\b/u', 'tshirt', $s);
    // Apostrophes et ponctuation → espaces
    $s = preg_replace("/[’'´`]/u", ' ', $s);
    $s = preg_replace('/[^a-z0-9@.\s-]/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Détecte l'intention d'un message normalisé.
 * Chaque intention = liste de motifs regex (sur texte normalisé) avec un poids.
 * Retourne [intent, score] — intent = 'fallback' si rien ne matche.
 */
function chatbot_match_intent(string $norm): array
{
    // NB : ordre = priorité en cas d'égalité de score (le premier gagne).
    $intents = [
        // Ai-je droit / vais-je recevoir un t-shirt ? (vérif par e-mail)
        'tshirt_check' => [
            ['/\btshirt\b.*\b(droit|avoir|recevoir|recois|aurai|eligible|elligible|gagne|obtenir|obtiens|beneficie)\b/', 3],
            ['/\b(droit|avoir|recevoir|eligible|beneficier?)\b.*\btshirt\b/', 3],
            ['/\btshirt\b.*\b(pour moi|le mien|mon)\b/', 2],
            ['/\b(taille)\b.*\btshirt\b|\btshirt\b.*\b(taille)\b/', 2],
            ['/\btshirt\b/', 1],
        ],
        // Comment / où / quand récupérer le t-shirt ? (spécifique → prime sur schedule/location)
        'tshirt_retrait' => [
            ['/\b(recuperer?|recuper|retirer?|chercher|obtenir|donne|distribution|remise|retrait)\b.*\btshirt\b/', 6],
            ['/\btshirt\b.*\b(recuperer?|retirer?|chercher|remise|retrait|distribution|ou|quand|comment)\b/', 6],
        ],
        // Suis-je bien inscrit(e) ? (vérif par e-mail)
        'registration_check' => [
            ['/\b(suis|je suis|etre|bien)\b.*\binscrit/', 4],
            ['/\binscription\b.*\b(valide|validee|enregistree?|confirmee?|prise? en compte|bien passe|ok)\b/', 4],
            ['/\b(verifier?|verif|controler?|savoir si)\b.*\b(inscrit|inscription)\b/', 4],
            ['/\bmon inscription\b/', 3],
            ['/\binscrit(e)?\b.*\?/', 2],
            ['/\bja?i (bien )?(ete|etais) inscrit/', 3],
        ],
        // Comment s'inscrire ?
        'registration_howto' => [
            ['/\b(comment|ou|puis[- ]?je|peut[- ]?on|veux|voudrais|souhaite)\b.*\b(inscrire|inscription|participer|men?gager)\b/', 4],
            ['/\b(inscrire|inscription)\b.*\b(comment|ou|faire)\b/', 3],
            ['/\bparticiper\b/', 2],
            ['/\bsinscrire\b|\bm inscrire\b|\binscrire\b/', 2],
        ],
        // Prix / tarif ("combien" seul est ambigu → exige un contexte argent/inscription)
        'price' => [
            ['/\b(prix|tarifs?|couts?|coute|payant|gratuit|montant|frais)\b/', 3],
            ['/\bcombien\b.*\b(coute|payer|euros?|inscription|ca coute)\b/', 4],
        ],
        // Lieu de départ / point de rendez-vous
        'location' => [
            ['/\b(ou|lieu|endroit|adresse|localisation)\b.*\b(depart|course|rdv|rendez|rendezvous|retrouve|passe|deroule|situe)\b/', 4],
            ['/\b(depart|course)\b.*\b(ou|lieu|endroit|adresse)\b/', 4],
            ['/\b(point de |lieu de )?(rendez[\s-]?vous|rdv)\b/', 3],
            ['/\b(comment|ou)\b.*\b(venir|aller|rendre|acceder|garer|parking|stationner)\b/', 3],
            ['/\blieu\b|\badresse\b/', 2],
        ],
        // Date / horaires ("quand a lieu / aura lieu" prime sur le mot "lieu" de location)
        'schedule' => [
            ['/\bquand\b.*\b(a lieu|aura lieu|se deroule|se passe|commence|course|evenement|depart)\b/', 5],
            ['/\b(quand|quelle? (heure|date)|horaires?|a quelle heure)\b/', 4],
            ['/\b(heure|horaires?)\b.*\b(depart|course|debut|commence)\b/', 4],
            ['/\b(date|jour)\b.*\b(course|evenement|depart)\b/', 3],
            ['/\b(commence|debute|demarre)\b/', 2],
            ['/\bhoraires?\b/', 3],
        ],
        // Parcours / distance ("combien de km" prime sur le "combien" du prix)
        'parcours' => [
            ['/\b(combien|quelle)\b.*\b(km|kilometres?|distance|longue?)\b/', 5],
            ['/\b(parcours|trajet|itineraire|circuit|boucle|trace)\b/', 3],
            ['/\bdistance\b|\bkm\b|\bkilometres?\b/', 2],
        ],
        // Parler à un humain / laisser un message
        'contact_human' => [
            ['/\b(contacter?|ecrire|joindre|parler|appeler|telephoner?|humain|quelqu un|un mail|un message|reclamation)\b/', 3],
            ['/\b(mail|email|telephone|tel|numero)\b.*\b(association|organisateurs?|vous)\b/', 3],
            ['/\bcontact\b/', 2],
        ],
        // Newsletter / rester informé
        'newsletter' => [
            ['/\b(newsletters?|rester informee?|tenir au courant|actualites?|nouveautes?|abonner)\b/', 3],
        ],
        // Photos
        'photos' => [
            ['/\b(photos?|albums?|images?|galerie)\b/', 3],
        ],
        // Don / soutien à la cause
        'donation' => [
            ['/\b(dons?|donner|soutenir|soutien|aider|cagnotte|reverse|argent recolte|benefices?)\b/', 3],
            ['/\b(cancer|ligue|depistage)\b/', 2],
        ],
        // Politesse
        'greeting' => [
            ['/^(bonjour|bonsoir|salut|coucou|hello|hey|yo|bjr|slt)\b/', 5],
        ],
        'thanks' => [
            ['/\b(mercii?s?|thanks|super|genial|parfait|top|nickel|cool)\b/', 3],
        ],
        'bye' => [
            ['/\b(au revoir|a bientot|bonne (journee|soiree)|bye|ciao|a plus)\b/', 4],
        ],
    ];

    $best = 'fallback';
    $bestScore = 0;
    foreach ($intents as $intent => $patterns) {
        $score = 0;
        foreach ($patterns as [$re, $w]) {
            if (preg_match($re, $norm)) $score = max($score, $w);
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $intent; }
    }
    // Seuil minimal : un score de 1 seul (ex. juste le mot "tshirt") reste accepté,
    // mais un score nul = incompris.
    return [$bestScore > 0 ? $best : 'fallback', $bestScore];
}

/** Formate la date de course en français ("dimanche 5 octobre 2025 à 9h30"). */
function chatbot_format_date(?string $ts): string
{
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $jours = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
    $mois  = ['', 'janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $out = $jours[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $mois[(int)date('n', $t)] . ' ' . date('Y', $t);
    if (date('H:i', $t) !== '00:00') $out .= ' à ' . (int)date('G', $t) . 'h' . (date('i', $t) !== '00' ? date('i', $t) : '');
    return $out;
}

/** Échappe puis convertit les \n en <br> (les réponses admin sont du texte libre). */
function chatbot_esc_nl(string $s): string
{
    return str_replace("\n", '<br>', htmlspecialchars(trim($s)));
}

/**
 * Construit la réponse à une intention.
 * @param array $set  ligne complète de `setting`
 * @return array {text: html, quick: string[], action: ?string}
 */
function chatbot_answer(string $intent, array $set): array
{
    $quickDefault = ['✅ Mon inscription', '🎽 T-shirt', '📍 Lieu & horaires', '✉️ Nous écrire'];
    $r = ['text' => '', 'quick' => [], 'action' => null];

    switch ($intent) {
        case 'greeting': {
            $extra = '';
            $t = !empty($set['date_course']) ? strtotime($set['date_course']) : false;
            if ($t && $t > time()) {
                $days = (int)ceil(($t - time()) / 86400);
                $extra = ' Plus que <strong>' . $days . ' jour' . ($days > 1 ? 's' : '') . '</strong> avant la course ! 🎉';
            }
            $r['text'] = 'Bonjour ! 👋 Je suis l\'assistant de Forbach en Rose.' . $extra . '<br>Comment puis-je vous aider ?';
            $r['quick'] = $quickDefault;
            break;
        }
        case 'thanks':
            $r['text'] = 'Avec plaisir ! 💗 N\'hésitez pas si vous avez une autre question.';
            $r['quick'] = $quickDefault;
            break;
        case 'bye':
            $r['text'] = 'À bientôt, et merci de soutenir la lutte contre le cancer du sein ! 🎀';
            break;

        case 'registration_check':
            $r['text'] = 'Je peux vérifier cela tout de suite ! 🔎<br>Indiquez-moi l\'adresse e-mail utilisée lors de votre inscription :';
            $r['action'] = 'ask_email_registration';
            break;

        case 'tshirt_check':
            $r['text'] = 'Je vérifie si un t-shirt est prévu pour vous ! 🎽<br>Indiquez-moi l\'adresse e-mail utilisée lors de votre inscription :';
            $r['action'] = 'ask_email_tshirt';
            break;

        case 'tshirt_retrait': {
            $info = trim((string)($set['tshirt_retrait_info'] ?? ''));
            if ($info !== '') {
                $r['text'] = '🎽 <strong>Retrait des t-shirts :</strong><br>' . chatbot_esc_nl($info);
            } else {
                $r['text'] = 'Les modalités de retrait des t-shirts seront communiquées prochainement. 🎽<br>Vous pouvez nous laisser un message pour en savoir plus !';
                $r['quick'] = ['✉️ Nous écrire'];
            }
            $r['quick'] = array_merge($r['quick'], ['🎽 Ai-je droit à un t-shirt ?']);
            break;
        }

        case 'registration_howto': {
            $fee = (int)($set['registration_fee'] ?? 0);
            $txt = '🏃‍♀️ Pour vous inscrire, c\'est très simple : rendez-vous sur <a href="register">la page d\'inscription</a> — cela prend 1 minute !';
            if ($fee > 0) {
                $txt .= '<br>Tarif : <strong>' . $fee . ' €</strong>';
                if (!empty($set['child_pricing_enabled'])) {
                    $txt .= ' (' . (int)$set['child_amount'] . ' € pour les moins de ' . (int)$set['child_age_threshold'] . ' ans)';
                }
                $txt .= ', intégralement reversé à la lutte contre le cancer du sein. 🎀';
            }
            $r['text'] = $txt;
            $r['quick'] = ['📍 Lieu & horaires', '🗺️ Le parcours'];
            break;
        }

        case 'price': {
            $fee = (int)($set['registration_fee'] ?? 0);
            if ($fee > 0) {
                $txt = '💶 L\'inscription coûte <strong>' . $fee . ' €</strong>';
                if (!empty($set['child_pricing_enabled'])) {
                    $txt .= ' (' . (int)$set['child_amount'] . ' € pour les moins de ' . (int)$set['child_age_threshold'] . ' ans)';
                }
                $txt .= '.<br>L\'intégralité est reversée à la lutte contre le cancer du sein. 🎀';
            } else {
                $txt = 'Les informations de tarif seront bientôt disponibles. Vous pouvez nous écrire pour en savoir plus !';
            }
            $r['text'] = $txt;
            $r['quick'] = ['📝 Comment s\'inscrire ?'];
            break;
        }

        case 'location': {
            $addr = trim((string)($set['start_point_address'] ?? ''));
            $rdv  = trim((string)($set['course_rdv'] ?? ''));
            $parts = [];
            if ($addr !== '') $parts[] = '📍 <strong>Départ :</strong> ' . htmlspecialchars($addr);
            if ($rdv !== '')  $parts[] = '🤝 <strong>Point de rendez-vous :</strong><br>' . chatbot_esc_nl($rdv);
            if (!$parts) {
                $r['text'] = 'Le lieu de départ sera annoncé prochainement — restez connecté(e) ! Vous pouvez aussi nous écrire.';
                $r['quick'] = ['✉️ Nous écrire'];
            } else {
                $r['text'] = implode('<br><br>', $parts);
                $r['quick'] = ['🕘 Les horaires', '🗺️ Le parcours'];
            }
            break;
        }

        case 'schedule': {
            $parts = [];
            $dateStr = chatbot_format_date($set['date_course'] ?? null);
            if ($dateStr !== '') $parts[] = '📅 <strong>Date de la course :</strong> ' . $dateStr;
            $hor = trim((string)($set['course_horaires'] ?? ''));
            if ($hor !== '') $parts[] = '🕘 <strong>Horaires :</strong><br>' . chatbot_esc_nl($hor);
            if (!$parts) {
                $r['text'] = 'La date et les horaires seront annoncés prochainement — restez connecté(e) !';
                $r['quick'] = ['✉️ Nous écrire'];
            } else {
                $r['text'] = implode('<br><br>', $parts);
                $r['quick'] = ['📍 Le lieu de départ'];
            }
            break;
        }

        case 'parcours': {
            $km = (int)($set['course_km'] ?? 0);
            $txt = '🗺️ ';
            if ($km > 0) $txt .= 'Le parcours fait <strong>' . $km . ' km</strong>. ';
            $txt .= 'Tous les détails (tracé, plan) sont sur <a href="parcours">la page Parcours</a>.';
            $r['text'] = $txt;
            $r['quick'] = ['📍 Le lieu de départ', '🕘 Les horaires'];
            break;
        }

        case 'newsletter':
            $r['text'] = '📰 Pour rester informé(e) de toutes les actualités, abonnez-vous à notre <a href="newsletter">newsletter</a> !';
            break;

        case 'photos':
            $r['text'] = '📸 Retrouvez toutes les photos des éditions précédentes sur <a href="photos">la page Photos</a> !';
            break;

        case 'donation': {
            $txt = '🎀 Merci de vouloir soutenir la cause ! Les bénéfices de la course sont reversés à la lutte contre le cancer du sein.';
            $link = trim((string)($set['link_cancer'] ?? ''));
            if ($link !== '') $txt .= '<br>Plus d\'infos : <a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">en savoir plus</a>.';
            $txt .= '<br>Pour toute question précise, écrivez-nous !';
            $r['text'] = $txt;
            $r['quick'] = ['✉️ Nous écrire', '📝 Comment s\'inscrire ?'];
            break;
        }

        case 'contact_human': {
            $parts = ['✉️ Bien sûr ! Vous pouvez nous laisser un message juste ici, nous vous répondrons par e-mail.'];
            $coord = [];
            if (!empty($set['mail_email'])) $coord[] = '📧 ' . htmlspecialchars($set['mail_email']);
            if (!empty($set['mail_phone'])) $coord[] = '📞 ' . htmlspecialchars($set['mail_phone']);
            if ($coord) $parts[] = implode('<br>', $coord);
            $r['text'] = implode('<br><br>', $parts);
            $r['action'] = 'contact_form';
            break;
        }

        default: // fallback
            $r['text'] = 'Hmm, je ne suis pas sûr d\'avoir compris. 🤔<br>Voici ce que je sais faire — ou laissez-nous directement un message :';
            $r['quick'] = $quickDefault;
            $r['action'] = 'suggest_contact';
            break;
    }
    return $r;
}

/**
 * Vérifie un e-mail dans les inscriptions : inscrit ? payé ? éligible t-shirt ?
 * Les e-mails sont normalement chiffrés (AES-256-GCM) mais certains imports
 * ont pu les stocker en clair : on compare les DEUX formes.
 * Ne renvoie JAMAIS de données personnelles — uniquement des compteurs.
 */
function chatbot_email_lookup(PDO $pdo, string $email): array
{
    $set = $pdo->query('SELECT qrcode_mail_mode, qrcode_mail_limit FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $limit = (($set['qrcode_mail_mode'] ?? '') === 'first_x' && (int)($set['qrcode_mail_limit'] ?? 0) > 0)
        ? (int)$set['qrcode_mail_limit'] : 0;

    $rows = $pdo->query('SELECT id, email, montant_du, created_at FROM registrations')->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, function ($a, $b) {
        $c = strcmp((string)$a['created_at'], (string)$b['created_at']);
        return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
    });

    $needle = mb_strtolower(trim($email), 'UTF-8');
    $found = 0; $paid = 0; $eligible = 0; $paidRank = 0;
    foreach ($rows as $row) {
        $isPaid = (float)$row['montant_du'] > 0;
        if ($isPaid) $paidRank++;
        $dec = mb_strtolower((string)@decrypt($row['email']), 'UTF-8');
        $raw = mb_strtolower((string)$row['email'], 'UTF-8');
        if ($dec === $needle || $raw === $needle) {
            $found++;
            if ($isPaid) {
                $paid++;
                if ($limit === 0 || $paidRank <= $limit) $eligible++;
            }
        }
    }
    return ['found' => $found, 'paid' => $paid, 'eligible' => $eligible, 'limit' => $limit];
}

/**
 * Message de réponse pour la vérification e-mail.
 * @param string $context 'registration' | 'tshirt'
 */
function chatbot_email_answer(array $lookup, string $context, array $set): array
{
    $r = ['text' => '', 'quick' => [], 'action' => null];
    $n = $lookup['found'];

    if ($n === 0) {
        $r['text'] = 'Je ne trouve pas d\'inscription avec cette adresse. 😔<br>'
            . 'Vérifiez l\'orthographe de l\'e-mail, ou <a href="register">inscrivez-vous en 1 minute</a> !<br>'
            . 'Si vous pensez qu\'il s\'agit d\'une erreur, laissez-nous un message.';
        $r['quick'] = ['🔁 Réessayer avec un autre e-mail', '✉️ Nous écrire'];
        return $r;
    }

    if ($context === 'registration') {
        $label = $n === 1 ? 'votre inscription est bien enregistrée' : "vos $n inscriptions sont bien enregistrées";
        $r['text'] = 'Bonne nouvelle : <strong>' . $label . '</strong> ! ✅<br>Hâte de vous voir le jour J 😊';
        $r['quick'] = ['🎽 Ai-je droit à un t-shirt ?', '📍 Lieu & horaires'];
        return $r;
    }

    // Contexte t-shirt
    if ($lookup['eligible'] > 0) {
        $label = $lookup['eligible'] === 1 ? 'un t-shirt est bien prévu' : $lookup['eligible'] . ' t-shirts sont bien prévus';
        $r['text'] = 'Oui ! 🎽 <strong>' . ucfirst($label) . '</strong> pour cette adresse.';
        $info = trim((string)($set['tshirt_retrait_info'] ?? ''));
        if ($info !== '') {
            $r['text'] .= '<br><br>🎁 <strong>Pour le récupérer :</strong><br>' . chatbot_esc_nl($info);
        } else {
            $r['text'] .= '<br>Les modalités de retrait seront communiquées prochainement.';
        }
    } elseif ($lookup['paid'] > 0) {
        // Payé mais hors quota "premiers X"
        $r['text'] = 'Votre inscription est bien enregistrée ✅, mais les t-shirts étaient réservés aux '
            . (int)$lookup['limit'] . ' premières inscriptions payées, et la vôtre est arrivée après. 😔<br>'
            . 'En cas de doute, laissez-nous un message !';
        $r['quick'] = ['✉️ Nous écrire'];
    } else {
        $r['text'] = 'Votre inscription est enregistrée, mais elle n\'apparaît pas encore comme réglée — '
            . 'le t-shirt est réservé aux inscriptions payées. 💶<br>'
            . 'Si vous avez déjà payé, pas d\'inquiétude : la mise à jour peut prendre un peu de temps. '
            . 'Sinon, écrivez-nous et on regarde ça ensemble !';
        $r['quick'] = ['✉️ Nous écrire'];
    }
    return $r;
}
