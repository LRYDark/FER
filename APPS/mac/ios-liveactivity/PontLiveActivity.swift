//
//  PontLiveActivity.swift — Le canal entre Dart et ActivityKit.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  À AJOUTER DANS LA CIBLE **Runner**, pas dans l'extension widget.
//
//  L'extension DESSINE la Live Activity ; c'est l'application principale qui la
//  DÉMARRE et qui l'ARRÊTE. Placer ce fichier dans l'extension donnerait une
//  compilation propre et un canal que Dart n'atteindrait jamais.
//
//  Branchement, dans `AppDelegate.swift` :
//
//      let controller = window?.rootViewController as! FlutterViewController
//      PontLiveActivity.enregistrer(messager: controller.binaryMessenger)
//

import ActivityKit
import Flutter
import Foundation

@available(iOS 16.1, *)
enum PontLiveActivity {

    private static var activite: Activity<FERActivityAttributes>?

    static func enregistrer(messager: FlutterBinaryMessenger) {
        let canal = FlutterMethodChannel(
            name: "fr.forbachenrose/liveactivity",
            binaryMessenger: messager
        )

        canal.setMethodCallHandler { appel, resultat in
            switch appel.method {

            case "demarrer":
                guard let args = appel.arguments as? [String: Any],
                      let depart = args["depart"] as? Double,
                      let dossard = args["dossard"] as? String else {
                    resultat(FlutterError(code: "arguments",
                                          message: "depart et dossard requis",
                                          details: nil))
                    return
                }
                demarrer(depart: Date(timeIntervalSince1970: depart),
                         dossard: dossard,
                         resultat: resultat)

            case "majDistance":
                let m = (appel.arguments as? [String: Any])?["distance"] as? Double ?? 0
                Task { await majDistance(m) }
                resultat(nil)

            case "arreter":
                Task { await arreter() }
                resultat(nil)

            default:
                resultat(FlutterMethodNotImplemented)
            }
        }
    }

    private static func demarrer(depart: Date, dossard: String,
                                 resultat: @escaping FlutterResult) {
        // ⚠️ L'AUTORISATION EST UN RÉGLAGE SYSTÈME, pas une permission qu'on
        // demande. Si la personne a désactivé les Live Activities pour cette
        // application, on ne peut rien faire — et il ne faut pas faire échouer
        // le suivi pour autant : le chrono existe toujours dans l'application.
        guard ActivityAuthorizationInfo().areActivitiesEnabled else {
            resultat(FlutterError(code: "desactive",
                                  message: "Les activités en direct sont désactivées.",
                                  details: nil))
            return
        }

        // Une seule à la fois : redémarrer un suivi ne doit pas empiler deux
        // chronos sur l'écran verrouillé.
        Task { await arreter() }

        do {
            let attributs = FERActivityAttributes(depart: depart, dossard: dossard)
            let etat = FERActivityAttributes.ContentState(distanceM: 0, arrive: false)
            activite = try Activity.request(
                attributes: attributs,
                content: .init(state: etat, staleDate: nil),
                pushType: nil   // Mise à jour locale : aucun serveur à impliquer.
            )
            resultat(nil)
        } catch {
            resultat(FlutterError(code: "echec",
                                  message: error.localizedDescription,
                                  details: nil))
        }
    }

    /// La distance, et elle seule, est poussée de temps en temps.
    ///
    /// ⚠️ PAS PLUS D'UNE FOIS PAR MINUTE côté appelant : iOS limite le nombre
    /// de mises à jour d'une Live Activity, et les dépasser la fige jusqu'à la
    /// fin. Le CHRONO, lui, n'a jamais besoin d'être mis à jour — il est animé
    /// par le système depuis la date de départ.
    private static func majDistance(_ metres: Double) async {
        guard let a = activite else { return }
        await a.update(.init(
            state: .init(distanceM: metres, arrive: false),
            staleDate: nil
        ))
    }

    private static func arreter() async {
        guard let a = activite else { return }
        // `.immediate` : la course est finie, la bannière n'a plus rien à dire.
        // La laisser s'éteindre toute seule la garderait des heures à l'écran.
        await a.end(nil, dismissalPolicy: .immediate)
        activite = nil
    }
}
