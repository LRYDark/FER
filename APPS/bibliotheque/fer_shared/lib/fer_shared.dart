/// Cœur partagé des applications Forbach en Rose.
///
/// Les deux coques (`APPS/android`, `APPS/mac`) n'importent QUE ce fichier —
/// et chacune sert à la fois le téléphone et la montre. Tout le reste est
/// derrière `src/`, donc libre d'être réorganisé sans toucher aux coques.
///
/// ```dart
/// import 'package:fer_shared/fer_shared.dart';
///
/// void main() async {
///   WidgetsFlutterBinding.ensureInitialized();
///   final session = await Session.ouvrir(poseur: MonPoseurDeRappel());
///   runApp(AppFer(session: session));
/// }
/// ```
library fer_shared;

export 'src/api/api_client.dart';
export 'src/api/api_erreur.dart';
export 'src/api/jetons.dart';
export 'src/app.dart';
export 'src/course/balise.dart';
export 'src/course/file_attente.dart';
export 'src/course/mesures.dart';
export 'src/course/suivi_course.dart';
export 'src/models/course_app.dart';
export 'src/models/modeles.dart';
export 'src/reveil.dart';
export 'src/session.dart';
export 'src/ui/ecrans/accueil.dart';
export 'src/ui/ecrans/bienvenue.dart';
export 'src/ui/ecrans/bloquant.dart';
export 'src/ui/ecrans/compte.dart';
export 'src/ui/ecrans/connexion.dart';
export 'src/ui/ecrans/course.dart';
export 'src/ui/ecrans/inscriptions.dart';
export 'src/ui/ecrans/messages.dart';
export 'src/ui/ecrans/modifier_compte.dart';
export 'src/ui/ecrans/partage.dart';
export 'src/ui/ecrans/profil_physique.dart';
export 'src/ui/ecrans/resultats.dart';
export 'src/ui/portee.dart';
export 'src/ui/theme.dart';
