//
//  SessionMontre.swift — État et appels API de l'application Apple Watch.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  MÊME CONTRAT QUE LE CLIENT DART
//
//  Routes, en-têtes et enveloppe sont identiques à
//  ../../bibliotheque/fer_shared/lib/src/api/api_client.dart :
//
//      { "ok": true,  "data": …,  "error": null }
//      { "ok": false, "data": null, "error": { "code": …, "message": … } }
//
//  Toute évolution de l'API doit être répercutée AUX DEUX ENDROITS. C'est le
//  prix de watchOS, que Flutter ne sait pas compiler.
//

import Foundation
import WatchConnectivity

@MainActor
final class SessionMontre: NSObject, ObservableObject {

    /// Adresse de l'API. Doit rester alignée sur celle des coques Flutter.
    private let baseUrl = "https://jr.zerobug-57.fr/FER/api/mobile"

    /// ⚠️ ALIGNÉE SUR LES pubspec.yaml DES DEUX COQUES. Le serveur compare une
    /// seule `app_version_minimale` : un numéro plus bas ici ferait refuser la
    /// montre alors que le téléphone passe, et personne ne comprendrait.
    private let version = "1.0.0"

    @Published var jetonAppareil: String?
    @Published var jetonAcces: String?
    /// Numéro d'inscription. ⚠️ PAS « dossard » : il n'y a pas de dossard à
    /// Forbach en Rose, et le mot a été retiré partout ailleurs. Le garder
    /// ici ferait dire à la montre autre chose qu'au téléphone et au site.
    @Published var numero: String?
    @Published var annee: Int?
    @Published var heureDepart: Date?

    /// Jour de la course. Affiché tant que l'heure de départ n'est pas publiée —
    /// c'est-à-dire toute l'année sauf le jour J.
    @Published var dateCourse: Date?

    /// ⚠️ RELU DU DISQUE AU DÉMARRAGE. Sans cela, une montre qui redémarre
    /// en pleine marche — batterie, plantage — repropose « Je pars » à
    /// quelqu'un déjà parti, et un second départ écraserait le premier.
    @Published var enCourse = false
    @Published var message: String?

    /// Résultat terminé le plus récent, s'il en existe un.
    @Published var resultat: ResultatMontre?

    /// Annonces de l'organisation, épinglées d'abord, plafonnées.
    @Published var messages: [MessageMontre] = []

    /// Image du QR code, ou `nil` si l'édition n'en distribue pas — le serveur
    /// répond alors 409 `qr_indisponible`. ⚠️ ON NE DEVINE PAS : c'est
    /// `fer_qrEligibilite()` côté serveur qui tranche, et le site, le téléphone
    /// et la montre lisent tous cette même règle. Un affichage local
    /// divergerait le jour où l'organisation change d'avis.
    @Published var qrPng: Data?

    /// Vrai quand le premier chargement complet est terminé.
    ///
    /// ⚠️ C'EST LUI QUI DÉCIDE DU MOMENT OÙ LES ONGLETS SE CONSTRUISENT.
    /// Voir `VuePrincipale` : un `TabView` complété page après page ouvre la
    /// dernière arrivée, pas celle qu'on a sélectionnée.
    @Published var chargementTermine = false

    /// Poids, taille, âge, sexe.
    ///
    /// ⚠️ NE VIENT PAS DU SERVEUR, ET N'Y RETOURNE JAMAIS. Le poids et la
    /// taille ne quittent pas la paire téléphone-montre : c'est une règle du
    /// projet. Ils arrivent par `WatchConnectivity` et sont rangés dans les
    /// préférences de la montre, comme sur le téléphone.
    @Published var profil = ProfilMontre()

    /// Détections qui n'ont pas pu partir. Écrites sur le disque AVANT l'envoi :
    /// une arrivée qui n'existe que dans une requête HTTP échouée est perdue,
    /// et quelqu'un franchit la ligne sans chrono.
    private var fileAttente: [[String: Any]] = []

    private let cleJeton = "fer_device_token"
    private let cleFile = "fer_file_detections"
    private let cleEnCourse = "fer_en_course"
    private let cleProfil = "fer_profil"

