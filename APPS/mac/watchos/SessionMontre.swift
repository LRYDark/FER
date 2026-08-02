//
//  SessionMontre.swift — État et appels API de l'application Apple Watch.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  MÊME CONTRAT QUE LE CLIENT DART
//
//  Routes, en-têtes et enveloppe sont identiques à
//  ../../shared/lib/src/api/api_client.dart :
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
    private let baseUrl = "https://jr.zerobug-57.fr/FER/api/v1"

    /// ⚠️ ALIGNÉE SUR LES pubspec.yaml DES DEUX COQUES. Le serveur compare une
    /// seule `app_version_minimale` : un numéro plus bas ici ferait refuser la
    /// montre alors que le téléphone passe, et personne ne comprendrait.
    private let version = "1.0.0"

    @Published var jetonAppareil: String?
    @Published var jetonAcces: String?
    @Published var dossard: String?
    @Published var annee: Int?
    @Published var heureDepart: Date?
    @Published var enCourse = false
    @Published var message: String?

    /// Détections qui n'ont pas pu partir. Écrites sur le disque AVANT l'envoi :
    /// une arrivée qui n'existe que dans une requête HTTP échouée est perdue,
    /// et quelqu'un franchit la ligne sans chrono.
    private var fileAttente: [[String: Any]] = []

    private let cleJeton = "fer_device_token"
    private let cleFile = "fer_file_detections"

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
        guard jetonAppareil != nil else { return }
        await rafraichirJeton()
        await chargerCourse()
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
            }
            if let insc = try await requete("me/registrations")
                as? [[String: Any]] {
                // Le dossard de l'édition en cours, s'il existe.
                dossard = insc.first { $0["annee"] as? Int == annee }?["inscription_no"] as? String
            }
        } catch {
            message = "Informations de course indisponibles."
        }
    }

    // MARK: - Passage de ligne

    /// Déclare un passage de ligne.
    ///
    /// ⚠️ LE TYPE ENVOYÉ EST `geofence`, PAS `manuel`. Le serveur réserve
    /// `manuel` à l'organisation et le refuse en 403 : il prime sur toutes les
    /// autres sources, et un coureur qui pourrait l'émettre dicterait son temps.
    /// `geofence` porte la bonne idée — « je déclare être passé là, à cet
    /// instant » — avec la confiance modérée qui va avec.
    func declarerPassage(_ point: String) async {
        guard let dossard, let annee else { return }
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
            "annee": annee, "no": dossard, "detection": detection
        ])
        UserDefaults.standard.set(fileAttente, forKey: cleFile)

        if point == "depart" { enCourse = true }
        if point == "arrivee" { enCourse = false }

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

/// La montre ne demande jamais l'adresse email : le jeton d'appareil lui est
/// transmis par l'application iPhone via WatchConnectivity.
///
/// ⚠️ CÔTÉ iPHONE, il reste à envoyer ce jeton. C'est le seul point que la
/// coque Flutter doit ajouter (canal de plateforme vers `WCSession`) — voir
/// README/03-apple-watch.md.
extension SessionMontre: WCSessionDelegate {
    nonisolated func session(_ session: WCSession,
                             activationDidCompleteWith state: WCSessionActivationState,
                             error: Error?) {}

    nonisolated func session(_ session: WCSession,
                             didReceiveApplicationContext contexte: [String: Any]) {
        guard let jeton = contexte["device_token"] as? String else { return }
        Task { @MainActor in
            self.jetonAppareil = jeton
            UserDefaults.standard.set(jeton, forKey: self.cleJeton)
            await self.rafraichirJeton()
            await self.chargerCourse()
        }
    }
}
