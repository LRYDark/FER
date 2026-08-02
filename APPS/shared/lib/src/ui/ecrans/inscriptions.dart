import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../api/api_erreur.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';

/// « Mes inscriptions » — toutes éditions confondues, groupées par année.
///
/// C'est le cas classique du parent qui inscrit toute la famille sous sa propre
/// adresse : plusieurs inscriptions partagent alors un `group_id`. Elles
/// apparaissent toutes ici, et c'est là que le transfert prend son sens.
library;

class EcranInscriptions extends StatelessWidget {
  const EcranInscriptions({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final inscriptions = session.inscriptions;

    if (inscriptions.isEmpty) {
      return RefreshIndicator(
        onRefresh: session.rafraichir,
        child: ListView(
          children: <Widget>[
            SizedBox(height: MediaQuery.sizeOf(context).height * 0.15),
            const RienAAfficher(
              icone: Icons.inbox_outlined,
              titre: 'Aucune inscription rattachée',
              explication:
                  "Si vous vous êtes inscrit avec une autre adresse email, "
                  'déconnectez-vous et reconnectez-vous avec celle-ci — '
                  "c'est l'adresse qui fait le lien.",
            ),
          ],
        ),
      );
    }

    // Groupement par édition, la plus récente en premier.
    final parAnnee = <int, List<Inscription>>{};
    for (final i in inscriptions) {
      parAnnee.putIfAbsent(i.annee, () => <Inscription>[]).add(i);
    }
    final annees = parAnnee.keys.toList()..sort((a, b) => b.compareTo(a));

    return RefreshIndicator(
      onRefresh: session.rafraichir,
      child: ListView.separated(
        padding: const EdgeInsets.all(marge),
        itemCount: annees.length,
        separatorBuilder: (_, __) => const SizedBox(height: marge),
        itemBuilder: (context, index) {
          final annee = annees[index];
          final membres = parAnnee[annee]!;
          return CarteFer(
            titre: 'Édition $annee',
            icone: membres.length > 1 ? Icons.groups_outlined : Icons.person_outline,
            action: Pastille('${membres.length} inscription'
                '${membres.length > 1 ? 's' : ''}'),
            enfant: Column(
              children: <Widget>[
                for (final i in membres)
                  _LigneInscription(
                    inscription: i,
                    resultat: session.resultatDe(i),
                  ),
                if (membres.length > 1) ...<Widget>[
                  const SizedBox(height: 8),
                  Text(
                    'Ces personnes partagent votre adresse email. Pour que '
                    "l'une d'elles ait son propre espace, transférez son "
                    'inscription depuis sa fiche.',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: Theme.of(context).colorScheme.outline),
                  ),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

class _LigneInscription extends StatelessWidget {
  const _LigneInscription({required this.inscription, this.resultat});

  final Inscription inscription;
  final Resultat? resultat;

  @override
  Widget build(BuildContext context) {
    final sousTitre = <String>[
      'n° ${inscription.inscriptionNo}',
      if (inscription.tshirt != null) 'T-shirt ${inscription.tshirt}',
      if (inscription.ville != null) inscription.ville!,
    ].join(' · ');

    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(inscription.nomComplet),
      subtitle: Text(sousTitre),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (resultat?.chronoAffichable ?? false)
            Text(resultat!.chrono, style: chiffresFixes)
          else if (inscription.estGratuite)
            const Pastille('Gratuit', couleur: Colors.green)
          else
            Pastille(
              '${inscription.montantDu!.toStringAsFixed(2).replaceAll('.', ',')} €',
            ),
          const Icon(Icons.chevron_right),
        ],
      ),
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => EcranInscription(inscription: inscription),
        ),
      ),
    );
  }
}

/* ════════════════════════════ Fiche détaillée ═══════════════════════════ */

class EcranInscription extends StatefulWidget {
  const EcranInscription({required this.inscription, super.key});

  final Inscription inscription;

  @override
  State<EcranInscription> createState() => _EcranInscriptionState();
}

class _EcranInscriptionState extends State<EcranInscription> {
  Uint8ListFuture? _qr;

  @override
  void initState() {
    super.initState();
    _qr = _chargerQr();
  }

  /// Le QR est demandé au serveur, pas fabriqué ici.
  ///
  /// ⚠️ C'EST LE MÊME GÉNÉRATEUR QUE LE MAIL DE CONFIRMATION. Un QR reconstruit
  /// côté application aurait sa propre version du contenu encodé — et le jour
  /// où les deux divergeraient, le lecteur du stand refuserait un dossard
  /// parfaitement valable.
  Uint8ListFuture _chargerQr() async {
    final api = PorteeSession.action(context).api;
    final b64 = await api.qrCode(
      widget.inscription.annee,
      widget.inscription.inscriptionNo,
    );
    return base64Decode(b64);
  }

  @override
  Widget build(BuildContext context) {
    final i = widget.inscription;
    final session = PorteeSession.de(context);
    final resultat = session.resultatDe(i);
    final large = MediaQuery.sizeOf(context).width >= 700;

    final detail = CarteFer(
      titre: "Détail de l'inscription",
      icone: Icons.badge_outlined,
      enfant: Column(
        children: <Widget>[
          _Champ('Nom', i.nomComplet),
          _Champ('Numéro', i.inscriptionNo),
          _Champ('Édition', '${i.annee}'),
          if (i.sexe != null) _Champ('Sexe', _sexe(i.sexe!)),
          if (i.age != null) _Champ('Âge', '${i.age} ans'),
          if (i.ville != null) _Champ('Ville', i.ville!),
          if (i.tshirt != null) _Champ('T-shirt', i.tshirt!),
          if (i.equipe != null) _Champ('Équipe', i.equipe!),
          _Champ(
            'Paiement',
            i.estGratuite
                ? 'Gratuit'
                : '${i.paiementMode ?? 'Réglé'} · '
                    '${i.montantDu!.toStringAsFixed(2).replaceAll('.', ',')} €',
          ),
        ],
      ),
    );

    final qr = CarteFer(
      titre: 'Votre QR code',
      icone: Icons.qr_code_2,
      enfant: FutureBuilder<Uint8List>(
        future: _qr,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const SizedBox(
              height: 200,
              child: Center(child: CircularProgressIndicator()),
            );
          }
          if (snap.hasError) {
            return _Erreur(snap.error!, () => setState(() => _qr = _chargerQr()));
          }
          return Column(
            children: <Widget>[
              // ⚠️ FOND BLANC IMPOSÉ. En thème sombre, un QR noir sur fond
              // sombre n'est plus lisible par un lecteur — et on ne s'en rend
              // compte qu'au stand, le jour de la course.
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Image.memory(
                  snap.data!,
                  width: 200,
                  height: 200,
                  // Un QR agrandi doit rester net : l'interpolation lisserait
                  // les modules et le rendrait illisible.
                  filterQuality: FilterQuality.none,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                'À présenter au retrait des dossards.',
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: Theme.of(context).colorScheme.outline),
              ),
            ],
          );
        },
      ),
    );

    return Scaffold(
      appBar: AppBar(title: Text('Édition ${i.annee}')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(marge),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            // Sur tablette, les deux cartes tiennent côte à côte ; sur
            // téléphone elles s'empilent. Aucune donnée n'est masquée d'un
            // format à l'autre.
            if (large)
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Expanded(child: detail),
                    const SizedBox(width: marge),
                    SizedBox(width: 320, child: qr),
                  ],
                ),
              )
            else ...<Widget>[
              detail,
              const SizedBox(height: marge),
              qr,
            ],
            if (resultat != null) ...<Widget>[
              const SizedBox(height: marge),
              _CarteResultat(resultat: resultat),
            ],
            const SizedBox(height: marge),
            _CarteCorrection(inscription: i),
          ],
        ),
      ),
    );
  }

  static String _sexe(String s) => switch (s.toUpperCase()) {
        'H' || 'M' => 'Homme',
        'F' => 'Femme',
        _ => 'Autre',
      };
}

