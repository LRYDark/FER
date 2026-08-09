/// « Transférer une inscription » — céder sa place à quelqu'un d'autre.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UN ÉCRAN, DEUX PORTES D'ENTRÉE.
///
/// On y arrive depuis « Mon compte » — on veut transférer, on cherche où — et
/// depuis la fiche d'une inscription — on regarde SA place, et on décide de la
/// céder. Les deux chemins sont légitimes, et c'est pourquoi ce n'est plus une
/// fenêtre privée de l'écran Compte : un geste qui n'existe qu'à un seul
/// endroit ne se trouve qu'en le sachant déjà.
///
/// Quand on vient d'une fiche, l'inscription est IMPOSÉE et ne se choisit plus :
/// on sait laquelle on cède, la proposer à nouveau ouvrirait la porte à
/// transférer celle du voisin par inadvertance.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LE DOUBLE ACCORD EST TENU PAR LE SERVEUR, PAS PAR CET ÉCRAN.
///
/// La demande n'est qu'une demande : le destinataire doit l'accepter depuis un
/// code reçu par mail. Sans cela, on ferait disparaître l'inscription de
/// quelqu'un en saisissant une adresse au hasard.
library;

import 'package:flutter/material.dart';

import '../../api/api_erreur.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';

class EcranTransfert extends StatefulWidget {
  const EcranTransfert({this.inscription, super.key});

  /// Inscription imposée quand on arrive depuis sa fiche. `null` depuis
  /// « Mon compte » : on choisit alors dans la liste.
  final Inscription? inscription;

  @override
  State<EcranTransfert> createState() => _EcranTransfertState();
}

class _EcranTransfertState extends State<EcranTransfert> {
  final _email = TextEditingController();
  Inscription? _choisie;
  bool _occupe = false;

  @override
  void initState() {
    super.initState();
    _choisie = widget.inscription;
  }

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _envoyer() async {
    final cible = _choisie;
    final email = _email.text.trim();
    if (cible == null || email.isEmpty) return;

    setState(() => _occupe = true);
    final session = PorteeSession.action(context);
    final messenger = ScaffoldMessenger.of(context);
    final navigateur = Navigator.of(context);
    try {
      await session.api.creerTransfert(
        annee: cible.annee,
        inscriptionNo: cible.inscriptionNo,
        emailCible: email,
      );
      messenger.showSnackBar(const SnackBar(
        content: Text('Demande envoyée. Elle doit être acceptée par la '
            'personne destinataire, depuis le lien reçu par mail.'),
      ));
      navigateur.pop(true);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
      if (mounted) setState(() => _occupe = false);
    }
  }

  /// « 04/07/2026 à 00:00 » — même format que le site, pour qu'un coureur qui
  /// compare les deux ne se demande pas s'il lit la même date.
  static String _dateHeure(DateTime d) {
    final l = d.toLocal();
    String d2(int n) => n.toString().padLeft(2, '0');
    return '${d2(l.day)}/${d2(l.month)}/${l.year} à ${d2(l.hour)}:${d2(l.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final session = PorteeSession.de(context);
    final imposee = widget.inscription != null;

    // ⚠️ LA MÊME RÈGLE QUE LE SITE, DITE AU MÊME MOMENT. Le serveur refusait
    // déjà (`xfer_creer`), mais seulement à l'envoi : on laissait choisir une
    // inscription, saisir une adresse, appuyer — pour un « non » qui était
    // connu d'avance. Le site, lui, l'annonce d'emblée.
    final edition = session.editionActive;
    final fermes = edition != null && !edition.transfertsOuverts;
    final limite = edition?.transfertsDeadline;

    final pret = _choisie != null &&
        _email.text.trim().isNotEmpty &&
        !_occupe &&
        !fermes;

    return Scaffold(
      appBar: AppBar(title: const Text('Transférer une inscription')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(marge, marge, marge, margeBasListe),
        children: <Widget>[
          if (fermes) ...<Widget>[
            BlocAccent(
              couleur: theme.colorScheme.error,
              icone: Icons.lock_clock,
              enfant: Text(
                limite == null
                    ? 'Les transferts sont fermés pour cette édition. '
                        "Contactez l'organisation si c'est un cas particulier."
                    : 'La date limite de transfert est dépassée '
                        '(${_dateHeure(limite)}). '
                        "Contactez l'organisation si c'est un cas particulier.",
                style: theme.textTheme.bodyMedium,
              ),
            ),
            const SizedBox(height: marge),
          ],
          CarteFer(
            titre: 'Ce que vous cédez',
            icone: Icons.swap_horiz,
            enfant: imposee
                ? Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      LigneFer('Inscription',
                          '${_choisie!.annee} · ${_choisie!.nomComplet}'),
                      LigneFer("Numéro d'inscription", _choisie!.inscriptionNo),
                    ],
                  )
                : ChoixFer<Inscription>(
                    libelle: 'Inscription à transférer',
                    valeur: _choisie,
                    options: session.inscriptions,
                    texteDe: (i) => '${i.annee} · ${i.nomComplet}',
                    surChangement: (v) => setState(() => _choisie = v),
                  ),
          ),
          const SizedBox(height: marge),

          CarteFer(
            titre: 'À qui',
            icone: Icons.alternate_email,
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Text('Adresse email du destinataire',
                    style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant)),
                const SizedBox(height: 8),
                TextField(
                  controller: _email,
                  enabled: !_occupe,
                  keyboardType: TextInputType.emailAddress,
                  autocorrect: false,
                  decoration:
                      const InputDecoration(hintText: 'quelquun@exemple.fr'),
                  // Réévalue l'état du bouton à chaque frappe : un bouton actif
                  // sans adresse enverrait une demande vide.
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 12),
                Text(
                  "La personne recevra un mail et devra accepter : tant qu'elle "
                  "ne l'a pas fait, rien ne change. Vous pouvez annuler la "
                  'demande entre-temps depuis « Mon compte ».',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
          const SizedBox(height: marge),

          // ⚠️ LA CONSÉQUENCE EST ANNONCÉE AVANT L'ENVOI, jamais après : c'est
          // le seul moment où elle peut encore changer la décision.
          BlocAccent(
            couleur: theme.colorScheme.error,
            icone: Icons.warning_amber_rounded,
            enfant: Text(
              "Une fois le transfert accepté, vous perdrez l'accès à cette "
              'inscription : son numéro, son QR code et son chronométrage '
              "passeront à l'autre personne.",
              style: theme.textTheme.bodyMedium,
            ),
          ),
          const SizedBox(height: marge),

          FilledButton.icon(
            onPressed: pret ? _envoyer : null,
            icon: _occupe
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.send_outlined, size: 18),
            label: const Text('Envoyer la demande'),
          ),
        ],
      ),
    );
  }
}
