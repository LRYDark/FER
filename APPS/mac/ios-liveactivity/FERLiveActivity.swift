//
//  FERLiveActivity.swift — L'écran verrouillé et la Dynamic Island.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  UN SEUL CODE POUR TOUS LES iPHONE
//
//  Dynamic Island sur les 14 Pro et plus, bandeau d'écran verrouillé sur les
//  autres : c'est le MÊME `ActivityConfiguration` qui produit les deux. Il n'y
//  a rien à détecter, et rien à écrire en double.
//
//  ⚠️ LE COMPTEUR N'EST PAS RAFRAÎCHI PAR L'APPLICATION.
//  `Text(timerInterval:)` reçoit un intervalle de dates et anime le chrono
//  lui-même, en continu, sans réveiller quoi que ce soit. C'est la seule
//  méthode qui tienne sur la durée d'une course : iOS limite le nombre de mises
//  à jour d'une Live Activity, et une mise à jour par seconde serait rejetée
//  bien avant l'arrivée.
//
//  À placer dans la cible Widget Extension. Voir README/06-chrono-vivant.md.
//

import ActivityKit
import SwiftUI
import WidgetKit

@available(iOS 16.1, *)
struct FERLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: FERActivityAttributes.self) { contexte in

            // ── Écran verrouillé ────────────────────────────────────────────
            HStack(spacing: 14) {
                Image(systemName: "figure.walk")
                    .font(.title2)
                    .foregroundStyle(.pink)

                VStack(alignment: .leading, spacing: 2) {
                    Text("Forbach en Rose")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                    chrono(contexte)
                        .font(.system(size: 30, weight: .semibold, design: .rounded))
                        .monospacedDigit()
                }

                Spacer()

                VStack(alignment: .trailing, spacing: 2) {
                    Text(contexte.attributes.dossard)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                    Text(distance(contexte))
                        .font(.headline)
                        .monospacedDigit()
                }
            }
            .padding()
            .activityBackgroundTint(Color.black.opacity(0.55))

        } dynamicIsland: { contexte in
            DynamicIsland {
                // ── Déployée (appui long) ───────────────────────────────────
                DynamicIslandExpandedRegion(.leading) {
                    Label("Course", systemImage: "figure.walk")
                        .font(.caption)
                        .foregroundStyle(.pink)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    Text(distance(contexte))
                        .font(.caption)
                        .monospacedDigit()
                }
                DynamicIslandExpandedRegion(.center) {
                    chrono(contexte)
                        .font(.system(size: 28, weight: .semibold, design: .rounded))
                        .monospacedDigit()
                }
                DynamicIslandExpandedRegion(.bottom) {
                    Text(contexte.state.arrive ? "Arrivée franchie" : contexte.attributes.dossard)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            } compactLeading: {
                Image(systemName: "figure.walk").foregroundStyle(.pink)
            } compactTrailing: {
                // ⚠️ L'espace compact est TRÈS étroit : au-delà de cinq
                // caractères, iOS tronque sans prévenir. D'où le format court.
                chrono(contexte, court: true)
                    .monospacedDigit()
                    .frame(maxWidth: 52)
            } minimal: {
                Image(systemName: "figure.walk").foregroundStyle(.pink)
            }
            .keylineTint(.pink)
        }
    }

    /// Le compteur, animé par le système.
    ///
    /// L'intervalle va du départ à « très loin » : `Text(timerInterval:)` compte
    /// alors en montant. Le figer à l'arrivée demanderait de borner l'intervalle,
    /// ce que fait `arreter()` en terminant l'activité.
    @available(iOS 16.1, *)
    private func chrono(
        _ contexte: ActivityViewContext<FERActivityAttributes>,
        court: Bool = false
    ) -> Text {
        Text(
            timerInterval: contexte.attributes.depart...Date.distantFuture,
            countsDown: false,
            showsHours: !court
        )
    }

    private func distance(
        _ contexte: ActivityViewContext<FERActivityAttributes>
    ) -> String {
        let m = contexte.state.distanceM
        if m < 1000 { return "\(Int(m)) m" }
        return String(format: "%.2f km", m / 1000).replacingOccurrences(of: ".", with: ",")
    }
}
