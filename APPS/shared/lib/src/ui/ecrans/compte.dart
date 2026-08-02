import 'package:flutter/material.dart';

import '../../api/api_erreur.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';

/// « Mon compte » — profil, transferts, appareils, déconnexion.
library;

class EcranCompte extends StatelessWidget {
  const EcranCompte({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final profil = session.profil;

    return RefreshIndicator(
      onRefresh: session.rafraichir,
      child: ListView(
        padding: const EdgeInsets.all(marge),
        children: <Widget>[
          CarteFer(
            titre: 'Mon compte',
            icone: Icons.person_outline,
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(profil?.nomComplet ?? '—',
                    style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(profil?.email ?? '',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: Theme.of(context).colorScheme.outline)),
              ],
            ),
          ),
          const SizedBox(height: marge),
          const _CarteTransferts(),
          const SizedBox(height: marge),
          const _CarteAppareils(),
          const SizedBox(height: marge),
          _CarteReveil(),
          const SizedBox(height: marge),
          OutlinedButton.icon(
            onPressed: () => _confirmerDeconnexion(context),
            icon: const Icon(Icons.logout),
            label: const Text('Me déconnecter'),
          ),
        ],
      ),
    );
  }

  static Future<void> _confirmerDeconnexion(BuildContext context) async {
    final session = PorteeSession.action(context);
    // ⚠️ La confirmation dit ce qui va être PERDU. Se déconnecter pendant une
    // course purge la file d'envoi : les données d'un compte ne doivent pas
    // repartir sous l'identité du suivant.
    final enAttente =
        session.file.detectionsEnAttente + session.file.pointsEnAttente;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Se déconnecter ?'),
        content: Text(
          enAttente > 0
              ? "$enAttente donnée(s) de course n'ont pas encore été envoyées. "
                  'Elles seront perdues. Attendez le retour du réseau si vous '
                  'venez de courir.'
              : 'Vous devrez saisir un nouveau code pour revenir.',
        ),
        actions: <Widget>[
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Annuler')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Me déconnecter')),
        ],
      ),
    );
    if (ok ?? false) await session.deconnexion();
  }
}

/* ═══════════════════════════ Transferts ═══════════════════════════════ */

/// Transférer une inscription à quelqu'un d'autre — exactement ce que fait le
/// site, avec le même double accord : le destinataire doit confirmer par un
/// code reçu par mail. Sans cela, on pourrait faire disparaître l'inscription
/// de quelqu'un en saisissant une adresse au hasard.
class _CarteTransferts extends StatefulWidget {
  const _CarteTransferts();

  @override
  State<_CarteTransferts> createState() => _CarteTransfertsState();
}

class _CarteTransfertsState extends State<_CarteTransferts> {
  Future<List<Transfert>>? _liste;

  @override
  void initState() {
    super.initState();
    _recharger();
  }

  void _recharger() {
    _liste = PorteeSession.action(context)
        .api
        .transferts()
        .then((l) => l.map(Transfert.depuisJson).toList());
  }

  Future<void> _nouveau() async {
    final session = PorteeSession.action(context);
    final inscriptions = session.inscriptions;
    if (inscriptions.isEmpty) return;

    final resultat = await showDialog<_DemandeTransfert>(
      context: context,
      builder: (_) => _DialogueTransfert(inscriptions: inscriptions),
    );
    if (resultat == null || !mounted) return;

    final messenger = ScaffoldMessenger.of(context);
    try {
      await session.api.creerTransfert(
        annee: resultat.inscription.annee,
        inscriptionNo: resultat.inscription.inscriptionNo,
        emailCible: resultat.email,
      );
      messenger.showSnackBar(const SnackBar(
        content: Text('Demande envoyée. Elle doit être acceptée par la '
            'personne destinataire, depuis le lien reçu par mail.'),
      ));
      if (mounted) setState(_recharger);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _annuler(int id) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await PorteeSession.action(context).api.annulerTransfert(id);
      messenger.showSnackBar(
          const SnackBar(content: Text('Demande annulée.')));
      if (mounted) setState(_recharger);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return CarteFer(
      titre: "Transférer une inscription",
      icone: Icons.swap_horiz,
      action: IconButton(
        onPressed: _nouveau,
        icon: const Icon(Icons.add),
        tooltip: 'Nouvelle demande',
      ),
      enfant: FutureBuilder<List<Transfert>>(
        future: _liste,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          final l = snap.data ?? const <Transfert>[];
          if (l.isEmpty) {
            return Text(
              'Vous pouvez céder une inscription à quelqu\'un d\'autre : elle '
              'passera sur son adresse email, avec son espace et son '
              'chronométrage. La personne doit accepter depuis le mail '
              "qu'elle recevra.",
              style: theme.textTheme.bodyMedium,
            );
          }
          return Column(
            children: <Widget>[
              for (final t in l)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('n° ${t.inscriptionNo} → ${t.emailCible ?? '?'}'),
                  subtitle: Text('Édition ${t.annee} · ${t.statut}'),
                  trailing: t.enAttente
                      ? IconButton(
                          icon: const Icon(Icons.close),
                          tooltip: 'Annuler',
                          onPressed: () => _annuler(t.id),
                        )
                      : null,
                ),
            ],
          );
        },
      ),
    );
  }
}