/// Correction du sexe et de l'âge — les deux seuls champs modifiables.
///
/// Le serveur refuse la modification sur une édition archivée ou après le
/// départ. L'application NE REJOUE PAS ces règles : elle envoie et affiche le
/// refus. Deux jeux de règles finiraient par diverger, et c'est celui du
/// serveur qui compte.
class _CarteCorrection extends StatefulWidget {
  const _CarteCorrection({required this.inscription});

  final Inscription inscription;

  @override
  State<_CarteCorrection> createState() => _CarteCorrectionState();
}

class _CarteCorrectionState extends State<_CarteCorrection> {
  late String? _sexe = widget.inscription.sexe;
  late final TextEditingController _age =
      TextEditingController(text: widget.inscription.age?.toString() ?? '');
  bool _ouvert = false;
  bool _occupe = false;

  @override
  void dispose() {
    _age.dispose();
    super.dispose();
  }

  Future<void> _envoyer() async {
    setState(() => _occupe = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final r = await PorteeSession.action(context).api.majInscription(
            widget.inscription.annee,
            widget.inscription.inscriptionNo,
            sexe: _sexe,
            age: _age.text.trim().isEmpty ? null : _age.text.trim(),
          );
      if (!mounted) return;
      await PorteeSession.action(context).rafraichir();
      messenger.showSnackBar(
        SnackBar(content: Text(r['message'] as String? ?? 'Enregistré.')),
      );
      if (mounted) setState(() => _ouvert = false);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_ouvert) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: () => setState(() => _ouvert = true),
          icon: const Icon(Icons.edit_outlined),
          label: const Text('Changer mes informations'),
        ),
      );
    }
    return CarteFer(
      titre: 'Changer mes informations',
      icone: Icons.edit_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          DropdownButtonFormField<String>(
            value: _sexe,
            decoration: const InputDecoration(labelText: 'Sexe'),
            items: const <DropdownMenuItem<String>>[
              DropdownMenuItem<String>(value: 'H', child: Text('Homme')),
              DropdownMenuItem<String>(value: 'F', child: Text('Femme')),
            ],
            onChanged: _occupe ? null : (v) => setState(() => _sexe = v),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _age,
            enabled: !_occupe,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Âge'),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _occupe ? null : _envoyer,
            child: const Text('Enregistrer'),
          ),
          TextButton(
            onPressed: _occupe ? null : () => setState(() => _ouvert = false),
            child: const Text('Annuler'),
          ),
        ],
      ),
    );
  }
}

