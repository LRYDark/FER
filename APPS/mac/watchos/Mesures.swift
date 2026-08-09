//
//  Mesures.swift — Profil physique et estimation des calories, côté montre.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  ⚠️ PORTAGE FIDÈLE DE bibliotheque/fer_shared/lib/src/course/mesures.dart
//
//  Les mêmes équations, les mêmes bornes, les mêmes garde-fous. Un écart
//  produirait deux chiffres différents pour la même course selon qu'on regarde
//  son poignet ou son téléphone — et personne ne saurait lequel croire.
//
//  Toute correction ici doit être reportée là-bas, et réciproquement.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  ⚠️ LE POIDS ET LA TAILLE NE SONT JAMAIS ENVOYÉS AU SERVEUR
//
//  C'est une règle du projet, pas une commodité. Ils arrivent du téléphone par
//  WatchConnectivity et ne vont nulle part ailleurs. C'est aussi pourquoi ce
//  calcul existe en double : l'API ne peut pas le faire à notre place.
//

import Foundation

/// Poids, taille, âge, sexe. Aucun n'est obligatoire, mais sans le poids il n'y
/// a pas d'estimation du tout.
struct ProfilMontre {
    var poidsKg: Double?
    var tailleCm: Int?
    var age: Int?
    /// « H », « F », ou `nil`. N'entre pas dans les équations ACSM.
    var sexe: String?

    init(poidsKg: Double? = nil, tailleCm: Int? = nil,
         age: Int? = nil, sexe: String? = nil) {
        self.poidsKg = poidsKg
        self.tailleCm = tailleCm
        self.age = age
        self.sexe = sexe
    }

    /// Reconstruit depuis le dictionnaire rangé dans les préférences, ou depuis
    /// le contexte reçu du téléphone : les deux ont les mêmes clés.
    /// ⚠️ ON PASSE PAR `NSNumber`, ET NON PAR `as? Double` DIRECTEMENT.
    /// Un poids rond saisi « 78 » peut traverser le pont ou les préférences
    /// sous forme d'entier ; le cast strict échouerait alors en silence, et la
    /// montre annoncerait « poids non renseigné » à quelqu'un qui l'a bel et
    /// bien renseigné. Le seul symptôme serait des calories absentes.
    init(depuis d: [String: Any]?) {
        self.init(
            poidsKg: (d?["poids_kg"] as? NSNumber)?.doubleValue,
            tailleCm: (d?["taille_cm"] as? NSNumber)?.intValue,
            age: (d?["age"] as? NSNumber)?.intValue,
            sexe: d?["sexe"] as? String
        )
    }

    var dictionnaire: [String: Any] {
        var d: [String: Any] = [:]
        if let poidsKg { d["poids_kg"] = poidsKg }
        if let tailleCm { d["taille_cm"] = tailleCm }
        if let age { d["age"] = age }
        if let sexe { d["sexe"] = sexe }
        return d
    }

    /// ⚠️ MÊMES BORNES QUE LE DART. Hors de 20–300 kg, c'est une faute de
    /// frappe, et l'estimation serait absurde.
    var utilisable: Bool {
        guard let p = poidsKg else { return false }
        return p > 20 && p < 300
    }

    /// Assez complet pour affiner le métabolisme de repos ?
    var affine: Bool {
        utilisable && tailleCm != nil && age != nil && sexe != nil
    }
}

/// Estimation de la dépense énergétique, par l'équation ACSM.
///
/// ⚠️ SUR LA MONTRE, ON N'A QU'UN SEUL SEGMENT — le résultat final du serveur
/// (distance, temps, dénivelé). Le téléphone, lui, accumule segment par segment
/// pendant la course, ce qui est plus juste sur un parcours vallonné. Les deux
/// chiffres peuvent donc différer légèrement : celui de la montre est calculé
/// sur la moyenne, et sous-estime plutôt qu'il ne surestime.
struct Calories {
    let profil: ProfilMontre

    var disponible: Bool { profil.utilisable }

    /// Consommation de repos, en mL d'oxygène par kg et par minute.
    ///
    /// C'est ici, et seulement ici, que la taille et l'âge servent. L'ACSM pose
    /// un terme fixe de 3,5 mL/kg/min — la moyenne d'un homme de 40 ans, 70 kg,
    /// 1,75 m. Mifflin-St Jeor donne un métabolisme individuel, ramené à la même
    /// unité. On passe d'environ ±20 % à ±15 %, pas à une mesure.
    private var vo2Repos: Double {
        guard profil.affine,
              let poids = profil.poidsKg,
              let taille = profil.tailleCm,
              let age = profil.age else { return 3.5 }

        let terme: Double
        switch profil.sexe {
        case "H": terme = 5
        case "F": terme = -161
        default:  terme = -78
        }
        // Mifflin-St Jeor, en kcal/jour.
        let base = 10 * poids + 6.25 * Double(taille) - 5 * Double(age) + terme

        // kcal/jour → mL O₂/kg/min : ÷1440 min, ÷5 kcal par litre, ×1000 mL,
        // ÷ le poids pour revenir à l'unité de l'équation.
        let vo2 = base / 1440 / 5 * 1000 / poids

        // ⚠️ BORNÉ. Une saisie aberrante — 30 kg pour 190 cm — sortirait la
        // formule de son domaine et fausserait tout le total.
        return min(max(vo2, 2.5), 5.0)
    }

