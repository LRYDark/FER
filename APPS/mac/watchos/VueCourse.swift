//
//  VueCourse.swift — Les écrans de course sur Apple Watch.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  UN ÉCRAN DE 40 mm SE LIT EN COURANT, D'UN COUP D'ŒIL.
//
//  D'où les règles tenues ici :
//    • Une seule idée par page. On glisse latéralement pour changer de sujet,
//      on ne fait jamais défiler une page qui mélange tout.
//    • Le chrono en chiffres à chasse fixe. Sans elle, le compteur change de
//      largeur à chaque seconde et l'œil suit un texte qui bouge.
//    • Les boutons de passage de ligne font toute la largeur : on les touche en
//      marchant, avec un doigt qui tremble.
//    • Pas d'inscriptions, pas de transferts, pas de réglages — ils sont sur le
//      téléphone, où l'on voit ce qu'on fait.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  ⚠️ LES PAGES SONT CONDITIONNELLES, ET L'ORDRE EST FIXE
//
//      [ QR ]  ‹—›  [ Course ]  ‹—›  [ Résultat ]  ‹—›  [ Messages ]
//
//  Chaque page n'existe que si elle a quelque chose à dire : le QR si
//  l'organisation en distribue pour cette édition, le résultat si une course est
//  terminée, les messages s'il y en a. Une page vide qui dirait « rien ici »
//  coûterait un glissement pour rien, plusieurs fois par jour.
//
//  Le QR est seul à gauche : c'est la page du stand des t-shirts, on la sort une
//  fois. À droite viennent les pages qu'on lit — le résultat, puis les annonces.
//
//  La course reste TOUJOURS la page ouverte au lancement, quel que soit le
//  nombre de pages présentes. Sur la ligne de départ, on lève le poignet et le
//  bouton est là — pas à un glissement de distance.
//

import SwiftUI

struct VuePrincipale: View {
    @EnvironmentObject private var session: SessionMontre

    /// Les pages présentes, dans l'ordre.
    ///
    /// ⚠️ UNE LISTE EXPLICITE, ET NON DES `if` DANS LE `TabView`. Des branches
    /// conditionnelles produisent une structure de vue différente à chaque
    /// combinaison, et le `TabView` y perd la correspondance entre sa sélection
    /// et ses pages.
    private var onglets: [Onglet] {
        var l: [Onglet] = []
        if session.qrPng != nil { l.append(.qr) }
        l.append(.course)
        if session.resultat != nil { l.append(.resultat) }
        if !session.messages.isEmpty { l.append(.messages) }
        return l
    }

    var body: some View {
        // ⚠️ ON N'OUVRE LE `TabView` QU'UNE FOIS TOUT CHARGÉ, ET C'EST LE
        // CORRECTIF.
        //
        // Les pages arrivent les unes après les autres, au rythme des réponses
        // de l'API. Tant que le `TabView` était construit dès le premier
        // affichage puis complété, il ouvrait la dernière page apparue — le QR,
        // puis le résultat, puis les messages — alors que la sélection valait
        // « Course ». Ni un `onChange` ni un `.id()` ne rattrapaient cela : la
        // page affichée était déjà décidée quand ils s'exécutaient.
        //
        // Pendant le chargement, on montre la page de course SEULE. Elle est
        // pleinement utilisable : le jour J, « Je pars » doit répondre au
        // premier coup d'œil, pas après une roue qui tourne.
        if session.chargementTermine {
            Onglets(liste: onglets).id(onglets)
        } else {
            VueCourse()
        }
    }
}

enum Onglet: Hashable { case qr, messages, course, resultat }

/// Le `TabView` proprement dit.
///
/// Il vit dans une vue à part pour une seule raison : c'est ici que réside la
/// sélection, et c'est donc ici que `.id()` doit pouvoir la réinitialiser.
struct Onglets: View {
    let liste: [Onglet]

    /// ⚠️ LA COURSE EST TOUJOURS LA PAGE OUVERTE. Sur la ligne de départ, on
    /// lève le poignet et le bouton est là — pas à un glissement de distance.
    @State private var page = Onglet.course

    var body: some View {
        TabView(selection: $page) {
            ForEach(liste, id: \.self) { onglet in
                vue(pour: onglet).tag(onglet)
            }
        }
        // `.page` et non `.verticalPage` : on glisse À GAUCHE ET À DROITE,
        // la couronne restant libre pour faire défiler la page en cours.
        .tabViewStyle(.page)
    }