    override init() {
        super.init()
        if WCSession.isSupported() {
            WCSession.default.delegate = self
            WCSession.default.activate()
        }
    }

    func demarrer() async {
        jetonAppareil = UserDefaults.standard.string(forKey: cleJeton)
        fileAttente = (UserDefaults.standard.array(forKey: cleFile)
                        as? [[String: Any]]) ?? []
        enCourse = UserDefaults.standard.bool(forKey: cleEnCourse)
        profil = ProfilMontre(depuis: UserDefaults.standard.dictionary(forKey: cleProfil))
        guard jetonAppareil != nil else { return }
        await rafraichirJeton()
        await chargerCourse()
        await chargerResultat()
        await chargerQr()
        await chargerMessages()
        chargementTermine = true
        await viderFile()
    }

    /// Chrono affiché. CONFORT D'AFFICHAGE : le temps officiel vient du serveur,
    /// après arbitrage entre balise et GPS, et peut en différer.
    var chrono: TimeInterval? {
        guard let depart = heureDepart else { return nil }
        let ecoule = Date().timeIntervalSince(depart)
        return ecoule < 0 ? 0 : ecoule
    }

    // MARK: - Appels API

    private func requete(_ chemin: String,
                         methode: String = "GET",
                         corps: [String: Any]? = nil,
                         avecJeton: Bool = true) async throws -> Any {
        guard let url = URL(string: "\(baseUrl)/\(chemin)") else {
            throw ErreurApi.urlInvalide
        }
        var r = URLRequest(url: url)
        r.httpMethod = methode
        r.timeoutInterval = 20
        r.setValue("application/json", forHTTPHeaderField: "Accept")
        // Obligatoire partout sauf /app/config : absent, le serveur répond 400.
        r.setValue(version, forHTTPHeaderField: "X-App-Version")
        if let corps {
            r.setValue("application/json", forHTTPHeaderField: "Content-Type")
            r.httpBody = try JSONSerialization.data(withJSONObject: corps)
        }
        if avecJeton, let acces = jetonAcces {
            r.setValue("Bearer \(acces)", forHTTPHeaderField: "Authorization")
        }

        let (donnees, _) = try await URLSession.shared.data(for: r)
        guard let enveloppe = try JSONSerialization.jsonObject(with: donnees)
                as? [String: Any] else {
            throw ErreurApi.reponseIllisible
        }
        if enveloppe["ok"] as? Bool == true {
            return enveloppe["data"] ?? NSNull()
        }
        let err = enveloppe["error"] as? [String: Any] ?? [:]
        throw ErreurApi.serveur(
            code: err["code"] as? String ?? "inconnu",
            message: err["message"] as? String ?? "Erreur inconnue."
        )
    }

    /// Rachète un jeton d'accès à partir du jeton d'appareil.
    func rafraichirJeton() async {
        guard let appareil = jetonAppareil else { return }
        do {
            let d = try await requete("auth/refresh", methode: "POST",
                                      corps: ["device_token": appareil],
                                      avecJeton: false) as? [String: Any]
            jetonAcces = d?["access_token"] as? String
        } catch ErreurApi.serveur(let code, _) where code == "device_revoked" {
            // Appareil révoqué depuis le téléphone : on efface, sinon chaque
            // requête échouerait sans que personne comprenne pourquoi.
            jetonAppareil = nil
            jetonAcces = nil
            UserDefaults.standard.removeObject(forKey: cleJeton)
        } catch {
            // Réseau coupé : le jeton d'appareil reste valable. On ne déconnecte
            // surtout pas quelqu'un passé sous un tunnel en pleine course.
        }
    }

    func chargerCourse() async {
        do {
            if let c = try await requete("course", avecJeton: false)
                as? [String: Any] {
                annee = c["annee"] as? Int
                if let iso = c["heure_depart"] as? String {
                    heureDepart = ISO8601DateFormatter().date(from: iso)
                }
                // ⚠️ `date_course` est une DATE NUE (« 2026-07-05 »), pas un
                // horodatage : ISO8601DateFormatter la refuse. Il faut un
                // format explicite, et le fuseau local — la course a lieu là où
                // se trouve la montre, pas à Greenwich.
                if let jour = c["date_course"] as? String {
                    let f = DateFormatter()
                    f.locale = Locale(identifier: "en_US_POSIX")
                    f.dateFormat = "yyyy-MM-dd"
                    dateCourse = f.date(from: jour)
                }
            }
            if let insc = try await requete("me/registrations")
                as? [[String: Any]] {
                // Le numéro de l'édition en cours, s'il existe.
                numero = insc.first { $0["annee"] as? Int == annee }?["inscription_no"] as? String
            }
        } catch {
            message = "Informations de course indisponibles."
        }
    }