    /// Total estimé, en kilocalories, ou `nil` si le poids manque.
    ///
    /// ⚠️ ON N'INVENTE PAS UN POIDS MOYEN pour afficher un chiffre : sans poids,
    /// l'estimation serait celle de quelqu'un d'autre.
    func total(distanceM: Double, secondes: Double, deniveleM: Double) -> Int? {
        guard let poids = profil.poidsKg, disponible,
              secondes > 0, distanceM > 0 else { return nil }

        // Vitesse en mètres par minute — l'unité des équations ACSM.
        let vitesse = distanceM / (secondes / 60)

        // ⚠️ GARDE-FOU : au-delà de 400 m/min (24 km/h), c'est un saut GPS, pas
        // un marcheur. Sans ce filtre, une aberration ajouterait des centaines
        // de calories imaginaires.
        guard vitesse <= 400 else { return nil }

        // Pente, bornée à ±30 % : au-delà, l'équation sort de son domaine.
        let pente = min(max(deniveleM / distanceM, -0.30), 0.30)
        let montee = max(0, pente)

        // La bascule à 100 m/min (6 km/h) est celle de la littérature : au-delà,
        // la marche devient moins économique que la course.
        let vo2 = vitesse <= 100
            ? 0.1 * vitesse + 1.8 * vitesse * montee + vo2Repos
            : 0.2 * vitesse + 0.9 * vitesse * montee + vo2Repos

        // VO2 (mL/kg/min) → kcal : 5 kcal par litre d'oxygène consommé.
        let kcalParMin = vo2 * poids / 1000 * 5
        return Int((kcalParMin * (secondes / 60)).rounded())
    }
}

/// Un résultat terminé, tel que le serveur le renvoie sur `/me/results`.
///
/// ⚠️ LE TEMPS VIENT DU SERVEUR, PAS DE LA MONTRE. Il est arbitré entre la
/// balise et le GPS. On ne le recalcule pas à partir des horodatages, sous
/// aucun prétexte.
struct ResultatMontre {
    let annee: Int
    let tempsS: Int
    let distanceM: Int?
    let denivelePositifM: Int?

    init?(depuis d: [String: Any]) {
        guard let annee = d["annee"] as? Int,
              d["statut"] as? String == "termine",
              let temps = d["temps_s"] as? Int, temps > 0 else { return nil }
        self.annee = annee
        self.tempsS = temps
        self.distanceM = d["distance_m"] as? Int
        self.denivelePositifM = d["denivele_positif_m"] as? Int
    }

    /// « 1 h 08 » ou « 48 min » — sur 40 mm, les secondes du chrono officiel
    /// tiennent dans la ligne du dessous, pas dans le titre.
    var chronoCourt: String {
        let h = tempsS / 3600, m = (tempsS % 3600) / 60, s = tempsS % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, s)
                     : String(format: "%d:%02d", m, s)
    }

    var distanceKm: Double? {
        guard let d = distanceM, d > 0 else { return nil }
        return Double(d) / 1000
    }

    /// Allure en minutes et secondes par kilomètre.
    var allure: String? {
        guard let km = distanceKm, km > 0 else { return nil }
        let sParKm = Double(tempsS) / km
        return String(format: "%d:%02d", Int(sParKm) / 60, Int(sParKm) % 60)
    }

    /// Le profil est passé en paramètre, jamais rangé dans le résultat : il
    /// vit dans la session, et une copie figée ici afficherait l'ancien poids
    /// après une correction.
    func calories(profil: ProfilMontre) -> Int? {
        // Sans distance mesurée, l'équation n'a pas d'entrée.
        guard let d = distanceM, d > 0 else { return nil }
        return Calories(profil: profil).total(
            distanceM: Double(d),
            secondes: Double(tempsS),
            deniveleM: Double(denivelePositifM ?? 0)
        )
    }
}

/* ════════════════════════════ Messages ════════════════════════════════ */

/// Une annonce de l'organisation, telle que `/me/notifications` la renvoie.
///
/// ⚠️ MÊME SOURCE QUE LE TÉLÉPHONE ET LE SITE. Le serveur a déjà retiré les
/// messages masqués et les expirés : on ne refiltre rien ici, sous peine de
/// voir la montre dire autre chose que l'onglet « Messages » du téléphone.
struct MessageMontre: Identifiable {
    let id: Int
    let titre: String
    let corps: String
    /// Reste en tête de liste au lieu de défiler. Porte ce qu'on relit la
    /// veille : rendez-vous, parking, horaires.
    let epingle: Bool
    let publieLe: Date?

    init?(depuis d: [String: Any]) {
        guard let id = d["id"] as? Int,
              let titre = d["titre"] as? String else { return nil }
        self.id = id
        self.titre = titre
        self.corps = d["message"] as? String ?? ""
        self.epingle = d["epingle"] as? Bool == true
        if let iso = d["publie_le"] as? String {
            self.publieLe = ISO8601DateFormatter().date(from: iso)
        } else {
            self.publieLe = nil
        }
    }
}