    @ViewBuilder
    private func vue(pour onglet: Onglet) -> some View {
        switch onglet {
        case .qr:       VueQr()
        case .messages: VueMessages()
        case .course:   VueCourse()
        case .resultat: VueResultat()
        }
    }
}


/* ═══════════════════════════════ Course ═══════════════════════════════ */

struct VueCourse: View {
    @EnvironmentObject private var session: SessionMontre

    /// Rafraîchit l'affichage chaque seconde. Personne ne lit les dixièmes, et
    /// plus rapide ne ferait que consommer de la batterie un jour où elle doit
    /// tenir toute la matinée.
    @State private var horloge = Timer.publish(every: 1, on: .main, in: .common)
        .autoconnect()
    @State private var maintenant = Date()

    var body: some View {
        ScrollView {
            VStack(spacing: 10) {

                // ── Chrono, ou date tant qu'il n'y a rien à compter ───────
                //
                // ⚠️ SANS HEURE DE DÉPART PUBLIÉE, ON N'AFFICHE PAS « --:-- ».
                // L'organisation ne publie cette heure que le jour J : le reste
                // de l'année, la montre ne montrait qu'un tiret, et l'écran ne
                // servait à rien. C'est la même distinction que sur le
                // téléphone — à venir, puis en cours.
                if let depart = session.heureDepart, maintenant >= depart {
                    Text(chronoFormate)
                        .font(.system(size: 34, weight: .semibold, design: .rounded))
                        .monospacedDigit()
                        .minimumScaleFactor(0.6)
                        .lineLimit(1)

                    // ⚠️ LA MISE EN GARDE ACCOMPAGNE LE COMPTEUR. Sans elle, ce
                    // chiffre serait pris pour le temps officiel — et la
                    // première contestation serait indéfendable.
                    Text("indicatif")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                } else {
                    Text(dateFormatee)
                        .font(.system(size: 22, weight: .semibold, design: .rounded))
                        .minimumScaleFactor(0.6)
                        .lineLimit(2)
                        .multilineTextAlignment(.center)
                        .fixedSize(horizontal: false, vertical: true)

                    Text(session.heureDepart == nil
                         ? "départ non publié"
                         : "départ à venir")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }

                // ⚠️ PAS DE NUMÉRO D'INSCRIPTION ICI. Il ne sert à rien pendant
                // la marche : personne ne le récite, et c'est le QR — page de
                // gauche — qui identifie au stand des t-shirts. Il y figure,
                // sous le code, là où il a un usage.

                Divider()

                // ── Passages de ligne ─────────────────────────────────────
                Button {
                    Task { await session.declarerPassage("depart") }
                } label: {
                    Label("Je pars", systemImage: "flag")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .disabled(session.enCourse || session.numero == nil)

                Button {
                    Task { await session.declarerPassage("arrivee") }
                } label: {
                    Label("J'arrive", systemImage: "flag.checkered")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .tint(.pink)
                .disabled(session.numero == nil)

                // ── File d'attente ────────────────────────────────────────
                // Affichée EXPRÈS : voir « 3 en attente » pendant une coupure,
                // c'est comprendre que rien n'est perdu. L'absence de retour
                // ressemblerait à une panne.
                if session.enAttente > 0 {
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.up.circle")
                        Text("\(session.enAttente) en attente")
                    }
                    .font(.caption2)
                    .foregroundStyle(.orange)
                }

                if let message = session.message {
                    Text(message)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .padding(.horizontal, 4)
            .padding(.top, 2)
        }
        .onReceive(horloge) { maintenant = $0 }
        .task {
            // Le retour du réseau n'est pas notifié ici : on retente à chaque
            // ouverture de l'écran, ce qui suffit sur une montre.
            await session.viderFile()
        }
    }

    /// Date de la course, en toutes lettres et sans l'année : sur 40 mm, chaque
    /// caractère se paie, et personne ne doute de l'année de sa propre course.
    private var dateFormatee: String {
        guard let jour = session.dateCourse else { return "Aucune course" }
        let f = DateFormatter()
        f.locale = Locale(identifier: "fr_FR")
        f.setLocalizedDateFormatFromTemplate("EEEE d MMMM")
        let s = f.string(from: jour)
        // ⚠️ PAS `.capitalized` : il met une majuscule à CHAQUE mot et produit
        // « Dimanche 5 Juillet ». En français, les noms de mois n'en prennent
        // pas. On ne relève que la première lettre.
        return s.prefix(1).uppercased() + s.dropFirst()
    }

    private var chronoFormate: String {
        guard let t = session.chrono else { return "--:--" }
        let s = Int(t)
        return String(format: "%d:%02d:%02d", s / 3600, (s % 3600) / 60, s % 60)
    }
}

/* ═════════════════════════════ Messages ═══════════════════════════════ */

/// Les annonces de l'organisation, épinglées d'abord.
///
/// ⚠️ DIX AU MAXIMUM — la coupe est faite dans `SessionMontre.chargerMessages()`,
/// après le tri. Au-delà, on glisserait une minute sur un écran de montre pour
/// retrouver ce qu'on lit mieux sur le téléphone.
///
/// ⚠️ PAS DE SUPPRESSION ICI. Masquer un message est irréversible côté serveur
/// une fois confirmé, et une confirmation ne tient pas sur 40 mm — un doigt qui
/// dérape en marchant effacerait l'heure du rendez-vous. Cela se fait sur le
/// téléphone ou le site, où l'on voit ce qu'on fait.
struct VueMessages: View {
    @EnvironmentObject private var session: SessionMontre

    var body: some View {
        // ⚠️ `List` ET NON `ScrollView`, ET C'EST UN CORRECTIF.
        // Sans barre de navigation, un ScrollView commence au bord haut de
        // l'écran : le premier titre passait SOUS l'heure du système, illisible.
        // `List` réserve cet espace tout seul sur watchOS, et le rend au
        // défilement. C'est aussi lui qui donne le rebond attendu à la couronne.
        List {
            // ⚠️ CET EN-TÊTE N'EST PAS DÉCORATIF. L'heure du système est
            // dessinée en haut à droite, par-dessus tout : sans cette ligne,
            // le premier titre passait dessous. Elle libère la bande, et nomme
            // la page pour qui y arrive en glissant.
            ForEach(session.messages) { m in
                    VStack(alignment: .leading, spacing: 3) {
                        HStack(spacing: 4) {
                            // L'épingle AVANT le titre : c'est elle qui dit
                            // pourquoi ce message est en tête, et sans elle
                            // l'ordre paraîtrait arbitraire.
                            if m.epingle {
                                Image(systemName: "pin.fill")
                                    .font(.system(size: 9))
                                    .foregroundStyle(.pink)
                            }
                            Text(m.titre)
                                .font(.caption.weight(.semibold))
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        if !m.corps.isEmpty {
                            Text(m.corps)
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        if let d = m.publieLe {
                            Text(dateCourte(d))
                                .font(.system(size: 10))
                                .foregroundStyle(.tertiary)
                        }
                    }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.vertical, 2)
                // Fond transparent : les cellules grises de watchOS
                // découperaient la liste en pavés, alors qu'on veut lire des
                // annonces à la suite — comme sur le site, où la carte autour
                // de chaque message a justement été retirée.
                .listRowBackground(Color.clear)
            }
        }
        .listStyle(.plain)
        // ⚠️ NI EN-TÊTE « Messages » NI MARGE HAUTE SUPPLÉMENTAIRE.
        // watchOS réserve déjà une zone sûre en haut pour l'heure du système —
        // ce n'est pas une marge, et `contentMargins` ne l'entame pas. Y ajouter
        // un titre de page repoussait le premier message d'un tiers d'écran sur
        // un boîtier de 40 mm, pour nommer une page qu'on vient d'atteindre en
        // glissant et qu'on reconnaît à son contenu.
    }

    private func dateCourte(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "fr_FR")
        f.setLocalizedDateFormatFromTemplate("d MMM")
        return f.string(from: d)
    }
}

/* ═════════════════════════════════ QR ═════════════════════════════════ */

/// Le QR code, en grand et rien d'autre.
///
/// ⚠️ CETTE PAGE N'EXISTE QUE SI LE SERVEUR A RENVOYÉ UNE IMAGE. C'est
/// `fer_qrEligibilite()` qui tranche, côté serveur, et le site, le téléphone et
/// la montre lisent tous cette même règle. Une édition sans QR n'a donc pas
/// d'onglet — plutôt qu'un onglet qui s'excuse d'être vide.
struct VueQr: View {
    @EnvironmentObject private var session: SessionMontre

    var body: some View {
        VStack(spacing: 6) {
            if let png = session.qrPng, let image = UIImage(data: png) {
                // ⚠️ FOND BLANC OBLIGATOIRE. Le PNG a un fond transparent : sur
                // l'écran noir de la montre, les modules noirs disparaissent et
                // aucune douchette ne lit quoi que ce soit.
                Image(uiImage: image)
                    .interpolation(.none)   // pas de flou : c'est un code, pas une photo
                    .resizable()
                    .scaledToFit()
                    .padding(5)
                    .background(Color.white)
                    .clipShape(RoundedRectangle(cornerRadius: 6))
            }
            if let numero = session.numero {
                // ⚠️ « INSCRIPTION », PAS « DOSSARD » : il n'y a pas de dossard
                // à Forbach en Rose. C'est ici, sous le code, que le numéro sert
                // — quand la douchette ne veut rien savoir et qu'il faut le lire
                // à voix haute.
                Text("n° \(numero)")
                    .font(.caption.weight(.medium))
                    .foregroundStyle(.pink)
            }
        }
        .padding(.horizontal, 6)
    }
}

/* ══════════════════════════════ Résultat ══════════════════════════════ */

/// Le résultat de la dernière course terminée.
///
/// ⚠️ LE TEMPS VIENT DU SERVEUR. Il est arbitré entre la balise et le GPS ; on
/// ne le recalcule jamais à partir des horodatages.
struct VueResultat: View {
    @EnvironmentObject private var session: SessionMontre

    var body: some View {
        ScrollView {
            if let r = session.resultat {
                // ⚠️ EN-TÊTE COMPACT, ET C'EST UN CORRECTIF. Avec un chrono
                // de 30 pt, une ligne d'année et un séparateur, « Calories »
                // tombait sous le bord de l'écran sur un boîtier de 40 mm —
                // c'est-à-dire le chiffre qu'on venait chercher. Année collée
                // au chrono, pas de séparateur : les quatre mesures tiennent.
                VStack(spacing: 5) {
                    Text(r.chronoCourt)
                        .font(.system(size: 26, weight: .semibold, design: .rounded))
                        .monospacedDigit()
                        .minimumScaleFactor(0.6)
                        .lineLimit(1)
                        .padding(.bottom, -4)

                    Text(String(r.annee))
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .padding(.bottom, 2)

                    // Une ligne par mesure : deux colonnes seraient illisibles
                    // sur 40 mm, et c'est exactement ce qu'on avait corrigé sur
                    // le téléphone pour la même raison.
                    if let km = r.distanceKm {
                        Ligne("Distance", String(format: "%.2f km", km))
                    }
                    if let d = r.denivelePositifM {
                        Ligne("Dénivelé +", "\(d) m")
                    }
                    if let a = r.allure {
                        Ligne("Allure", "\(a) /km")
                    }
                    // ⚠️ LE TILDE EST LE DERNIER SIGNE QUI DIT « ESTIMATION ».
                    // La marge chiffrée a été retirée à la demande de
                    // l'organisation ; sans le tilde, ce nombre passerait pour
                    // une mesure, alors que l'équation ignore le terrain, le
                    // vent, la foulée et l'entraînement.
                    if let k = r.calories(profil: session.profil) {
                        Ligne("Calories", "~\(k) kcal")
                    } else {
                        // ⚠️ ON N'INVENTE PAS UN POIDS MOYEN. Sans poids,
                        // l'estimation serait celle de quelqu'un d'autre — on
                        // dit donc où aller le renseigner.
                        Text("Poids non renseigné sur le téléphone")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                            .fixedSize(horizontal: false, vertical: true)
                            .padding(.top, 2)
                    }
                }
                .padding(.horizontal, 4)
                .padding(.top, 2)
            }
        }
    }

    /// Libellé à gauche, valeur à droite — la seule disposition qui tienne sur
    /// une largeur de montre sans couper les mots.
    private struct Ligne: View {
        let titre: String
        let valeur: String
        init(_ titre: String, _ valeur: String) {
            self.titre = titre
            self.valeur = valeur
        }
        var body: some View {
            HStack {
                Text(titre)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer(minLength: 4)
                Text(valeur)
                    .font(.caption.weight(.medium))
                    .monospacedDigit()
                    .lineLimit(1)
                    .minimumScaleFactor(0.7)
            }
        }
    }
}