    /// Le résultat terminé le plus récent.
    ///
    /// ⚠️ ON FILTRE SUR `statut == "termine"`. Une édition en cours d'arbitrage
    /// porte `invalide` ou `en_cours` avec des horodatages incohérents — les
    /// afficher donnerait un temps négatif ou une allure absurde, et la
    /// personne croirait avoir raté sa course.
    func chargerResultat() async {
        do {
            guard let liste = try await requete("me/results") as? [[String: Any]]
            else { return }
            resultat = liste.compactMap(ResultatMontre.init(depuis:))
                            .max(by: { $0.annee < $1.annee })
        } catch {
            // Pas de résultat, c'est le cas le plus courant de l'année.
        }
    }

    /// Le QR code de l'inscription en cours, s'il est distribué.
    ///
    /// ⚠️ `qr_indisponible` (409) N'EST PAS UNE ERREUR. C'est la réponse
    /// normale quand l'organisation ne distribue pas de QR pour cette édition,
    /// ou que cette inscription n'y a pas droit. On efface alors l'image, et
    /// l'onglet disparaît — exactement comme sur le site et le téléphone.
    func chargerQr() async {
        guard let numero, let annee else { return }
        do {
            let d = try await requete("me/registrations/\(annee)/\(numero)/qrcode")
                as? [String: Any]
            if let b64 = d?["png_base64"] as? String {
                qrPng = Data(base64Encoded: b64)
            }
        } catch ErreurApi.serveur(let code, _) where code == "qr_indisponible" {
            qrPng = nil
        } catch {
            // Réseau : on garde l'image précédente s'il y en avait une. Le QR
            // sert au retrait du t-shirt, souvent là où le réseau est mauvais.
        }
    }

    /// Les annonces de l'organisation.
    ///
    /// ⚠️ ÉPINGLÉES D'ABORD, PUIS LES PLUS RÉCENTES, ET DIX AU MAXIMUM.
    /// C'est la limite voulue : au-delà, on glisse pendant une minute sur un
    /// écran de montre pour retrouver une information qu'on lit mieux sur le
    /// téléphone. Le tri est fait ici parce que le serveur renvoie l'ordre
    /// complet ; le trier après avoir coupé garderait dix messages au hasard,
    /// et l'épinglé du jour J pourrait en tomber.
    func chargerMessages() async {
        do {
            guard let liste = try await requete("me/notifications")
                    as? [[String: Any]] else { return }
            let tous = liste.compactMap(MessageMontre.init(depuis:))
            messages = Array(
                tous.sorted {
                    if $0.epingle != $1.epingle { return $0.epingle }
                    return ($0.publieLe ?? .distantPast) > ($1.publieLe ?? .distantPast)
                }.prefix(Self.maxMessages)
            )
        } catch {
            // Réseau : on garde la liste précédente. Un message lu hier vaut
            // mieux qu'un écran vide au moment où l'on cherche l'heure du
            // rendez-vous.
        }
    }

    /// ⚠️ DIX, PAS PLUS. Voir `chargerMessages()`.
    static let maxMessages = 10

    // MARK: - Passage de ligne

