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
 * Deux étages, dans cet ordre (voir public/chatbot-api.php) :
 *   1. la couche « politesse & petite conversation » (chatbot_social_*) :
 *      salutations, « ça va ? », merci, au revoir, « t'es un robot ? »… sont
 *      reconnus et RETIRÉS du message — Rosie y répond comme une personne ;
 *   2. le moteur d'intentions (chatbot_match_intent) ne voit que ce qui
 *      reste : la vraie question. « salut, je suis inscrite ? » répond donc à
 *      la question, précédée d'un « Salut ! ».
 *
 * Utilisé par public/chatbot-api.php.
 */

/**
 * Normalise un message : minuscules, sans accents, ponctuation → espaces.
 * En mode $keepPunct (couche sociale), la ponctuation n'est pas effacée mais
 * réduite à deux jetons : « ? » (question) et « , » (toute autre frontière de
 * proposition : virgule, point, « ! », parenthèse…). Le reste est identique.
 */
function chatbot_normalize(string $s, bool $keepPunct = false): string
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
    if ($keepPunct) {
        $s = preg_replace('/[^a-z0-9@,;:!?.…()«»"\s-]/u', ' ', $s);
        // Une suite de ponctuation = UN jeton : « ? » si elle contient un
        // point d'interrogation (« ?! », « ??? »), « , » sinon.
        $s = preg_replace_callback('/(?:\s*[,;:!?.…()«»"]\s*)+/u', function ($m) {
            return strpos($m[0], '?') !== false ? ' ? ' : ' , ';
        }, $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
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
        /* ── Espace coureur & application (lot 6) ────────────────────────────
         * Placées EN TÊTE : « je veux voir mon inscription » doit mener à
         * l'espace coureur, pas à l'intention générique « inscription ». Les
         * poids sont élevés (4-5) pour la même raison — ces demandes sont
         * précises, et une réponse générique à une demande précise donne
         * l'impression de ne pas avoir été écouté. */
        'espace_coureur' => [
            ['/\bespace\b.*\b(coureur|personnel|perso|membre)\b/', 5],
            ['/\b(mon|mes)\b.*\b(espace|compte)\b/', 4],
            ['/\b(me connecter|connexion|se connecter|identifier)\b/', 4],
            ['/\b(voir|consulter|retrouver|acceder|revoir)\b.*\b(mon|ma|mes)\b.*\b(inscription|dossard|qr|billet)\b/', 4],
            ['/\bmot de passe\b.*\b(oubli|perdu)\b/', 3],
        ],
        'application' => [
            ['/\b(application|appli|app)\b.*\b(mobile|telephone|portable|smartphone)\b/', 5],
            ['/\b(telecharger|installer|download)\b.*\b(application|appli|app)\b/', 5],
            ['/\b(application|appli)\b.*\b(existe|dispo|disponible|quand)\b/', 4],
            ['/\b(android|iphone|ios|play store|app store)\b/', 4],
            ['/\b(application|appli)\b/', 2],
        ],
        // Corriger ses informations. « je me suis trompé » sans complément est
        // volontairement faible (1) : trop vague pour trancher tout seul.
        'corriger_infos' => [
            ['/\b(changer|modifier|corriger|rectifier|mettre a jour)\b.*\b(mail|email|adresse|nom|prenom|age|sexe|information)\b/', 5],
            ['/\b(mail|email|adresse|nom|prenom)\b.*\b(faux|fausse|erreur|incorrect|change)\b/', 4],
            ['/\b(erreur|faute)\b.*\b(nom|prenom|mail|email|age)\b/', 4],
            ['/\b(je me suis trompe|jai fait une erreur)\b/', 1],
        ],
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
        // Mail de confirmation / QR code non reçu → renvoi par e-mail (vérif préalable)
        'qrcode_resend' => [
            ['/\b(pas|jamais|non|rien)\b.*\brecu\b.*\b(mail|email|confirmation|qr|billet|code)\b/', 7],
            ['/\b(mail|email|confirmation|qr|billet|code)\b.*\b(pas|jamais|non|rien)\b.*\brecu/', 7],
            // « oublié » et « egare » ajoutés au lot 6 : ce sont des mots que les
            // gens emploient spontanément, et ils manquaient à la liste.
            ['/\b(renvoyer?|renvoi|renvoie|retrouver|perdu|perdue|oublie|oubliee|egare|efface|supprime)\b.*\b(mail|email|confirmation|qr|billet|code)\b/', 6],
            ['/\b(qr ?code|billet)\b.*\b(perdu|perdue|oublie|oubliee|egare|introuvable)\b/', 6],
            ['/\bqr ?codes?\b/', 4],
            ['/\b(mail|email)\b.*\bconfirmation\b|\bconfirmation\b.*\b(mail|email)\b/', 4],
            ['/\bbillet\b/', 2],
        ],
        // Problème / erreur pendant l'inscription en ligne (prime sur les autres
        // intentions "inscription" quand un mot de souci est présent)
        'registration_problem' => [
            // mot de souci … puis mot d'inscription/paiement (et inversement)
            ['/\b(probleme|soucis?|erreurs?|bugs?|beug|impossible|bloque|bloquee?|plante|echec|echoue|marche pas|fonctionne pas|ne marche|ne fonctionne|passe pas|refusee?|rejetee?)\b.*\b(inscri|inscription|paiement|payer|formulaire)/', 7],
            ['/\b(inscri\w*|inscription|paiement|payer|formulaire)\b.*\b(probleme|soucis?|erreurs?|bugs?|beug|impossible|bloque|plante|marche pas|fonctionne pas|ne marche|ne fonctionne|passe pas|refusee?|rejetee?|echoue)\b/', 7],
            // « je n'arrive / peux / réussis / parviens pas à … m'inscrire »
            ['/\b(arrive|arrives|peux|peut|reussis?|parviens?) pas\b.*\binscri/', 7],
            ['/\binscri\w*\b.*\b(arrive|peux|peut|reussis?|parviens?) pas\b/', 7],
            // ordre inversé : « je n'ai pas réussi / pas pu … m'inscrire »
            ['/\bpas (reussi|pu|arrive) a?\b.*\binscri/', 7],
        ],
        // Problème technique sur le site (photos, pages, vidéos, liens…) —
        // prime sur l'intention « photos » quand un mot de souci est présent
        'site_problem' => [
            ['/\b(probleme|soucis?|erreurs?|bugs?|beug|impossible|bloque|plante|marche pas|fonctionne pas|ne marche|ne fonctionne|affiche pas|charge pas|ouvre pas|lance pas|demarre pas|inaccessible|indisponible|rame|hors ligne)\b.*\b(photos?|albums?|images?|galerie|pages?|site|videos?|liens?|acces)\b/', 5],
            ['/\b(photos?|albums?|images?|galerie|pages?|site|videos?|liens?)\b.*\b(probleme|soucis?|erreurs?|bugs?|beug|impossible|bloque|plante|marche pas|fonctionne pas|ne marche|ne fonctionne|affiche pas|charge pas|ouvre pas|lance pas|demarre pas|inaccessible|indisponible|rame|hors ligne)\b/', 5],
            // Négation (« je n'arrive pas à… ») + média/page — verbes en radical
            // (accède/accéder/accédez, ouvre/ouvrir…) et fautes tolérées
            ['/\b(arrive|peux|peut|reussis?) pas\b.*\b(photos?|albums?|images?|galerie|pages?|site|videos?|acces)\b/', 6],
        ],
        // Modifier / corriger / annuler son inscription (prime sur la vérif
        // « mon inscription » quand un mot d'action est présent)
        'registration_modify' => [
            ['/\b(modifier|changer|corriger|annuler|desinscrire|rembours\w*)\b.*\binscri/', 5],
            ['/\binscri\w*\b.*\b(modifier|changer|corriger|annuler|desinscrire|rembours\w*)\b/', 5],
            ['/\b(trompee?|faute de frappe|erreur de saisie)\b.*\binscri|\binscri\w*\b.*\btrompee?\b/', 5],
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
        // Modes de paiement (carte, espèces, chèque, en ligne, sur place…)
        'payment_method' => [
            ['/\b(payer|paiement|regler)\b.*\b(carte|cb|especes|liquide|cheques?|paypal|virement|en ligne|sur place)\b/', 6],
            ['/\b(carte|cb|especes|liquide|cheques?|paypal|virement)\b.*\b(payer|paiement|accepte)/', 6],
            ['/\baccepte\w*\b.*\b(carte|cb|especes|liquide|cheques?|paypal|virement)\b/', 6],
            ['/\bcomment (payer|regler)\b/', 5],
            ['/\bmoyens? de paiement\b|\bmodes? de paiement\b/', 6],
        ],
        // Date limite / inscriptions encore ouvertes ?
        'deadline' => [
            ['/\b(jusqu a quand|date limite|avant quand|avant quelle date)\b.*\binscri|\binscri\w*\b.*\b(jusqu a quand|date limite|encore possible|toujours possible|encore ouvertes?|toujours ouvertes?|encore temps)\b/', 6],
            ['/\b(jusqu a quand|date limite|dernier (jour|moment|delai))\b/', 4],
            ['/\b(encore|toujours) (possible|temps) de\b.*\binscri/', 6],
        ],
        // Inscription de groupe / entreprise / équipe
        'group_registration' => [
            ['/\b(groupes?|equipes?|entreprises?|associations?|collegues|amis|famille|plusieurs)\b.*\binscri/', 6],
            ['/\binscri\w*\b.*\b(groupes?|equipes?|entreprises?|associations?|collegues|plusieurs|a plusieurs)\b/', 6],
        ],
        // Dossard / billet : le QR code fait office de billet
        'dossard' => [
            ['/\bdossards?\b/', 5],
            ['/\bbillets?\b.*\b(ou|recuperer|retirer|imprimer|montrer)\b|\b(imprimer|montrer|presenter)\b.*\b(billets?|qr ?codes?)\b/', 5],
        ],
        // Chrono / classement / résultats
        'ranking' => [
            ['/\b(classements?|chrono|chronometr\w*|meilleur temps|record)\b/', 4],
            ['/\bresultats?\b.*\b(course|marche|classement)\b|\b(course|marche)\b.*\bresultats?\b/', 4],
            // Ajouts du lot 6 : la formulation PERSONNELLE (« mon temps »,
            // « mes résultats ») était absente. C'est pourtant celle qu'emploie
            // un participant — « le classement » est le vocabulaire de
            // l'organisateur, pas le sien.
            ['/\b(mon|ma|mes)\b.*\b(temps|chrono|resultats?|performance|classement)\b/', 5],
            ['/\bou (voir|trouver|consulter)\b.*\b(temps|resultats?|classement)\b/', 5],
            // Les DEUX sens de lecture : « suivi de ma course » comme « ma course
            // sera-t-elle suivie ». Et « suivie » au féminin — un participe passé
            // accordé ne matche pas \bsuivi\b, ce qui laissait la phrase sans réponse.
            ['/\b(suivi|suivie|suivre|tracking|gps)\b.*\b(course|parcours|marche|trajet)\b/', 4],
            ['/\b(course|parcours|marche|trajet)\b.*\b(suivi|suivie|gps|tracking|enregistree?)\b/', 4],
        ],
        // Tenue / dress code rose (attention : ne jamais matcher « Forbach en Rose »)
        'dresscode' => [
            ['/\b(habille|habiller|habilles|vetements?|tenue|dress ?code|deguise\w*)\b/', 4],
            ['/\b(venir|vetue?|porter|mettre) (en|du) rose\b/', 5],
            ['/\brose obligatoire\b/', 5],
        ],
        // Courir ou marcher ? (allure libre)
        'run_or_walk' => [
            ['/\b(courir|jogging|footing|trottiner)\b/', 4],
            ['/\ballure\b|\brythme\b/', 3],
        ],
        // Céder / transférer sa place
        'transfer' => [
            ['/\b(ceder|donner|transferer|revendre|vendre|laisser)\b.*\bplace\b/', 6],
            ['/\btransferts?\b.*\b(place|inscription|dossard)\b/', 6],
            ['/\bceder\b.*\binscription/', 6],
            ['/\b(peux|peut|pourrai) plus venir\b|\bne viendrai (pas|plus)\b|\bempeche\w*\b.*\bvenir\b/', 5],
            // Ajouts du lot 6 : depuis que le transfert est en libre-service,
            // c'est « je ne peux plus courir » qui ouvre la conversation, bien
            // plus souvent que le mot « transfert » lui-même.
            ['/\b(transferer|transfert|ceder|donner|passer)\b.*\b(inscription|dossard|billet)\b/', 6],
            ['/\b(ne (peux|pourrai))\b.*\b(plus)?\b.*\b(courir|participer)\b/', 5],
            ['/\b(blesse|blessee|indisponible)\b.*\b(courir|venir|participer)\b/', 4],
        ],
        // Accompagner / regarder sans participer
        'spectator' => [
            ['/\b(accompagnants?|spectateurs?|encourager|assister a)\b/', 4],
            ['/\bvenir (voir|regarder)\b/', 4],
            ['/\bsans (participer|m inscrire|inscription|etre inscrite?)\b/', 6],
        ],
        // Données personnelles / RGPD
        'privacy' => [
            ['/\bdonnees (personnelles|perso)\b|\bmes donnees\b|\brgpd\b|\bconfidentialite\b|\bvie privee\b/', 5],
            ['/\b(supprimer|effacer)\b.*\b(donnees|compte|informations)\b/', 5],
        ],
        // Sécurité / secours sur le parcours
        'safety' => [
            ['/\b(secours|secouristes?|premiers soins|ambulance|malaise|blessures?|urgence)\b/', 4],
            ['/\bsecurite\b.*\b(parcours|course|marche|evenement|jour j)\b|\b(parcours|course|marche|evenement)\b.*\bsecurite\b/', 4],
        ],
        // Objets perdus / trouvés
        'lost_found' => [
            ['/\b(perdu|egare|oublie)\b.*\b(objets?|sacs?|veste|manteau|telephone|portable|cles?|clefs?|affaires|doudou|lunettes|gourde|bijou\w*)\b/', 5],
            ['/\bobjets? trouves?\b/', 5],
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
            ['/\b(la|quelle) date\b/', 3],
            ['/\b(heure|horaires?)\b.*\b(depart|course|debut|commence)\b/', 4],
            ['/\b(date|jour)\b.*\b(course|evenement|depart)\b/', 3],
            ['/\b(commence|debute|demarre)\b/', 2],
            ['/\bhoraires?\b/', 3],
        ],
        // Parcours / distance ("combien de km" prime sur le "combien" du prix)
        'parcours' => [
            ['/\b(combien|quelle)\b.*\b(km|kilometres?|distance|longue?)\b/', 5],
            // « la course/marche fait combien ? » (et inversé)
            ['/\b(course|marche)\b.*\b(fait|mesure)\b.*\bcombien\b|\bcombien\b.*\b(fait|mesure)\b.*\b(course|marche)\b/', 5],
            // « la carte / le plan de la course » — carte SEULEMENT avec un mot du
            // domaine (sinon « payer par carte » partirait ici)
            ['/\bcarte\b.*\b(parcours|course|marche|trajet)\b|\b(parcours|course|marche|trajet)\b.*\bcarte\b/', 4],
            // « par où passe la course/marche ? » (le tracé, pas le lieu de départ)
            ['/\bpasse par\b|\bpar ou\b.*\bpasse\b/', 5],
            ['/\b(parcours|trajet|itineraire|circuit|boucle|trace|plan|chemin|denivele|kilometrage)\b/', 3],
            // « combien de temps ça dure ? » → allure libre, réponse parcours
            ['/\bcombien de temps\b|\bca dure\b|\bduree\b/', 4],
            ['/\bdistance\b|\bkm\b|\bkilometres?\b/', 2],
        ],
        // Parler à un humain / laisser un message — « quelqu'un » seul est trop
        // large (« quelqu'un peut me rembourser » ≠ contact) : contexte exigé.
        'contact_human' => [
            ['/\b(contacter?|ecrire|joindre|parler|appeler|telephoner?|humain|un mail|un message|reclamation)\b/', 3],
            ['/\bparler a quelqu un\b|\bquelqu un\b.*\b(joindre|contacter|repond)/', 4],
            ['/\b(mail|email|telephone|tel|numero)\b.*\b(association|organisateurs?|vous)\b/', 3],
            ['/\bcontact\b/', 2],
        ],
        // Newsletter / rester informé
        'newsletter' => [
            ['/\b(newsletters?|rester informee?|tenir au courant|actualites?|nouveautes?|abonner)\b/', 3],
        ],
        // Renvoi vers la page FAQ (chip « Voir la FAQ » du menu)
        'faq_page' => [
            ['/\bfaq\b|\bquestions? frequentes?\b|\bfoire aux questions\b/', 5],
        ],
        // Réseaux sociaux
        'social' => [
            ['/\b(facebook|instagram|insta|tiktok|twitter|youtube|reseaux sociaux)\b/', 4],
            ['/\b(nous|vous) suivre\b/', 3],
        ],
        // Photos
        'photos' => [
            ['/\b(photos?|albums?|images?|galerie)\b/', 3],
        ],
        // Partenaires / sponsors (voir la liste OU devenir partenaire)
        'partners' => [
            ['/\b(voir|liste|decouvrir|qui sont|devenir|rejoindre)\b.*\b(partenaires?|sponsors?)\b/', 4],
            ['/\bpartenaires?\b|\bsponsors?\b|\bsponsoring\b/', 2],
        ],
        // Don / soutien à la cause — « donner » et « aider » seuls sont trop
        // larges (coup de main → bénévole via la FAQ) : contexte exigé.
        'donation' => [
            ['/\b(dons?|cagnotte|reverse|argent recolte|benefices?)\b/', 3],
            ['/\b(soutenir|soutien)\b/', 3],
            ['/\bfaire un don\b/', 7], // prime sur « sans participer » (spectateurs)
            ['/\bdonner\b.*\b(argent|somme|cause|association|ligue)\b/', 4],
            ['/\brecu fiscal\b|\bdefiscali|\bdeduction\b/', 5],
            ['/\b(cancer|ligue|depistage)\b/', 2],
        ],
        // Politesse (bonjour, merci, au revoir…) : ce n'est plus une intention
        // — la couche chatbot_social_* la traite AVANT d'arriver ici.
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

/* ═══════════════════ Politesse & petite conversation ═══════════════════
 *
 * Un visiteur ne parle pas par mots-clés : il écrit « bonjour ça va ? »,
 * « merci beaucoup », « salut, je voudrais savoir si je suis inscrite ».
 * Cette couche reconnaît la part SOCIALE du message (salutation, « ça va ? »,
 * merci, au revoir, s'il vous plaît, « tu es un robot ? »…), la RETIRE, et
 * confie le reste au moteur d'intentions ci-dessus. S'il ne reste rien, elle
 * répond elle-même — chaleureusement, en variant ses formulations, et sans
 * jamais se présenter deux fois (la présentation est réservée à l'accueil).
 *
 * Le texte analysé est normalisé AVEC la ponctuation (chatbot_normalize en
 * mode $keepPunct : « , » marque une frontière de proposition, « ? » une
 * question). C'est ce qui permet de distinguer « ça va ? je suis inscrite ? »
 * (deux propositions) de « je suis bien inscrite » (où « bien » n'a rien de
 * social) : les formules de bien-être ne sont retirées qu'en tête ou en fin
 * de proposition, jamais au milieu d'une phrase.
 */

/** Sujets proposés en réponses rapides quand Rosie « relance ». */
function chatbot_quick_default(): array
{
    return ['✅ Mon inscription', '🎽 T-shirt', '💶 Tarifs', '📩 QR code non reçu', '📍 Lieu & horaires', '❓ Voir la FAQ', '✉️ Nous écrire'];
}

/** Une formulation au hasard : la même phrase trois fois de suite sent le robot. */
function chatbot_pick(array $variants): string
{
    return $variants[array_rand($variants)];
}

/**
 * Le mot de salutation à renvoyer. On reprend celui du visiteur (« Salut » à
 * qui dit salut, « Bonsoir » à qui dit bonsoir) ; sans indice, l'heure du
 * serveur tranche entre Bonjour et Bonsoir.
 */
function chatbot_hello(string $said = ''): string
{
    if ($said !== '') {
        if ($said === 're' || preg_match('/^re[- ]?bonjour/', $said))  return 'Re-bonjour';
        if (strpos($said, 'bonsoir') !== false)                          return 'Bonsoir';
        if (in_array($said, ['salut', 'slt'], true))                     return 'Salut';
        if (in_array($said, ['coucou', 'cc'], true))                     return 'Coucou';
        if (in_array($said, ['hello', 'hi', 'hey'], true))               return 'Hello';
        if (strpos($said, 'bonjour') === 0 || $said === 'bjr')           return 'Bonjour';
    }
    $h = (int)date('G');
    return ($h >= 18 || $h < 5) ? 'Bonsoir' : 'Bonjour';
}

/**
 * Tous les motifs de la couche sociale (texte normalisé : minuscules, sans
 * accents, apostrophes → espaces, « , » et « ? » comme frontières).
 * UN motif par ligne : le script de test les relit tels quels.
 *   - motifs « retirés »  : appliqués sur le message, la partie reconnue est
 *                           effacée (greeting … presence)
 *   - motifs « w_… »      : le message ENTIER (ancré ^…$ par l'appelant)
 *   - leadin / filler     : amorces retirées en tête, mots vides résiduels
 */
function chatbot_social_patterns(): array
{
    $L  = '(?<=^|[,?])\s*';      // début de proposition
    $R  = '\s*(?=[,?]|$)';       // fin de proposition
    $hi = '(re[- ]?bonjour|bonjours?|bonsoir|salut|coucou|hello|hi|hey|yo|wesh|bjr|slt)';
    return [
        'greeting'      => '\b' . $hi . '\b(\s+a\s+(tous|toutes|vous|toi))?(\s+(madame|monsieur|tout\s+le\s+monde|l\s+assistante?|le\s+robot))?',
        'greeting_sms'  => '^\s*(cc|re)' . $R,
        'named'         => '\brosie\b',
        'please'        => '\b(s\s*il\s+(vous|te)\s+plait|svp|stp|please|siouplait|siouplai)\b',
        'thanks'        => '\b(merci+s?(\s+(beaucoup|bien|infiniment|mille\s+fois|d\s+avance|a\s+(toi|vous)))?(\s+pour\s+((ton|votre|vos|ces|cette|la|le|les|l|tout)\s+)?\w+)?|merki|thanks?|thank\s+you|thx|je\s+(te|vous)\s+remercie(\s+d\s+avance)?)\b',
        'confused_q'    => $L . '(pardon|comment|quoi|hein|je\s+(ne\s+)?comprends\s+pas|j\s+ai\s+pas\s+compris|je\s+n\s+ai\s+pas\s+compris|pas\s+compris|c\s+est\s+a\s+dire|comment\s+ca)\s*(?=\?)',
        'compliment'    => $L . '(c\s+est\s+(tres\s+|trop\s+|vraiment\s+)?(gentil|sympa|adorable|top|cool)|(t|tu|vous)\s+(es|etes)\s+(trop\s+|tres\s+|vraiment\s+|super\s+)?(gentil|gentille|sympa|cool|genial|geniale|forte?|top|au\s+top|mignonne?|belle|beau|intelligente?|efficace|rapide|la\s+meilleure|le\s+meilleur|super|adorable|parfaite?|drole)|je\s+(t|vous)\s+(aime|adore|kiffe)|bravo|felicitations?|bien\s+joue|chapeau|respect|(beau|bon)\s+(travail|boulot)|(tu|vous)\s+(m\s+as|m\s+avez)\s+(bien\s+|beaucoup\s+|vraiment\s+)?aidee?|tu\s+(assures|geres|dechires)|(tres|trop)\s+(gentil|gentille|sympa))' . $R,
        'apology'       => $L . '(pardon|desolee?|excuse[- ]?moi|excusez[- ]?moi|oups|oops|mince|zut|je\s+m\s+excuse|toutes\s+mes\s+excuses|autant\s+pour\s+moi|my\s+bad)' . $R,
        'bye'           => '\b(au\s+revoir|a\s+bientot|a\s+plus(\s+tard)?|a\s+tout\s+a\s+l\s+heure|a\s+la\s+prochaine|a\s+demain|a\s+(dimanche|samedi|lundi|mardi|mercredi|jeudi|vendredi)|a\s+tres\s+vite|a\s+tout\s+de\s+suite|bonne\s+(journee|soiree|nuit|continuation|fin\s+de\s+journee|semaine|apres[- ]?midi|chance|route)(\s+a\s+(toi|vous|tous|toutes))?|bon\s+(week[- ]?end|courage|dimanche|apres[- ]?midi|retablissement)|bye+|ciao|tchao|tchuss|je\s+(vous|te)\s+laisse|adieu|bisous?|bises|bonne\s+course(\s+a\s+(tous|toutes))?)' . $R,
        'how_are_you_q' => $L . '((ca|sa)\s+va(\s+bien)?|(tu|vous)\s+(vas|allez)\s+bien|bien)\s*(?=\?)',
        'not_fine'      => $L . '((oh|ah|ben|bah|bof|non)\s+)?((ca|sa)\s+va\s+pas(\s+(trop|tres|fort|top|terrible|du\s+tout))?|pas\s+(trop|tres|top|terrible|super|genial|fort|la\s+forme|la\s+peche|le\s+moral|ouf|(tres\s+|trop\s+)?bien)|bof|couci[- ]?couca|comme\s+ci\s+comme\s+ca|je\s+vais\s+(mal|pas\s+bien|moyen)|(je\s+suis|chui|j\s+suis|je\s+me\s+sens)\s+(fatiguee?|crevee?|epuisee?|triste|malade|stressee?|deprimee?|pas\s+bien|mal|pas\s+top|nulle?|au\s+bout|hs)|mauvaise\s+journee|(ca|sa)\s+pourrait\s+aller\s+mieux|moyen(nement)?|mal|(ca|sa)\s+va\s+moyen|dure\s+journee|journee\s+difficile)' . $R,
        'fine'          => $L . '((oui|ouais|ouai|moi|nous|ben|bah|oh|ah)\s+)?(((je|moi\s+je|j)\s+(vais|suis)|on\s+va|nous\s+allons|tout\s+va|(ca|sa)\s+va|je\s+me\s+sens)\s+(tres\s+|super\s+|plutot\s+|assez\s+|vraiment\s+|trop\s+|tout\s+a\s+fait\s+)?(bien|nickel|impec\w*|super|top|au\s+top|en\s+(pleine\s+)?forme|parfaitement|a\s+merveille|tranquille|cool|d\s+enfer)|(oui|ouais|ouai|moi)\s+(ca|sa)\s+va|(ca|sa)\s+va(?=\s+(merci|et\s+(toi|vous)\b))|(tres|plutot|super|vraiment)\s+bien|bien(?=\s+(merci|et\s+(toi|vous)\b))|en\s+(pleine\s+)?forme|tout\s+va\s+bien|(ca|sa)\s+roule|(ca|sa)\s+gaze|la\s+peche|pas\s+mal)(\s+(aujourd\s+hui|ce\s+matin|ce\s+soir|en\s+ce\s+moment))?(\s+merci)?\s*(?=[,?]|$|et\s+(toi|vous)\b)',
        'how_are_you'   => $L . '(et\s+)?(alors\s+)?(comment\s+((ca|sa)\s+va|vas[- ]?tu|allez[- ]?vous|tu\s+vas|vous\s+allez|va)|(ca|sa)\s+va(\s+bien)?|(tu|vous|t)\s+(vas|allez|es|etes)\s+(bien|en\s+forme)|la\s+forme|quoi\s+de\s+neuf|cava|sava)(\s+(aujourd\s+hui|ce\s+matin|ce\s+soir|en\s+ce\s+moment))?\s*(?=[,?]|$|et\s+(toi|vous)\b)',
        'askback'       => $L . 'et\s+(toi|vous)(\s+(ca|sa)\s+va)?' . $R,
        'presence'      => $L . '((t|tu)\s+es\s+(toujours\s+|encore\s+)?la|vous\s+etes\s+(toujours\s+|encore\s+)?la|(il\s+)?y\s+a\s+quelqu\s+un|ya\s+quelqu\s+un|quelqu\s+un|allo+|tu\s+m\s+entends|vous\s+m\s+entendez|c\s+est\s+(bien\s+)?(toi|vous)|tu\s+es|t\s+es|vous\s+etes)' . $R,
        // ── Messages entiers ──
        'w_rude'        => '(?:\w+ ){0,3}(?:connard|connasse|con|conne|salope|pute|putain|merde|fdp|ntm|nique|encule|batard|batarde|ta gueule|tg|debile|idiot|idiote|stupide|nul|nulle|inutile|abruti|cretin|bouffon|bouffonne|ferme la|va te faire|tu sers a rien|imbecile|pourri|pourrie|de merde|a chier|bidon|naze|casse toi|degage|va chier|ta mere|fuck|shit|bullshit|wtf|(?:tu es|t es) (?:nul|nulle|bete|con|conne|inutile)|vous etes (?:nuls?|nulles?|betes?|inutiles?))(?: \w+){0,3}',
        'w_identity'    => 'qui (?:es|est|etes)[- ](?:tu|vous|ce)|(?:t|tu) es qui|vous etes qui|c est qui(?: ca| toi)?|(?:t|tu|vous) (?:es|etes) (?:un|une) (?:robot|humain|humaine|machine|ia|bot|vraie personne|personne reelle|personne|intelligence artificielle|vrai humain|vraie humaine|femme|fille|homme|garcon|mec|nana)|(?:es|est|etes)[- ](?:tu|vous) (?:un|une) (?:robot|humain|humaine|bot|ia|machine|vraie personne|personne)|est[- ]ce (?:que tu es|que vous etes|que t es|un robot|une vraie personne|une personne|un humain)(?: (?:un|une) \w+(?: \w+)?)?|je parle a (?:un|une) (?:robot|humain|humaine|bot|machine|vraie personne|personne)|c est (?:un|une) (?:robot|humain|humaine|bot|machine|vraie personne)|comment (?:tu t appelles?|vous vous appelez|tu te nommes|t appelles? tu|vous appelez vous)|(?:c est quoi |quel est )?(?:ton|votre) (?:nom|prenom)|(?:tu t appelles?|vous vous appelez) comment|(?:tu|vous) (?:fais|faites) quoi(?: dans la vie)?|que (?:fais|faites)[- ](?:tu|vous)(?: dans la vie)?|(?:tu es|t es|vous etes) (?:reelle?|vraie?|humaine?|virtuelle?)|robot|un robot|une ia|une machine|qui parle|a qui je parle|je parle a qui|avec qui je parle|c est quoi|(?:tu|vous) (?:es|etes) (?:chatgpt|une ia|gpt|google|siri|alexa)|qui t a (?:cree|creee|fait|faite|programme|programmee|concu|concue)|qui vous a (?:cree|creee|fait|programme)',
        'w_age'         => '(?:tu as|t as|vous avez) quel age|quel age (?:as[- ]tu|avez[- ]vous|tu as|t as|vous avez)|(?:tu|vous) (?:as|avez) (?:\d+ ans|quel age)|ton age|votre age|c est quoi ton age',
        'w_confused'    => 'je ne comprends pas|je comprends pas|comprends pas|pas compris|j ai pas compris|je n ai pas compris|c est a dire|comment ca|hein|quoi|comment|pardon|je ne comprends rien|je comprends rien|j ai rien compris|je n ai rien compris',
        'w_help'        => 'aide|aidez[- ]?moi|aide[- ]?moi|help|sos|au secours|je (?:ne )?sais pas(?: quoi (?:demander|faire|dire|choisir))?|(?:que|qu est[- ]ce que|quoi) (?:peux|peut|sais|sait|pouvez|savez)[- ](?:tu|vous|on)(?: (?:faire|me dire|m apporter|pour moi))?|(?:tu|vous) (?:sers|servez|fais|faites) a quoi|tu (?:sais|peux) faire quoi|(?:tu|vous) (?:peux|pouvez) (?:m|nous) aider(?: (?:a|pour|avec) quoi)?|(?:a quoi|pour quoi) (?:tu sers|vous servez|ca sert)|c est quoi (?:ce (?:truc|chat|machin|bidule)|ca|cette fenetre|ce chat|ici)|que faire|quelles? (?:questions?|sujets?|choix|options?)|menu|options?|la liste|montre[- ]moi|montrez[- ]moi|liste des questions|les questions|voir les questions|afficher les questions|(?:mon|le) menu|accueil|recommencer|retour|debut|(?:tu|vous) (?:proposes?|proposez) quoi|que (?:proposes|proposez)[- ](?:tu|vous)|qu est[- ]ce (?:que tu proposes|que vous proposez|qu on peut faire ici)|(?:tu|vous) (?:peux|pouvez) faire quoi(?: pour moi)?',
        'w_question_intro' => 'j ai une (?:petite |autre |derniere |deuxieme )?question|(?:petite |une |autre )?question|je (?:voudrais|voudrai|veux|souhaite|souhaiterais|aimerais|aimerai) (?:savoir|vous demander|te demander|poser une question|demander|un renseignement|une info|des infos|me renseigner)(?: quelque chose| qqch| un truc)?|(?:je peux|puis[- ]je|est[- ]ce que je peux|je pourrais) (?:te|vous) poser une question|j aurais une (?:petite )?question|besoin d (?:une )?info(?:rmation)?s?|j ai besoin d aide|j aurais besoin d aide|j ai besoin d (?:un )?renseignement|renseignements?|je cherche une info|infos?|informations?|une info|des infos|je (?:voudrais|veux|aimerais) (?:un|des) (?:renseignements?|infos?|informations?)|(?:tu|vous) (?:peux|pouvez) me renseigner|(?:je peux|puis[- ]je) (?:te|vous) demander (?:quelque chose|un truc|qqch)|dis[- ]?moi|dites[- ]?moi|je t ecoute|je vous ecoute',
        'w_laugh'       => '(?:ha|ah|ho|hi|he){2,}\w*|(?:ha ?){2,}|lol|mdr|ptdr|xd|xdd|rire|trop drole|mort de rire|elle est bonne|(?:tu es|t es|vous etes) (?:drole|marrante?|rigolote?)|marrant|marrante|rigolo|rigolote',
        'w_praise'      => '(?:ok |ah |oh |c est |c etait |trop |tres |vraiment |tout )?(?:super|top|au top|parfait|genial|geniale|cool|nickel|impeccable|impec|excellent|excellente|chouette|formidable|magnifique|extra|bien|clair|tres clair|parfaitement clair|tout bon|exactement|voila|tout a fait|bien vu|trop fort|trop forte|j adore|je kiffe|c est ca)(?: (?:super|top|parfait|genial|cool|nickel|merci|ok|clair|bien|alors|donc))*',
        'w_ack_no'      => 'non|nan|nope|no|non merci|nan merci|non c est bon|non c est tout|c est bon|c est tout|rien|rien d autre|ca ira|ca va aller|pas besoin|pas pour le moment|pas maintenant|je n ai pas de question|j ai pas de question|aucune|aucune question|non ca va|non ca ira|non rien|c est tout pour moi|ce sera tout|ca sera tout|c est bon merci|non c est bon merci|laisse tomber|laissez tomber|pas la peine|tant pis|ok c est bon|non rien d autre|nan c est bon',
        'w_ack_yes'     => 'ok|okay|oki|okey|d accord|dac|d acc|ca marche|ca roule|entendu|compris|bien recu|je vois|ah ok|ah d accord|ah bon|ah|oh|oui|ouais|ouai|yes|yep|si|volontiers|avec plaisir|je veux bien|c est note|note|ca me va|pas de souci|pas de probleme|ok ca marche|ok super|ok d accord|d accord merci|ok merci|oui merci|ah ok merci|ah d accord merci|bien sur|evidemment|carrement|allez|allons y|go|c est parti|vas y|allez y|ok je t ecoute|ok dis moi',
        // ── Amorces (tête de phrase) et mots vides ──
        'leadin'        => 'et|alors|donc|du coup|euh|heu|hum|ben|bah|bon|ok|d accord|dis[- ]?moi|dites[- ]?moi|excusez[- ]?moi|excuse[- ]?moi|juste|petite question|j ai une (?:petite |autre )?question|une question|question|j aurais une question|(?:tu|vous) (?:peux|pouvez) m aider(?: (?:a|pour|avec))?|j ai besoin d aide(?: (?:pour|avec))?|j aurais besoin d aide(?: (?:pour|avec))?|aidez[- ]?moi(?: (?:a|pour|avec))?|aide[- ]?moi(?: (?:a|pour|avec))?|je (?:voudrais|voudrai|veux|souhaite|souhaiterais|aimerais|aimerai) (?:savoir|vous demander|te demander|demander)|(?:est[- ]ce que )?(?:tu|vous) (?:sais|savez|peux|pouvez) me dire|(?:pouvez|peux)[- ](?:vous|tu) me dire|(?:je peux|puis[- ]je|je voudrais|je voulais|je pourrais) (?:te|vous) poser une question',
        'filler'        => 'et|ou|mais|donc|alors|a|de|du|le|la|les|un|une|des|ca|sa|c|est|ce|en|y|il|elle|on|je|tu|vous|moi|toi|nous|bien|tres|trop|aussi|encore|vraiment|voila|hein|quoi|ah|oh|eh|euh|heu|hum|ben|bah|bon|ok|d|accord|puis|pour|par|sur|avec|si|me|te|se|ne|pas|que|qui|-|\.',
    ];
}

/**
 * Sépare la part sociale d'un message de sa vraie question.
 * @param  string $text message normalisé AVEC ponctuation : chatbot_normalize($msg, true)
 * @return array {tags: array<string,bool>, hello: string, askback: bool, rest: string}
 *         rest = ce qu'il reste à comprendre (normalisé comme chatbot_normalize),
 *         '' si le message n'était que de la politesse.
 */
function chatbot_social_parse(string $text): array
{
    $P = chatbot_social_patterns();
    $tags = []; $hello = ''; $askback = false;
    $s = ' ' . $text . ' ';

    // Retire un motif du texte et pose son étiquette. Vrai s'il a matché.
    $take = function (string $key, string $tag) use (&$s, &$tags, $P): bool {
        $re = '/' . $P[$key] . '/';
        if (!preg_match($re, $s)) return false;
        $s = preg_replace($re, ' ', $s);
        $tags[$tag] = true;
        return true;
    };

    // 1) Salutation — n'importe où ; on retient le mot pour le renvoyer
    if (preg_match('/' . $P['greeting'] . '/', $s, $m) || preg_match('/' . $P['greeting_sms'] . '/', $s, $m)) {
        $hello = $m[1];
        $take('greeting', 'greeting') || $take('greeting_sms', 'greeting');
    }
    // Bien-être : la question explicite (« ça va bien ? ») avant les affirmations.
    // Passé DEUX fois : avant le retrait du « merci » (« je vais bien merci »,
    // « ça va merci » sont des affirmations, pas des questions) et après
    // (« merci ça va ? » ne devient une question qu'une fois « merci » ôté).
    $wellbeing = function () use ($take, &$askback): void {
        $take('how_are_you_q', 'how_are_you');
        $take('not_fine', 'not_fine');
        $take('fine', 'fine');
        $take('how_are_you', 'how_are_you');
        if ($take('askback', 'how_are_you')) $askback = true;   // « et vous ? » : on répond sans redemander
    };
    // 2) Interpellation, s'il vous plaît
    $take('named', 'named');
    $take('please', 'please');
    // 3) Bien-être (1er passage), puis merci
    $wellbeing();
    $take('thanks', 'thanks');
    // 4) « pardon ? », compliments, excuses, au revoir
    $take('confused_q', 'confused');
    $take('compliment', 'compliment');
    $take('apology', 'apology');
    $take('bye', 'bye');
    // 5) Bien-être (2e passage), « tu es là ? »
    $wellbeing();
    $take('presence', 'presence');

    // 6) Ce qu'il reste : la vraie question (ou rien)
    $rest = trim(preg_replace('/\s+/', ' ', str_replace([',', '?'], ' ', $s)));

    // Message entier = petite conversation (identité, aide, oui/non, rire…)
    if ($rest !== '') {
        foreach ($P as $key => $re) {
            if (strpos($key, 'w_') !== 0) continue;
            if (preg_match('/^(?:' . $re . ')$/', $rest)) { $tags[substr($key, 2)] = true; $rest = ''; break; }
        }
    }
    if ($rest !== '') {
        // Amorces retirées pour que le moteur voie la question elle-même
        $rest = preg_replace('/^(?:(?:' . $P['leadin'] . ')\s+)+/', '', $rest);
        // Il ne reste que des mots vides (« et », « alors »…) → rien à comprendre
        if (preg_match('/^(?:(?:' . $P['filler'] . ')\s*)+$/', $rest)) $rest = '';
    }
    if ($rest === '' && !$tags) $tags['emoji'] = true;   // que des émojis / de la ponctuation

    return ['tags' => $tags, 'hello' => $hello, 'askback' => $askback, 'rest' => $rest];
}

/**
 * Réponse de Rosie quand le message n'était QUE de la petite conversation.
 * @param array $p     résultat de chatbot_social_parse()
 * @param array $set   ligne complète de `setting`
 * @param bool  $intro accueil automatique à l'ouverture du widget : la seule
 *                     fois où Rosie se présente
 * @return array|null  {text, quick, action} — null si rien de social
 */
function chatbot_social_reply(array $p, array $set, bool $intro = false): ?array
{
    $t = $p['tags'];
    $r = ['text' => '', 'quick' => [], 'action' => null];
    $hello = chatbot_hello($p['hello'] ?? '');
    $named = !empty($t['named']);

    if ($intro) {
        $extra = '';
        $ts = !empty($set['date_course']) ? strtotime($set['date_course']) : false;
        if ($ts && $ts > time()) {
            $days = (int)ceil(($ts - time()) / 86400);
            $extra = ' Plus que <strong>' . $days . ' jour' . ($days > 1 ? 's' : '') . '</strong> avant la course ! 🎉';
        }
        $r['text'] = $hello . ' ! 👋 Je suis <strong>Rosie</strong>, l\'assistante de Forbach en Rose.' . $extra
                   . '<br>Comment puis-je vous aider ?';
        $r['quick'] = chatbot_quick_default();
        return $r;
    }
    if (!$t) return null;

    // Grossièreté ou coup de sang : on ne relève pas, on reste douce et utile
    if (!empty($t['rude'])) {
        $r['text'] = 'Oh… je suis désolée si quelque chose vous a contrarié. 💗 Je fais de mon mieux ! '
                   . 'Dites-moi ce dont vous avez besoin — ou écrivez-nous, une vraie personne vous répondra.';
        $r['quick'] = chatbot_quick_default();
        return $r;
    }

    // Au revoir : on clôt (avec le merci s'il y en a un), sans relancer
    if (!empty($t['bye'])) {
        $txt = !empty($t['thanks']) ? chatbot_pick(['Avec plaisir ! 💗 ', 'Je vous en prie ! 💗 ']) : '';
        $txt .= chatbot_pick([
            'À bientôt, et merci de soutenir la lutte contre le cancer du sein ! 🎀',
            'Bonne journée à vous, et à bientôt sur Forbach en Rose ! 🎀',
            'Au revoir, prenez soin de vous ! 💗🎀',
        ]);
        $r['text'] = $txt;
        return $r;
    }

    $parts = [];        // phrases, dans l'ordre : salutation → bien-être → merci → …
    $relance = true;    // finir par « Comment puis-je vous aider ? » + sujets
    $quick = true;

    if (!empty($t['greeting'])) {
        $parts[] = $hello . ' ! ' . chatbot_pick(['👋', '😊', '🎀']);
    }
    if (!empty($t['presence'])) {
        $parts[] = $named ? 'Oui, c\'est bien moi, Rosie ! 😊' : 'Oui, je suis là ! 😊';
    } elseif ($named && count($t) === 1) {
        $parts[] = 'Oui, c\'est moi ! 😊';
    }
    if (!empty($t['not_fine'])) {
        $parts[] = chatbot_pick([
            'Oh, je suis désolée de l\'entendre… 💗 J\'espère pouvoir vous rendre la journée un peu plus douce.',
            'Oh non… 💗 Courage ! Si je peux vous aider en quoi que ce soit, je suis là.',
        ]);
    } elseif (!empty($t['fine']) && !empty($t['how_are_you'])) {
        $parts[] = chatbot_pick([
            'Ravie de l\'entendre ! Moi, je vais très bien, merci 😊',
            'Super ! Moi ça va très bien, merci de demander 😊',
        ]);
    } elseif (!empty($t['fine'])) {
        $parts[] = chatbot_pick(['Ravie de l\'entendre ! 😊', 'Tant mieux ! 😊', 'Parfait, ça fait plaisir ! 😊']);
    } elseif (!empty($t['how_are_you'])) {
        $parts[] = !empty($p['askback'])
            ? chatbot_pick(['Je vais très bien, merci de demander ! 😊', 'Très bien, merci ! 😊'])
            : chatbot_pick([
                'Je vais très bien, merci ! Et vous ? 😊',
                'Très bien, merci de demander ! Et vous, ça va ? 😊',
                'En pleine forme, merci ! Et vous ? 😊',
            ]);
    }
    if (!empty($t['apology'])) {
        $parts[] = chatbot_pick(['Pas de souci, ça arrive ! 😊', 'Aucun problème ! 😊']);
    }
    $wellbeing = !empty($t['not_fine']) || !empty($t['fine']) || !empty($t['how_are_you']);
    if (!empty($t['compliment'])) {
        $parts[] = chatbot_pick(['Oh, merci, vous êtes adorable ! 🥰', 'Vous allez me faire rougir ! 😊', 'Merci beaucoup, ça me touche ! 💗']);
    } elseif ((!empty($t['thanks']) || !empty($t['praise'])) && !$wellbeing && empty($t['ack_no'])) {
        // « je vais bien merci » : le merci fait partie de l'échange, on n'y
        // répond pas à part ; « non merci » a sa propre formule plus bas.
        $parts[] = chatbot_pick(['Avec plaisir ! 💗', 'Je vous en prie ! 💗', 'C\'est tout naturel ! 💗']);
    }
    if (!empty($t['laugh']) || !empty($t['emoji'])) {
        $parts[] = chatbot_pick(['😄', 'Haha ! 😄', '😊']);
    }
    if (!empty($t['identity'])) {
        $parts[] = 'Je suis <strong>Rosie</strong>, l\'assistante virtuelle de Forbach en Rose 🎀 — un petit robot, '
                 . 'mais avec un grand cœur ! Je connais la course sur le bout des doigts : inscriptions, t-shirts, '
                 . 'QR codes, horaires, parcours… Et si vous préférez parler à une vraie personne, il suffit de nous écrire.';
    }
    if (!empty($t['age'])) {
        $parts[] = 'Je suis toute jeune — je suis née avec ce site ! 🎀 Mais je connais Forbach en Rose par cœur.';
    }
    if (!empty($t['help']) || !empty($t['confused'])) {
        $parts[] = (!empty($t['confused']) ? 'Pardon, je n\'ai peut-être pas été claire ! 😊 ' : '')
                 . 'Voici ce que je sais faire : vérifier votre inscription ou votre t-shirt, renvoyer votre QR code, '
                 . 'vous renseigner sur le lieu, les horaires, le parcours, les tarifs… Posez-moi votre question, ou choisissez un sujet :';
        $relance = false;
    } elseif (!empty($t['question_intro']) || (!empty($t['please']) && count($t) === 1)) {
        $parts[] = 'Je vous écoute ! 😊 Posez-moi votre question, ou choisissez un sujet :';
        $relance = false;
    }
    if (!empty($t['ack_no'])) {
        $parts[] = !empty($t['thanks'])
            ? chatbot_pick(['Avec plaisir ! 💗 Si une question vous vient, je suis là.', 'Je vous en prie ! 💗 Je reste là si besoin.'])
            : chatbot_pick(['D\'accord ! 😊 Si une question vous vient, je suis là.', 'Très bien ! 😊 Je reste là si besoin.']);
        $relance = false; $quick = false;
    } elseif (!empty($t['ack_yes']) && empty($t['thanks']) && empty($t['praise'])) {
        $parts[] = chatbot_pick(['Parfait ! 😊', 'Très bien ! 😊', 'Ça marche ! 😊']);
    }

    if (!$parts) $parts[] = 'Je suis là ! 😊';
    $text = implode(' ', $parts);
    if ($relance) {
        $onlyThanks = (!empty($t['thanks']) || !empty($t['praise'])) && empty($t['greeting'])
                   && empty($t['how_are_you']) && empty($t['fine']) && empty($t['not_fine']);
        $text .= '<br>' . ($onlyThanks
            ? 'N\'hésitez pas si vous avez une autre question.'
            : chatbot_pick(['Comment puis-je vous aider ?', 'Que puis-je faire pour vous ?', 'Dites-moi ce que je peux faire pour vous !']));
    }
    $r['text'] = $text;
    $r['quick'] = $quick ? chatbot_quick_default() : [];
    return $r;
}

/**
 * Habille la réponse d'une intention (ou d'une FAQ) avec la politesse du
 * message : « Bonjour ! Je vais très bien, merci ! 😊 » devant la réponse à
 * « bonjour ça va ? je suis inscrite ? », « Bonne journée » derrière un
 * « merci, bonne journée ».
 */
function chatbot_social_decorate(array $reply, array $p): array
{
    $t = $p['tags'];
    $pre = '';
    if (!empty($t['greeting']))        $pre .= chatbot_hello($p['hello'] ?? '') . ' ! ';
    if (!empty($t['not_fine']))        $pre .= 'Oh, désolée de l\'entendre… 💗 ';
    elseif (!empty($t['how_are_you'])) $pre .= 'Je vais très bien, merci ! 😊 ';
    elseif (!empty($t['fine']))        $pre .= 'Ravie de l\'entendre ! 😊 ';
    if (!empty($t['apology']))         $pre .= 'Pas de souci ! ';
    if ($pre !== '') $reply['text'] = $pre . $reply['text'];
    if (!empty($t['bye'])) $reply['text'] .= '<br>Bonne journée à vous, et à bientôt ! 🎀';
    return $reply;
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
 * Échappe le texte libre PUIS rend les URLs cliquables avec un affichage
 * « propre » :
 *   - https://www.exemple.fr/page/  →  lien affiché « exemple.fr/page »
 *     (sans schéma ni www, tronqué au-delà de 40 caractères)
 *   - /register, /parcours…         →  lien interne relatif affiché « register »
 * Utilisé pour les réponses FAQ (chatbot + page publique /faq).
 */
function chatbot_linkify(string $s): string
{
    $esc = str_replace("\n", '<br>', htmlspecialchars(trim($s)));

    // URLs absolues http(s)
    $esc = preg_replace_callback('~https?://[^\s<>"«»]+~u', function ($m) {
        $url = $m[0];
        $trail = '';
        // La ponctuation finale (fin de phrase, parenthèse) n'appartient pas au lien
        while ($url !== '' && mb_strpos('.,;:!?)»', mb_substr($url, -1)) !== false) {
            $trail = mb_substr($url, -1) . $trail;
            $url = mb_substr($url, 0, -1);
        }
        $label = rtrim(preg_replace('~^https?://(www\.)?~', '', $url), '/');
        if (mb_strlen($label) > 40) $label = mb_substr($label, 0, 37) . '…';
        return '<a href="' . $url . '" target="_blank" rel="noopener">' . $label . '</a>' . $trail;
    }, $esc);

    // Pages internes du site notées « /register », « /parcours »…
    // (lien relatif : fonctionne quel que soit le domaine / sous-dossier)
    $esc = preg_replace_callback('~(^|[\s>(])/([a-z0-9_-]{2,})~u', function ($m) {
        return $m[1] . '<a href="' . $m[2] . '">' . $m[2] . '</a>';
    }, $esc);

    return $esc;
}

/**
 * Construit la réponse à une intention.
 * @param array $set  ligne complète de `setting`
 * @return array {text: html, quick: string[], action: ?string}
 */
function chatbot_answer(string $intent, array $set): array
{
    $quickDefault = chatbot_quick_default();
    $r = ['text' => '', 'quick' => [], 'action' => null];

    switch ($intent) {
        /* Bonjour / merci / au revoir : voir chatbot_social_reply() — Rosie y
         * répond comme une personne, et se présente uniquement à l'accueil. */

        /* ── Espace coureur & application (lot 6) ───────────────────────────
         * Les URL sont écrites en relatif depuis la racine du site : le chatbot
         * répond sur toutes les pages publiques, et un lien relatif à la page
         * courante casserait dès qu'on n'est pas à la racine. */
        case 'espace_coureur':
            $r['text'] = '🔑 Votre <strong>espace coureur</strong> vous donne accès à tout moment à '
                       . 'votre inscription, votre QR code et vos informations.<br><br>'
                       . '<strong>Pas de mot de passe à retenir</strong> : vous saisissez l\'adresse '
                       . 'email utilisée lors de votre inscription, et vous recevez un code à '
                       . '6 chiffres par email.<br><br>'
                       . '👉 <a href="/public/espace-coureur/login.php">Accéder à mon espace</a>';
            $r['quick'] = ['📱 L\'application', '✏️ Corriger mes infos', '🔄 Transférer mon inscription', '❓ Voir la FAQ'];
            break;

        case 'application': {
            // On n'annonce l'application que si les liens des magasins sont
            // renseignés. Promettre un téléchargement qui n'existe pas fait
            // perdre son temps au coureur — et le chatbot ne doit jamais mentir.
            $ios     = trim((string) ($set['app_store_url_ios'] ?? ''));
            $android = trim((string) ($set['app_store_url_android'] ?? ''));

            if ($ios !== '' || $android !== '') {
                $liens = [];
                if ($ios !== '')     $liens[] = '<a href="' . htmlspecialchars($ios, ENT_QUOTES, 'UTF-8') . '">iPhone</a>';
                if ($android !== '') $liens[] = '<a href="' . htmlspecialchars($android, ENT_QUOTES, 'UTF-8') . '">Android</a>';
                $r['text'] = '📱 L\'application Forbach en Rose est disponible !<br><br>'
                           . 'Télécharger pour : ' . implode(' ou ', $liens) . '<br><br>'
                           . 'Vous vous y connectez comme sur le site : votre adresse email, '
                           . 'puis un code à 6 chiffres. Vous y retrouvez votre QR code, votre '
                           . 'inscription, et le <strong>suivi de votre course</strong> le jour J.<br><br>'
                           . 'Vous pouvez aussi tout faire depuis votre navigateur, sans rien installer : '
                           . '<a href="/public/telecharger-app.php">en savoir plus</a>.';
            } else {
                $r['text'] = '📱 L\'application mobile <strong>arrive bientôt</strong>.<br><br>'
                           . 'Ce qu\'elle apportera en plus du site : le <strong>suivi de votre '
                           . 'course le jour J</strong> et votre temps. Une page web ne peut pas '
                           . 'le faire — elle s\'arrête dès que l\'écran du téléphone s\'éteint.<br><br>'
                           . 'En attendant, votre <strong>espace coureur</strong> fait déjà tout le reste '
                           . '— QR code, inscription, transfert, corrections — depuis n\'importe quel '
                           . 'navigateur, et sans rien installer.<br><br>'
                           . '👉 <a href="/public/espace-coureur/login.php">Accéder à mon espace</a> '
                           . 'ou <a href="/public/telecharger-app.php">en savoir plus</a>';
            }
            $r['quick'] = ['🔑 Mon espace coureur', '⏱️ Chronométrage', '📩 QR code non reçu', '❓ Voir la FAQ'];
            break;
        }

        case 'corriger_infos':
            $r['text'] = '✏️ Vous pouvez corriger vous-même vos informations depuis votre '
                       . 'espace coureur :<br><br>'
                       . '• <strong>Nom et prénom</strong> — dans « Mon compte »<br>'
                       . '• <strong>Adresse email</strong> — un code de confirmation est envoyé à la '
                       . 'nouvelle adresse, pour éviter toute faute de frappe<br>'
                       . '• <strong>Sexe et âge</strong> — dans le détail de votre inscription<br><br>'
                       . 'Les corrections sont reportées sur votre inscription à la course. '
                       . 'Le sexe et l\'âge ne sont plus modifiables une fois le départ donné : '
                       . 'ils déterminent votre catégorie de classement.<br><br>'
                       . '👉 <a href="/public/espace-coureur/login.php">Accéder à mon espace</a>';
            $r['quick'] = ['🔑 Mon espace coureur', '🔄 Transférer mon inscription', '✉️ Nous écrire'];
            break;

        case 'registration_problem': {
            $txt = '😕 Désolée pour ce désagrément ! Le plus souvent, il s\'agit d\'un souci passager :<br>'
                 . '1️⃣ Réessayez dans quelques minutes, idéalement depuis un autre navigateur ou un autre appareil.<br>'
                 . '2️⃣ Si ça ne passe toujours pas, écrivez-nous en décrivant le problème (message d\'erreur, étape bloquée…) — nous vous aiderons rapidement.';
            $onsite = trim((string)($set['registration_onsite_info'] ?? ''));
            if ($onsite !== '') {
                $txt .= '<br><br>🏢 <strong>Vous pouvez aussi vous inscrire sur place :</strong><br>' . chatbot_esc_nl($onsite);
            }
            $r['text'] = $txt;
            $r['quick'] = ['✉️ Nous écrire', '📝 Comment s\'inscrire ?'];
            break;
        }

        case 'site_problem':
            $r['text'] = '😕 Oups, on dirait un souci technique ! Essayez d\'abord de recharger la page '
                . '(Ctrl+F5) ou d\'ouvrir le site depuis un autre navigateur ou appareil.<br>'
                . 'Si le problème persiste, écrivez-nous en décrivant ce qui ne s\'affiche pas — nous corrigerons au plus vite. 🛠️';
            $r['quick'] = ['✉️ Nous écrire'];
            break;

        case 'registration_modify':
            $r['text'] = '✏️ Pour modifier, corriger ou annuler votre inscription : écrivez-nous via le formulaire '
                . '(bouton « Nous écrire ») en précisant l\'adresse e-mail utilisée lors de l\'inscription — nous nous en occupons rapidement.';
            $r['quick'] = ['✉️ Nous écrire', '✅ Mon inscription'];
            break;

        case 'registration_check':
            $r['text'] = 'Je peux vérifier cela tout de suite ! 🔎<br>Indiquez-moi l\'adresse e-mail utilisée lors de votre inscription :';
            $r['action'] = 'ask_email_registration';
            break;

        case 'tshirt_check':
            $r['text'] = 'Je vérifie si un t-shirt est prévu pour vous ! 🎽<br>Indiquez-moi l\'adresse e-mail utilisée lors de votre inscription :';
            $r['action'] = 'ask_email_tshirt';
            break;

        case 'qrcode_resend':
            /* DEUX chemins, et l'ordre compte. L'espace coureur est instantané,
             * ne peut pas tomber dans les indésirables, marche même si le mail
             * d'origine a été effacé il y a longtemps, et n'a aucun quota
             * d'envoi. Le renvoi par mail reste proposé juste après : c'est ce
             * que beaucoup de gens viennent chercher, et il ne faut pas le leur
             * retirer. L'action ask_email_qrcode est donc conservée telle
             * quelle — la conversation continue exactement comme avant pour qui
             * saisit son adresse. */
            $r['text'] = 'Pas de panique, votre QR code n\'est jamais perdu ! 🎫<br><br>'
                . '<strong>Le plus rapide</strong> — il est dans votre espace coureur, '
                . 'consultable tout de suite :<br>'
                . '👉 <a href="/public/espace-coureur/login.php">Voir mon QR code</a> '
                . '<small>(connexion par code à 6 chiffres, sans mot de passe)</small><br><br>'
                . '<strong>Ou par email</strong> — indiquez-moi l\'adresse utilisée lors de votre '
                . 'inscription, je vous renvoie le mail de confirmation.<br>'
                . '<small>Pensez alors à vérifier votre dossier spam / indésirables.</small>';
            $r['action'] = 'ask_email_qrcode';
            $r['quick']  = ['🔑 Mon espace coureur', '🎽 T-shirt', '✉️ Nous écrire'];
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
            $txt = '🏃‍♀️ Pour vous inscrire, c\'est très simple : rendez-vous sur <a href="register">la page d\'inscription</a> — cela prend 5 minutes !';
            if ($fee > 0) {
                $txt .= '<br>Tarif : <strong>' . $fee . ' €</strong>';
                if (!empty($set['child_pricing_enabled'])) {
                    $txt .= ' (' . (int)$set['child_amount'] . ' € pour les moins de ' . (int)$set['child_age_threshold'] . ' ans)';
                }
                $txt .= ', intégralement reversé à la lutte contre le cancer. 🎀';
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
                $txt .= '.<br>L\'intégralité est reversée à la lutte contre le cancer. 🎀';
            } else {
                $txt = 'Les informations de tarif seront bientôt disponibles. Vous pouvez nous écrire pour en savoir plus !';
            }
            $r['text'] = $txt;
            $r['quick'] = ['📝 Comment s\'inscrire ?'];
            break;
        }

        case 'payment_method': {
            $txt = '💳 Le paiement de l\'inscription se fait <strong>en ligne, de façon sécurisée</strong>, à la fin du '
                 . '<a href="register">formulaire d\'inscription</a>.';
            $onsite = trim((string)($set['registration_onsite_info'] ?? ''));
            if ($onsite !== '') {
                $txt .= '<br><br>🏢 <strong>Inscription (et paiement) sur place également possible :</strong><br>' . chatbot_esc_nl($onsite);
            }
            $txt .= '<br>Pour toute autre modalité (chèque, espèces…), écrivez-nous !';
            $r['text'] = $txt;
            $r['quick'] = ['✉️ Nous écrire', '💶 Tarifs'];
            break;
        }

        case 'deadline': {
            $txt = '🗓️ Les inscriptions en ligne sont ouvertes tant que <a href="register">la page d\'inscription</a> est active — ne tardez pas !';
            $dateStr = chatbot_format_date($set['date_course'] ?? null);
            if ($dateStr !== '') $txt .= '<br>📅 Pour rappel, la course a lieu le <strong>' . $dateStr . '</strong>.';
            $onsite = trim((string)($set['registration_onsite_info'] ?? ''));
            if ($onsite !== '') {
                $txt .= '<br><br>🏢 <strong>Inscription sur place également possible :</strong><br>' . chatbot_esc_nl($onsite);
            }
            $r['text'] = $txt;
            $r['quick'] = ['📝 Comment s\'inscrire ?'];
            break;
        }

        case 'group_registration':
            $r['text'] = '👥 Bonne nouvelle : le formulaire permet d\'inscrire <strong>plusieurs personnes en une seule fois</strong> '
                . 'sur <a href="register">la page d\'inscription</a>.<br>'
                . 'Pour un grand groupe, une entreprise ou une association, écrivez-nous — nous vous faciliterons les choses !';
            $r['quick'] = ['✉️ Nous écrire', '💶 Tarifs'];
            break;

        case 'dossard':
            $r['text'] = '🎫 Pas de dossard papier ici : votre <strong>QR code reçu par e-mail</strong> après l\'inscription fait office de billet le jour J.<br>'
                . 'Gardez-le sur votre téléphone (ou imprimé). Vous ne l\'avez pas reçu ? Dites-moi « je n\'ai pas reçu mon QR code » et je vous le renvoie !';
            $r['quick'] = ['📩 Je n\'ai pas reçu mon QR code', '📍 Lieu & horaires'];
            break;

        /* ⚠️ NE PAS ANNONCER LE CHRONOMÉTRAGE COMME DISPONIBLE.
         * La page « Mes résultats » existe dans l'espace coureur, mais elle est
         * VIDE tant que le chronométrage n'est pas en service. On dit donc où
         * les résultats apparaîtront, au futur, sans jamais laisser croire qu'ils
         * s'y trouvent déjà — sinon les gens iront regarder, ne verront rien, et
         * écriront à l'association pour signaler une panne qui n'existe pas. */
        case 'ranking':
            $r['text'] = '🚶‍♀️ L\'événement est avant tout <strong>solidaire et à allure libre</strong> — '
                . 'l\'essentiel est de participer et de soutenir la cause. 🎀<br><br>'
                . '⏱️ Un <strong>chronométrage par l\'application mobile est en préparation</strong>. '
                . 'Quand il sera en service, vos temps apparaîtront dans la rubrique '
                . '« Mes résultats » de votre espace coureur — inutile de chercher ailleurs.<br><br>'
                . 'D\'ici là, aucun temps n\'est enregistré : venez profiter de la marche. '
                . 'Pour toute précision, consultez <a href="parcours">la page Parcours</a> ou écrivez-nous !';
            $r['quick'] = ['🗺️ Le parcours', '📱 L\'application', '🔑 Mon espace coureur', '✉️ Nous écrire'];
            break;

        case 'dresscode':
            $r['text'] = '🎀 Le rose est à l\'honneur et <strong>fortement encouragé</strong> — mais rien d\'obligatoire : venez comme vous êtes !<br>'
                . 'Et selon les modalités d\'inscription, un t-shirt de l\'événement est prévu.';
            $r['quick'] = ['🎽 T-shirt', '📍 Lieu & horaires'];
            break;

        case 'run_or_walk':
            $r['text'] = '🏃‍♀️ Allure totalement <strong>libre</strong> : marche tranquille, marche rapide ou course — chacun avance à son rythme, l\'essentiel est de participer !<br>'
                . 'Le tracé complet est sur <a href="parcours">la page Parcours</a>.';
            $r['quick'] = ['🗺️ Le parcours', '🕘 Les horaires'];
            break;

        /* ⚠️ RÉPONSE REMPLACÉE AU LOT 6. Elle disait « écrivez-nous, nous
         * verrons ensemble ce qui est possible » — ce qui était vrai avant le
         * lot 4, quand un transfert passait par l'organisation. Ce n'est plus
         * le cas : le coureur le fait lui-même en trente secondes. Laisser
         * l'ancienne réponse aurait envoyé des gens écrire un mail pour rien,
         * et donné du travail à l'association pour rien. */
        case 'transfer':
            $r['text'] = '🔄 Vous ne pouvez plus venir ? <strong>Votre inscription peut être '
                       . 'transférée</strong> à quelqu\'un d\'autre — inutile de la perdre, et '
                       . 'vous n\'avez besoin de personne pour le faire.<br><br>'
                       . '1️⃣ Connectez-vous à votre espace coureur<br>'
                       . '2️⃣ Ouvrez l\'inscription concernée<br>'
                       . '3️⃣ Indiquez l\'adresse email de la personne<br>'
                       . '4️⃣ Elle reçoit un mail et confirme<br><br>'
                       . 'Tant qu\'elle n\'a pas confirmé, <strong>vous pouvez annuler</strong> et '
                       . 'l\'inscription reste la vôtre. Une date limite s\'applique avant la course.<br><br>'
                       . '👉 <a href="/public/espace-coureur/login.php">Accéder à mon espace</a>';
            $r['quick'] = ['🔑 Mon espace coureur', '📍 Lieu & horaires', '✉️ Nous écrire'];
            break;

        case 'spectator':
            $r['text'] = '👏 Bien sûr ! Le village, les animations et l\'ambiance sont ouverts à toutes et à tous — '
                . 'seule la participation à la marche nécessite une inscription.<br>Venez encourager les participants !';
            $r['quick'] = ['📍 Lieu & horaires', '📝 Comment s\'inscrire ?'];
            break;

        case 'privacy':
            $r['text'] = '🔒 Vos données servent uniquement à la gestion de votre inscription et de l\'événement — elles ne sont jamais revendues.<br>'
                . 'Tous les détails sont dans notre <a href="politique-confidentialite">politique de confidentialité</a>. '
                . 'Pour une demande d\'accès ou de suppression, écrivez-nous.';
            $r['quick'] = ['✉️ Nous écrire'];
            break;

        case 'safety':
            $r['text'] = '⛑️ Un dispositif de sécurité et de premiers secours est prévu le jour de l\'événement. '
                . 'Sur place, signalez-vous aux bénévoles ou à l\'accueil du village.<br>'
                . 'Une situation particulière (condition médicale…) ? Écrivez-nous, nous prendrons les dispositions nécessaires.';
            $r['quick'] = ['✉️ Nous écrire'];
            break;

        case 'lost_found':
            $r['text'] = '🧢 Objet perdu ? Écrivez-nous en décrivant l\'objet et l\'endroit où vous pensez l\'avoir laissé : '
                . 'nous vérifions les objets retrouvés et revenons vers vous.';
            $r['quick'] = ['✉️ Nous écrire'];
            break;

        case 'faq_page':
            $r['text'] = '❓ Toutes les réponses aux questions fréquentes sont réunies sur <a href="faq">notre page FAQ</a> '
                . '(avec une recherche intégrée).<br>Et vous pouvez aussi me poser votre question directement ici ! 😊';
            break;

        case 'social': {
            $links = [];
            if (!empty($set['link_facebook']))  $links[] = '<a href="' . htmlspecialchars($set['link_facebook'])  . '" target="_blank" rel="noopener">Facebook</a>';
            if (!empty($set['link_instagram'])) $links[] = '<a href="' . htmlspecialchars($set['link_instagram']) . '" target="_blank" rel="noopener">Instagram</a>';
            if (!empty($set['link_twitter']))   $links[] = '<a href="' . htmlspecialchars($set['link_twitter'])   . '" target="_blank" rel="noopener">Twitter/X</a>';
            if (!empty($set['link_youtube']))   $links[] = '<a href="' . htmlspecialchars($set['link_youtube'])   . '" target="_blank" rel="noopener">YouTube</a>';
            if ($links) {
                $r['text'] = '📱 Suivez-nous sur ' . implode(' · ', $links) . ' — et abonnez-vous à la <a href="newsletter">newsletter</a> pour ne rien manquer !';
            } else {
                $r['text'] = '📱 Retrouvez tous nos liens en bas de page, et abonnez-vous à la <a href="newsletter">newsletter</a> pour ne rien manquer !';
            }
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
            $r['text'] = '📸 Retrouvez toutes les photos et albums des éditions précédentes sur <a href="photos">la page Photos</a> (classés par année) !';
            break;

        case 'partners':
            $r['text'] = '🤝 Découvrez tous nos partenaires sur <a href="partenaires">la page Partenaires</a>.<br>'
                . 'Vous souhaitez rejoindre l\'aventure (sponsoring, mécénat, lot) ? Écrivez-nous, nous vous enverrons les modalités !';
            $r['quick'] = ['✉️ Nous écrire'];
            break;

        case 'donation': {
            $txt = '🎀 Merci de vouloir soutenir la cause ! Les bénéfices de la course sont reversés à la lutte contre le cancer.';
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
            $r['text'] = 'Hmm, je ne suis pas sûre d\'avoir compris. 🤔<br>'
                . 'Jetez un œil à notre <a href="faq">FAQ</a>, choisissez un sujet ci-dessous, '
                . 'ou laissez-nous directement un message :';
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
            . 'Vérifiez l\'orthographe de l\'e-mail, ou <a href="register">inscrivez-vous en 5 minutes</a> !<br>'
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

/* ═══════════════════════ FAQ gérée depuis l'admin ═══════════════════════ */

/**
 * Cherche la meilleure entrée FAQ pour un message normalisé.
 * Score : mots-clés admin (poids 2, séparés par virgules) + mots significatifs
 * de la question (poids 1). Seuil : au moins un mot-clé, ou deux mots de la
 * question — sinon null (le fallback classique s'applique).
 */
function chatbot_faq_match(PDO $pdo, string $norm): ?array
{
    try {
        $faqs = $pdo->query('SELECT id, question, answer, keywords FROM chatbot_faq WHERE active = 1 ORDER BY position, id')
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null; // table absente avant migration
    }
    if (!$faqs) return null;

    $stop = ['les','des','une','pour','avec','dans','est','sont','que','qui','quoi','comment',
             'pourquoi','quand','peut','peux','vous','nous','mon','mes','votre','vos','sur',
             'pas','par','aux','ces','cette','ils','elle','elles','moi','ont','avoir','etre',
             'faire','faut','fait','bien','tout','tous','plus'];
    $msgWords = array_diff(array_filter(explode(' ', $norm), fn($w) => mb_strlen($w) >= 3), $stop);
    if (!$msgWords) return null;

    $best = null; $bestScore = 0;
    foreach ($faqs as $faq) {
        $score = 0; $kwHit = 0; $qHit = 0;
        foreach (array_filter(array_map('chatbot_normalize', explode(',', (string)$faq['keywords']))) as $kw) {
            // Mot-clé = expression complète (peut contenir plusieurs mots).
            // Tolérance de 2 caractères en fin de mot : « toutou » matche
            // « toutous », « rembourse » matche « remboursez/rembourser »…
            if ($kw !== '' && preg_match('/\b' . preg_quote($kw, '/') . '\w{0,2}\b/u', $norm)) { $kwHit++; $score += 2; }
        }
        $qWords = array_diff(array_filter(explode(' ', chatbot_normalize($faq['question'])), fn($w) => mb_strlen($w) >= 4), $stop);
        foreach ($qWords as $w) {
            if (in_array($w, $msgWords, true)) { $qHit++; $score += 1; }
        }
        if (($kwHit >= 1 || $qHit >= 2) && $score > $bestScore) { $bestScore = $score; $best = $faq; }
    }
    return $best;
}

/** Construit la réponse chatbot d'une entrée FAQ (texte libre → échappé). */
function chatbot_faq_reply(array $faq): array
{
    return [
        'text' => '💡 <strong>' . htmlspecialchars($faq['question']) . '</strong><br>' . chatbot_linkify($faq['answer'])
            . '<br><small>D\'autres réponses dans notre <a href="faq">FAQ</a>.</small>',
        'quick' => ['✉️ Nous écrire'],
        'action' => null,
    ];
}

/* ═══════════ Renvoi du mail de confirmation / QR code ═══════════ */

/**
 * Retrouve les inscriptions liées à un e-mail avec leur numéro et l'éligibilité
 * t-shirt (mêmes règles de quota que chatbot_email_lookup). Le QR n'est JAMAIS
 * montré dans le chat : il est renvoyé par mail à l'adresse de l'inscrit.
 */
function chatbot_qr_lookup(PDO $pdo, string $email): array
{
    $set = $pdo->query('SELECT qrcode_mail_mode, qrcode_mail_limit FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $limit = (($set['qrcode_mail_mode'] ?? '') === 'first_x' && (int)($set['qrcode_mail_limit'] ?? 0) > 0)
        ? (int)$set['qrcode_mail_limit'] : 0;

    $rows = $pdo->query('SELECT id, inscription_no, nom, prenom, email, montant_du, created_at FROM registrations')->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, function ($a, $b) {
        $c = strcmp((string)$a['created_at'], (string)$b['created_at']);
        return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
    });

    $needle = mb_strtolower(trim($email), 'UTF-8');
    $matches = []; $paidRank = 0;
    foreach ($rows as $row) {
        $isPaid = (float)$row['montant_du'] > 0;
        if ($isPaid) $paidRank++;
        $dec = mb_strtolower((string)@decrypt($row['email']), 'UTF-8');
        $raw = mb_strtolower((string)$row['email'], 'UTF-8');
        if ($dec === $needle || $raw === $needle) {
            $nom    = (string)(@decrypt($row['nom'])    ?: $row['nom']);
            $prenom = (string)(@decrypt($row['prenom']) ?: $row['prenom']);
            $matches[] = [
                'no'       => (string)$row['inscription_no'],
                'nom'      => $nom,
                'prenom'   => $prenom,
                'paid'     => $isPaid,
                'eligible' => $isPaid && ($limit === 0 || $paidRank <= $limit),
            ];
        }
    }
    return $matches;
}