class _CarteResultat extends StatelessWidget {
  const _CarteResultat({required this.resultat});

  final Resultat resultat;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return CarteFer(
      titre: 'Votre temps',
      icone: Icons.timer_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            resultat.chrono,
            style: theme.textTheme.displaySmall?.copyWith(
              fontWeight: FontWeight.w700,
              fontFeatures: chiffresFixes.fontFeatures,
            ),
          ),
          const SizedBox(height: 4),
          // ⚠️ LA MÉTHODE ACCOMPAGNE TOUJOURS LE TEMPS. Un temps extrapolé au
          // GPS affiché nu passerait pour une mesure à la seconde près.
          Text(
            '${resultat.methode.libelle}'
            '${resultat.precisionS != null ? ' · ±${resultat.precisionS} s' : ''}',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
        ],
      ),
    );
  }
}

class _Champ extends StatelessWidget {
  const _Champ(this.libelle, this.valeur);

  final String libelle;
  final String valeur;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          SizedBox(
            width: 110,
            child: Text(libelle,
                style: theme.textTheme.bodyMedium
                    ?.copyWith(color: theme.colorScheme.outline)),
          ),
          Expanded(
            child: Text(valeur,
                style: theme.textTheme.bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}

class _Erreur extends StatelessWidget {
  const _Erreur(this.erreur, this.reessayer);

  final Object erreur;
  final VoidCallback reessayer;

  @override
  Widget build(BuildContext context) {
    final message =
        erreur is ApiErreur ? (erreur as ApiErreur).message : '$erreur';
    return Column(
      children: <Widget>[
        Text(message, textAlign: TextAlign.center),
        const SizedBox(height: 8),
        TextButton(onPressed: reessayer, child: const Text('Réessayer')),
      ],
    );
  }
}

typedef Uint8ListFuture = Future<Uint8List>;
