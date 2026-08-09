/// Suivi de course — le chrono, le GPS et la balise, pendant l'épreuve.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// CET ÉCRAN N'AFFICHE JAMAIS UN TEMPS OFFICIEL.
///
/// Le chrono qu'on y voit est calculé depuis l'heure de départ de l'édition,
/// pour se situer pendant la marche. Le résultat officiel vient du serveur,
/// après arbitrage entre balise et GPS, et peut en différer de quelques
/// secondes. C'est écrit à l'écran : laisser croire que ce compteur fait foi
/// garantirait une contestation.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// CE N'EST PLUS UN ONGLET, C'EST UNE PAGE OUVERTE DEPUIS SON INSCRIPTION.
///
/// Une inscription passe par trois états : à venir, en cours, terminée. Le
/// suivi est l'état « en cours » de CE dossard-là — pas une rubrique à part.
/// L'ancien onglet obligeait à faire le rapprochement soi-même, et affichait
/// onze mois sur douze « le suivi n'est pas ouvert », ce qui n'apprend rien.
///
/// Les infos pratiques (lieu, horaires, retrait des dossards) ne sont plus ici :
/// elles vivent sur l'inscription, où on les cherche naturellement.
library;

import 'package:flutter/material.dart';

import '../../course/mesures.dart';
import '../../course/suivi_course.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';
import 'profil_physique.dart';

class EcranSuivi extends StatelessWidget {
  const EcranSuivi({required this.inscription, super.key});

  final Inscription inscription;

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final suivi = session.suivi;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Ma course'),
        // Le dossard sous le titre : sur un écran de suivi, savoir POUR QUI on
        // mesure évite l'erreur du parent qui suit la course d'un enfant.
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(22),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(marge, 0, marge, 12),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                '${inscription.nomComplet} · n° ${inscription.inscriptionNo}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant),
              ),
            ),
          ),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(marge, marge, marge, margeBasListe),
        children: <Widget>[
          AnimatedBuilder(
            animation: suivi,
            builder: (context, _) => _CarteSuivi(suivi: suivi),
          ),
          const SizedBox(height: marge),
          AnimatedBuilder(
            animation: suivi,
            builder: (context, _) => _CarteFile(suivi: suivi),
          ),
        ],
      ),
    );
  }
}

/* ══════════════════════════════ Suivi ═════════════════════════════════ */

class _CarteSuivi extends StatelessWidget {
  const _CarteSuivi({required this.suivi});

  final SuiviCourse suivi;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final session = PorteeSession.de(context);
    final actif = suivi.etat == EtatSuivi.actif;