    /// Déclare un passage de ligne.
    ///
    /// ⚠️ LE TYPE ENVOYÉ EST `geofence`, PAS `manuel`. Le serveur réserve
    /// `manuel` à l'organisation et le refuse en 403 : il prime sur toutes les
    /// autres sources, et un coureur qui pourrait l'émettre dicterait son temps.
    /// `geofence` porte la bonne idée — « je déclare être passé là, à cet
    /// instant » — avec la confiance modérée qui va avec.
    func declarerPassage(_ point: String) async {
        guard let numero, let annee else { return }
        let detection: [String: Any] = [
            "type": "geofence",
            "point": point,
            // Décalage explicite EXIGÉ par le serveur : une date nue est
            // refusée en 422, et serait de toute façon lue dans le mauvais
            // fuseau.
            "detecte_at": ISO8601DateFormatter().string(from: Date())
        ]

        // Disque D'ABORD, envoi ensuite. L'inverse laisserait une fenêtre où la
        // détection n'existe nulle part si l'application est tuée.
        fileAttente.append([
            "annee": annee, "no": numero, "detection": detection
        ])
        UserDefaults.standard.set(fileAttente, forKey: cleFile)

        if point == "depart" { enCourse = true }
        if point == "arrivee" { enCourse = false }
        UserDefaults.standard.set(enCourse, forKey: cleEnCourse)

        await viderFile()
    }

    /// Envoie ce qui attend. Ne vide la file qu'APRÈS confirmation du serveur.
    /// Les envois sont idempotents côté serveur (index unique sur la détection)
    /// : dans le doute, on renvoie.
    func viderFile() async {
        guard !fileAttente.isEmpty else { return }
        if jetonAcces == nil { await rafraichirJeton() }
        guard jetonAcces != nil else { return }

        var restant: [[String: Any]] = []
        for lot in fileAttente {
            guard let annee = lot["annee"] as? Int,
                  let no = lot["no"] as? String,
                  let d = lot["detection"] as? [String: Any] else { continue }
            do {
                _ = try await requete("me/detections", methode: "POST", corps: [
                    "annee": annee, "inscription_no": no, "detections": [d]
                ])
            } catch ErreurApi.serveur(let code, _)
                        where code == "chrono_disabled" {
                // Refus durable : le garder ferait une file qui grossit sans fin
                // et repart à chaque reprise de réseau.
                continue
            } catch {
                restant.append(lot)   // On réessaiera : rien n'est perdu.
            }
        }
        fileAttente = restant
        UserDefaults.standard.set(fileAttente, forKey: cleFile)
    }

    var enAttente: Int { fileAttente.count }
}

enum ErreurApi: Error {
    case urlInvalide
    case reponseIllisible
    case serveur(code: String, message: String)
}

// MARK: - Reprise de la connexion depuis l'iPhone

/// La montre ne demande jamais l'adresse email, ni le poids : le téléphone lui
/// transmet les deux par WatchConnectivity.
///
/// Voir `bibliotheque/fer_shared/lib/src/pont_montre.dart` pour l'émetteur, et
/// `mac/ios/Runner/AppDelegate.swift` pour le canal.
extension SessionMontre: WCSessionDelegate {
    nonisolated func session(_ session: WCSession,
                             activationDidCompleteWith state: WCSessionActivationState,
                             error: Error?) {}

    /// ⚠️ LE CONTEXTE EST COMPLET À CHAQUE FOIS, IL NE SE FUSIONNE PAS.
    /// Une clé absente veut dire « efface-la » : c'est ainsi qu'une déconnexion
    /// ou un droit à l'effacement exercé sur le téléphone atteint la montre.
    /// Traiter l'absence comme « ne change rien » laisserait la montre
    /// connectée à un compte fermé.
    nonisolated func session(_ session: WCSession,
                             didReceiveApplicationContext contexte: [String: Any]) {
        Task { @MainActor in
            let jeton = contexte["device_token"] as? String
            self.jetonAppareil = jeton
            if let jeton {
                UserDefaults.standard.set(jeton, forKey: self.cleJeton)
            } else {
                UserDefaults.standard.removeObject(forKey: self.cleJeton)
                self.jetonAcces = nil
                self.resultat = nil
                self.qrPng = nil
                self.messages = []
                self.chargementTermine = false
            }

            self.profil = ProfilMontre(depuis: contexte)
            UserDefaults.standard.set(self.profil.dictionnaire, forKey: self.cleProfil)

            guard jeton != nil else { return }
            await self.rafraichirJeton()
            await self.chargerCourse()
            await self.chargerResultat()
            await self.chargerQr()
            await self.chargerMessages()
            self.chargementTermine = true
        }
    }
}