class _DemandeTransfert {
  const _DemandeTransfert(this.inscription, this.email);

  final Inscription inscription;
  final String email;
}

class _DialogueTransfert extends StatefulWidget {
  const _DialogueTransfert({required this.inscriptions});

  final List<Inscription> inscriptions;

  @override
  State<_DialogueTransfert> createState() => _DialogueTransfertState();
}

class _DialogueTransfertState extends State<_DialogueTransfert> {
  late Inscription _choisie = widget.inscriptions.first;
  final _email = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('Transférer une inscription'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DropdownButtonFormField<Inscription>(
              value: _choisie,
              decoration: const InputDecoration(labelText: 'Inscription'),
              items: <DropdownMenuItem<Inscription>>[
                for (final i in widget.inscriptions)
                  DropdownMenuItem<Inscription>(
                    value: i,
                    child: Text('${i.annee} · ${i.nomComplet}'),
                  ),
              ],
              onChanged: (v) => setState(() => _choisie = v!),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              decoration: const InputDecoration(
                labelText: 'Adresse email du destinataire',
              ),
            ),
            const SizedBox(height: 12),
            // La conséquence est annoncée AVANT la demande, pas après : c'est
            // le moment où elle peut encore changer la décision.
            Text(
              "Vous perdrez l'accès à cette inscription une fois le transfert "
              'accepté.',
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ),
        actions: <Widget>[
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Annuler')),
          FilledButton(
            onPressed: () => Navigator.pop(
                context, _DemandeTransfert(_choisie, _email.text.trim())),
            child: const Text('Envoyer la demande'),
          ),
        ],
      );
}

/* ═══════════════════════════ Appareils ════════════════════════════════ */

class _CarteAppareils extends StatefulWidget {
  const _CarteAppareils();

  @override
  State<_CarteAppareils> createState() => _CarteAppareilsState();
}

class _CarteAppareilsState extends State<_CarteAppareils> {
  Future<List<Appareil>>? _liste;

  @override
  void initState() {
    super.initState();
    _recharger();
  }

  void _recharger() {
    _liste = PorteeSession.action(context)
        .api
        .appareils()
        .then((l) => l.map(Appareil.depuisJson).toList());
  }

  @override
  Widget build(BuildContext context) => CarteFer(
        titre: 'Mes appareils',
        icone: Icons.devices_outlined,
        enfant: FutureBuilder<List<Appareil>>(
          future: _liste,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            final l = snap.data ?? const <Appareil>[];
            return Column(
              children: <Widget>[
                for (final a in l)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(a.courant ? Icons.smartphone : Icons.devices),
                    title: Text(a.nom),
                    subtitle: Text(a.plateforme ?? '—'),
                    // ⚠️ L'appareil COURANT ne se révoque pas d'ici : on se
                    // déconnecterait soi-même sans l'avoir voulu. Le bouton
                    // « Me déconnecter » est là pour ça, et il prévient.
                    trailing: a.courant
                        ? const Pastille('cet appareil', couleur: Colors.green)
                        : IconButton(
                            icon: const Icon(Icons.link_off),
                            tooltip: 'Révoquer',
                            onPressed: () async {
                              final m = ScaffoldMessenger.of(context);
                              try {
                                await PorteeSession.action(context)
                                    .api
                                    .revoquerAppareil(a.id);
                                if (mounted) setState(_recharger);
                              } on ApiErreur catch (e) {
                                m.showSnackBar(
                                    SnackBar(content: Text(e.message)));
                              }
                            },
                          ),
                  ),
              ],
            );
          },
        ),
      );
}

/* ════════════════════════════ Réveil ══════════════════════════════════ */

class _CarteReveil extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final theme = Theme.of(context);
    final minutes = session.config?.reveilAvantMin ?? 0;
    final prochain = session.reveil.prochain;

    if (minutes <= 0) return const SizedBox.shrink();

    return CarteFer(
      titre: 'Rappel avant la course',
      icone: Icons.alarm,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (prochain != null)
            Text(
              'Rappel programmé le ${prochain.day.toString().padLeft(2, '0')}/'
              '${prochain.month.toString().padLeft(2, '0')} à '
              '${prochain.hour.toString().padLeft(2, '0')} h '
              '${prochain.minute.toString().padLeft(2, '0')}.',
              style: theme.textTheme.bodyMedium,
            )
          else
            Text(
              session.reveil.autorise
                  ? "Aucun rappel : l'heure de départ n'est pas encore publiée."
                  : "Les notifications sont refusées sur cet appareil : aucun "
                      'rappel ne peut être programmé.',
              style: theme.textTheme.bodyMedium,
            ),
          const SizedBox(height: 8),
          // ⚠️ Ce que le rappel FAIT, et ce qu'il ne fait pas. Une application
          // ne se lance pas toute seule — ni Android ni iOS ne l'autorisent.
          Text(
            "L'application ne démarre pas d'elle-même : le rappel s'affiche sur "
            "votre téléphone, et c'est vous qui l'ouvrez pour lancer le suivi.",
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
          if (!session.reveil.autorise) ...<Widget>[
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () async {
                await session.reveil.demanderAutorisation();
                await session.rafraichirConfig();
              },
              icon: const Icon(Icons.notifications_active_outlined),
              label: const Text('Autoriser les notifications'),
            ),
          ],
        ],
      ),
    );
  }
}