    return CarteFer(
      titre: 'Suivi de course',
      icone: Icons.directions_walk,
      action: actif
          ? const Pastille('en cours', couleur: Colors.green, icone: Icons.circle)
          : null,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (actif) ...<Widget>[
            Center(
              child: Text(
                formaterDuree(suivi.chronoCourant),
                style: theme.textTheme.displayMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  fontFeatures: chiffresFixes.fontFeatures,
                ),
              ),
            ),
            const SizedBox(height: 4),
            // ⚠️ LA MISE EN GARDE ACCOMPAGNE LE COMPTEUR, TOUJOURS. Sans elle,
            // ce chiffre serait pris pour le temps officiel.
            Center(
              child: Text(
                session.infoCourse?.partie ?? false
                    ? 'Depuis le départ officiel — votre temps final est calculé '
                        "par l'organisation, à partir de la balise et du GPS."
                    : "Le départ n'a pas encore été donné : ce compteur part de "
                        "l'heure prévue et sera recalé.",
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.outline),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: <Widget>[
                Expanded(child: _Stat('Distance', formaterDistance(suivi.distanceM))),
                Expanded(child: _Stat('Allure', suivi.allureMoyenne != null
                    ? '${suivi.allureMoyenne} /km' : '—')),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                Expanded(child: _Stat('Dénivelé +',
                    '${suivi.denivelePositifM.round()} m')),
                Expanded(
                  child: suivi.calories.disponible
                      // La réserve accompagne le chiffre ici comme partout : ce
                      // n'est pas une mesure, et ça doit se lire.
                      ? _Stat('Calories', suivi.calories.libelle,
                          note: Calories.mention)
                      : _StatProfil(),
                ),
              ],
            ),
            if (suivi.kilometres.isNotEmpty) ...<Widget>[
              const SizedBox(height: 12),
              _TempsAuKm(tours: suivi.kilometres),
            ],
            const SizedBox(height: 12),
            Row(
              children: <Widget>[
                Icon(
                  suivi.baliseActive
                      ? Icons.bluetooth_connected
                      : Icons.bluetooth_disabled,
                  size: 16,
                  color: suivi.baliseActive
                      ? theme.colorScheme.primary
                      : theme.colorScheme.outline,
                ),
                const SizedBox(width: 6),
                Text(
                  suivi.baliseActive
                      ? 'Balise à l\'écoute'
                      : 'Balise inactive — le GPS prend le relais',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
            const SizedBox(height: 16),
            // Boutons de secours : si la balise n'a rien vu et que le GPS a
            // dérivé, il reste ce geste. Sans lui, quelqu'un franchit la ligne
            // et n'a aucun moyen de le signaler.
            Row(
              children: <Widget>[
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: suivi.aFranchi('depart')
                        ? null
                        : () => suivi.declarerPassage('depart'),
                    icon: const Icon(Icons.flag_outlined),
                    label: const Text('Je pars'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: suivi.aFranchi('arrivee')
                        ? null
                        : () => suivi.declarerPassage('arrivee'),
                    icon: const Icon(Icons.sports_score),
                    label: const Text("J'arrive"),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: () async {
                await suivi.arreter();
                await session.rafraichirResultats();
              },
              icon: const Icon(Icons.stop_circle_outlined),
              label: const Text('Arrêter le suivi'),
            ),
          ] else ...<Widget>[
            Text(
              'Le suivi enregistre votre position et écoute la balise posée sur '
              'les lignes. Les deux sont envoyées à l\'organisation, qui en '
              'déduit votre temps.',
              style: theme.textTheme.bodyMedium,
            ),
            if (suivi.messageErreur != null) ...<Widget>[
              const SizedBox(height: 12),
              Text(
                suivi.messageErreur!,
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: suivi.etat == EtatSuivi.demarrage
                  ? null
                  : () => suivi.demarrer(
                        inscription: session.inscriptionActive!,
                        edition: session.editionActive,
                        // Le top réel s'il a été donné, la prévision sinon.
                        departEffectif: session.infoCourse?.departEffectif,
                      ),
              icon: const Icon(Icons.play_arrow),
              label: const Text('Démarrer le suivi'),
            ),
          ],
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat(this.libelle, this.valeur, {this.note});

  final String libelle;
  final String valeur;

  /// Réserve affichée sous la valeur — pour tout ce qui est estimé.
  final String? note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(libelle,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline)),
        Text(valeur,
            style: theme.textTheme.titleMedium
                ?.copyWith(fontWeight: FontWeight.w600)),
        if (note != null)
          Text(note!,
              style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline, fontSize: 10)),
      ],
    );
  }
}

/// Invitation à renseigner son poids, à la place des calories.
///
/// ⚠️ ON N'INVENTE PAS UN POIDS MOYEN pour afficher un chiffre. Un « ~400 kcal »
/// calculé sur 70 kg par défaut serait faux pour presque tout le monde, et rien
/// ne le dirait.
class _StatProfil extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => const EcranProfilPhysique()),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('Calories',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline)),
          Row(
            children: <Widget>[
              Icon(Icons.add_circle_outline,
                  size: 16, color: theme.colorScheme.primary),
              const SizedBox(width: 4),
              Text('Votre poids',
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: theme.colorScheme.primary)),
            ],
          ),
          Text('reste sur le téléphone',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline, fontSize: 10)),
        ],
      ),
    );
  }
}

/// Le temps de chaque kilomètre, comme sur une montre de sport.
class _TempsAuKm extends StatelessWidget {
  const _TempsAuKm({required this.tours});

  final List<TempsAuKm> tours;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text('Par kilomètre',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline)),
        const SizedBox(height: 4),
        // Les derniers d'abord : c'est le kilomètre qu'on vient de finir qu'on
        // regarde, pas le premier.
        for (final t in tours.reversed.take(5))
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              children: <Widget>[
                SizedBox(
                  width: 34,
                  child: Text('km ${t.km}', style: theme.textTheme.bodySmall),
                ),
                Text(t.allure,
                    style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        fontFeatures: chiffresFixes.fontFeatures)),
                if (t.deniveleM >= 5) ...<Widget>[
                  const SizedBox(width: 8),
                  Icon(Icons.trending_up, size: 13, color: theme.colorScheme.outline),
                  Text(' ${t.deniveleM.round()} m',
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: theme.colorScheme.outline)),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

/// Ce qui attend d'être envoyé.
///
/// Affiché EXPRÈS, et pas caché comme un détail technique : voir « 340 points
/// en attente » pendant une coupure de réseau, c'est comprendre que rien n'est
/// perdu. Sans cette carte, l'absence de retour ressemble à une panne.
class _CarteFile extends StatelessWidget {
  const _CarteFile({required this.suivi});

  final SuiviCourse suivi;

  @override
  Widget build(BuildContext context) {
    final total = suivi.pointsEnAttente + suivi.detectionsEnAttente;
    if (total == 0) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return CarteFer(
      titre: 'En attente d\'envoi',
      icone: Icons.cloud_upload_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            '${suivi.detectionsEnAttente} passage(s) de ligne et '
            '${suivi.pointsEnAttente} point(s) de parcours.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 6),
          Text(
            'Tout est enregistré sur votre téléphone et repartira dès le retour '
            'du réseau. Rien n\'est perdu, même si vous fermez l\'application.',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
        ],
      ),
    );
  }
}
