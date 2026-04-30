<?php
require_once __DIR__ . '/../session_utils.php';

startAppSession();

const STADE_BONAL_ADDRESS = 'Impasse de la Forge';
const STADE_BONAL_CITY = 'Montbeliard';
const STADE_BONAL_LATITUDE = 47.512311;
const STADE_BONAL_LONGITUDE = 6.811345;

$dbHost = '127.0.0.1';
$dbUser = 'u129649329_root';
$dbPassword = '2tM7XQZDmjfJCKJz';
$dbName = 'u129649329_sae';

$user = requireSessionUser('Form/proposer_trajet.php');
$sessionUser = getSessionUser();
$sessionAdresse = trim((string) ($user['adresse'] ?? ''));
$sessionVille = trim((string) ($user['ville'] ?? ''));
$sessionLatitude = trim((string) ($user['latitude'] ?? ''));
$sessionLongitude = trim((string) ($user['longitude'] ?? ''));
$needsDepartureFields = $sessionAdresse === '' || $sessionVille === '';

$message = '';
$messageType = '';

function old($key)
{
    // Cette fonction permet de remettre les anciennes valeurs dans le formulaire
    // apres une erreur, pour eviter que l utilisateur retape tout.
    return $_POST[$key] ?? '';
}

function e($value)
{
    // Cette fonction protege l affichage HTML.
    // Exemple : si quelqu un tape du code HTML dans un champ,
    // on l affiche comme du texte et non comme du vrai code.
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function prepareAndExecute($connexion, $sql, $types = '', $values = [])
{
    // Cette fonction centralise les requetes preparees.
    // Le but est d eviter les injections SQL et de simplifier les corrections.
    $statement = mysqli_prepare($connexion, $sql);

    if (!$statement) {
        throw new Exception(mysqli_error($connexion));
    }

    if ($types !== '') {
        mysqli_stmt_bind_param($statement, $types, ...$values);
    }

    if (!mysqli_stmt_execute($statement)) {
        $error = mysqli_stmt_error($statement);
        mysqli_stmt_close($statement);
        throw new Exception($error);
    }

    return $statement;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $connexion = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

    if (!$connexion) {
        $message = 'Connexion a la base impossible.';
        $messageType = 'erreur';
    } else {
        mysqli_set_charset($connexion, 'utf8mb4');

        // On lit et nettoie toutes les donnees envoyees par le formulaire.
        // trim() retire les espaces inutiles au debut et a la fin.
        // Les informations personnelles viennent maintenant de la session.
        // Le formulaire offreur ne demande plus de les ressaisir.
        $idPersonne = (int) ($user['id_personne'] ?? 0);
        $nom = trim($user['nom']);
        $prenom = trim($user['prenom']);
        $email = trim($user['email']);
        $telephone = trim($user['telephone']);
        $adressePersonne = $sessionAdresse !== '' ? $sessionAdresse : trim($_POST['departure_address'] ?? '');
        $villePersonne = $sessionVille !== '' ? $sessionVille : trim($_POST['departure_city'] ?? '');
        $typeVehicule = trim($_POST['type_vehicule'] ?? '');
        $nbPlace = (int) ($_POST['nb_place'] ?? 0);
        $immatriculation = trim($_POST['immatriculation'] ?? '');
        $assurance = trim($_POST['assurance'] ?? '');
        $controleTechnique = trim($_POST['controle_technique'] ?? '');

        $jour = trim($_POST['jour'] ?? '');
        $dateTrajet = trim($_POST['date_trajet'] ?? '');
        $heureDepart = trim($_POST['heure_depart'] ?? '');
        $heureArrivee = trim($_POST['heure_arrivee'] ?? '');
        $prix = (float) ($_POST['prix'] ?? 0);
        $destinationChoice = trim($_POST['destination_choice'] ?? 'stade_bonal');
        $destinationAddress = trim($_POST['destination_address'] ?? '');
        $destinationCity = trim($_POST['destination_city'] ?? '');
        $destinationLatitude = trim($_POST['destination_latitude'] ?? '');
        $destinationLongitude = trim($_POST['destination_longitude'] ?? '');
        $latitude = $sessionLatitude !== '' ? $sessionLatitude : trim($_POST['departure_latitude'] ?? '');
        $longitude = $sessionLongitude !== '' ? $sessionLongitude : trim($_POST['departure_longitude'] ?? '');

        $adresse = $adressePersonne;
        $ville = $villePersonne;

        if ($destinationChoice === 'stade_bonal') {
            $destinationAddress = STADE_BONAL_ADDRESS;
            $destinationCity = STADE_BONAL_CITY;
            $destinationLatitude = (string) STADE_BONAL_LATITUDE;
            $destinationLongitude = (string) STADE_BONAL_LONGITUDE;
        }

        // Premier filtre simple : on verifie que les champs obligatoires sont presents.
        // Cette verification ne remplace pas la securite SQL, elle sert surtout a guider l utilisateur.
        if (
            $idPersonne <= 0 || $nom === '' || $prenom === '' || $email === '' || $telephone === '' ||
            $adressePersonne === '' || $villePersonne === '' ||
            $typeVehicule === '' || $nbPlace < 1 || $assurance === '' ||
            $controleTechnique === '' || $jour === '' || $dateTrajet === '' ||
            $heureDepart === '' || $heureArrivee === '' ||
            !in_array($destinationChoice, ['stade_bonal', 'event_stade_bonal'], true) ||
            $destinationAddress === '' || $destinationCity === ''
        ) {
            if ($adressePersonne === '' || $villePersonne === '') {
                $message = 'Merci de renseigner votre adresse de depart.';
            } else {
                $message = 'Merci de remplir les champs obligatoires.';
            }
            $messageType = 'erreur';
            
            
        } elseif (
            $destinationChoice === 'event_stade_bonal' &&
            ($destinationLatitude === '' || $destinationLongitude === '')
        ) {
            $message = 'Merci de verifier l adresse de destination.';
            $messageType = 'erreur';
        } else {
            // Toute l insertion est faite dans une transaction.
            // Si une etape echoue, on annule tout pour garder une base coherente.
            mysqli_begin_transaction($connexion);

            try {
                $idVehicule = 0;

                // ETAPE 1 :
                // Si une immatriculation a ete saisie, on verifie si ce vehicule existe deja
                // pour cette personne. Cela evite de creer le meme vehicule plusieurs fois.
                if ($immatriculation !== '') {
                    $statementVehicule = prepareAndExecute(
                        $connexion,
                        "SELECT id_vehicule
                         FROM vehicule
                         WHERE immatriculation = ? AND id_personne = ?
                         LIMIT 1",
                        'si',
                        [$immatriculation, $idPersonne]
                    );
                    $resultVehicule = mysqli_stmt_get_result($statementVehicule);

                    if ($resultVehicule && mysqli_num_rows($resultVehicule) > 0) {
                        $vehicule = mysqli_fetch_assoc($resultVehicule);
                        $idVehicule = (int) $vehicule['id_vehicule'];
                    }

                    mysqli_stmt_close($statementVehicule);
                }

                // Si aucun vehicule existant n a ete trouve, on l insere maintenant.
                if ($idVehicule === 0) {
                    prepareAndExecute(
                        $connexion,
                        "INSERT INTO vehicule (type_vehicule, immatriculation, nb_places, assurance, controle_technique, id_personne)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        'ssissi',
                        [$typeVehicule, $immatriculation, $nbPlace, $assurance, $controleTechnique, $idPersonne]
                    );

                    $idVehicule = mysqli_insert_id($connexion);
                }

                // ETAPE 2 :
                // On cherche si le lieu de depart existe deja avec la meme adresse et la meme ville.
                // Si oui, on reutilise le lieu. Sinon, on cree un nouveau lieu.
                $statementLieu = prepareAndExecute(
                    $connexion,
                    "SELECT id_lieu, latitude, longitude
                     FROM lieu
                     WHERE adresse = ? AND ville = ?
                     LIMIT 1",
                    'ss',
                    [$adresse, $ville]
                );
                $resultLieu = mysqli_stmt_get_result($statementLieu);

                if ($resultLieu && mysqli_num_rows($resultLieu) > 0) {
                    $lieu = mysqli_fetch_assoc($resultLieu);
                    $idLieu = (int) $lieu['id_lieu'];

                    // Si le lieu existe deja mais sans coordonnees,
                    // on peut les completer grace aux informations de session.
                    if ($latitude !== '' && $longitude !== '' && ($lieu['latitude'] === null || $lieu['longitude'] === null)) {
                        prepareAndExecute(
                            $connexion,
                            "UPDATE lieu
                             SET latitude = ?, longitude = ?
                             WHERE id_lieu = ?",
                            'ddi',
                            [(float) $latitude, (float) $longitude, $idLieu]
                        );
                    }
                } else {
                    // Les coordonnees viennent du geocodage JS.
                    // Si elles sont absentes, on enregistre NULL en base.
                    // float transforme les coordonnees texte en nombres.
                    $latitudeValue = $latitude === '' ? null : (float) $latitude;
                    $longitudeValue = $longitude === '' ? null : (float) $longitude;

                    prepareAndExecute(
                        $connexion,
                        "INSERT INTO lieu (adresse, ville, type_lieu, latitude, longitude)
                         VALUES (?, ?, 'depart', ?, ?)",
                        'ssdd',
                        [$adresse, $ville, $latitudeValue, $longitudeValue]
                    );

                    $idLieu = mysqli_insert_id($connexion);
                }
                mysqli_stmt_close($statementLieu);

                // ETAPE 3 :
                // On enregistre aussi la destination de l offre.
                $statementDestination = prepareAndExecute(
                    $connexion,
                    "SELECT id_lieu, latitude, longitude
                     FROM lieu
                     WHERE adresse = ? AND ville = ? AND type_lieu = 'destination'
                     LIMIT 1",
                    'ss',
                    [$destinationAddress, $destinationCity]
                );
                $resultDestination = mysqli_stmt_get_result($statementDestination);

                if ($resultDestination && mysqli_num_rows($resultDestination) > 0) {
                    $destinationLieu = mysqli_fetch_assoc($resultDestination);
                    $idLieuDestination = (int) $destinationLieu['id_lieu'];

                    if (
                        $destinationLatitude !== '' &&
                        $destinationLongitude !== '' &&
                        ($destinationLieu['latitude'] === null || $destinationLieu['longitude'] === null)
                    ) {
                        prepareAndExecute(
                            $connexion,
                            "UPDATE lieu
                             SET latitude = ?, longitude = ?
                             WHERE id_lieu = ?",
                            'ddi',
                            [(float) $destinationLatitude, (float) $destinationLongitude, $idLieuDestination]
                        );
                    }
                } else {
                    prepareAndExecute(
                        $connexion,
                        "INSERT INTO lieu (adresse, ville, type_lieu, latitude, longitude)
                         VALUES (?, ?, 'destination', ?, ?)",
                        'ssdd',
                        [$destinationAddress, $destinationCity, (float) $destinationLatitude, (float) $destinationLongitude]
                    );

                    $idLieuDestination = mysqli_insert_id($connexion);
                }
                mysqli_stmt_close($statementDestination);

                // ETAPE 4 :
                // On cherche si la date et les horaires existent deja dans date_trajet.
                // Si oui, on recupere l id_date existant.
                // Sinon, on insere un nouvel enregistrement.
                $statementDate = prepareAndExecute(
                    $connexion,
                    "SELECT id_date
                     FROM date_trajet
                     WHERE jour = ? AND date_trajet = ? AND heure_depart = ? AND heure_arrivee = ?
                     LIMIT 1",
                    'ssss',
                    [$jour, $dateTrajet, $heureDepart, $heureArrivee]
                );
                $resultDate = mysqli_stmt_get_result($statementDate);

                if ($resultDate && mysqli_num_rows($resultDate) > 0) {
                    $date = mysqli_fetch_assoc($resultDate);
                    $idDate = (int) $date['id_date'];
                } else {
                    prepareAndExecute(
                        $connexion,
                        "INSERT INTO date_trajet (jour, date_trajet, heure_depart, heure_arrivee)
                         VALUES (?, ?, ?, ?)",
                        'ssss',
                        [$jour, $dateTrajet, $heureDepart, $heureArrivee]
                    );

                    $idDate = mysqli_insert_id($connexion);
                }
                mysqli_stmt_close($statementDate);

                // ETAPE 5 :
                // On verifie si l offre exacte existe deja.
                // Ici, "exacte" signifie meme personne, meme lieu, meme date et meme vehicule.
                $statementOffreExiste = prepareAndExecute(
                    $connexion,
                    "SELECT id_offre_demande
                     FROM offre_demande
                     WHERE type = 'offre' AND id_personne = ? AND id_lieu = ? AND id_lieu_destination <=> ? AND id_date = ? AND id_vehicule = ?
                     LIMIT 1",
                    'iiiii',
                    [$idPersonne, $idLieu, $idLieuDestination, $idDate, $idVehicule]
                );
                $resultOffreExiste = mysqli_stmt_get_result($statementOffreExiste);

                if ($resultOffreExiste && mysqli_num_rows($resultOffreExiste) > 0) {
                    $message = 'Cette offre existe deja.';
                    $messageType = 'erreur';
                    mysqli_rollback($connexion);
                } else {
                    // ETAPE 6 :
                    // On insere l offre finale dans offre_demande.
                    // places_proposees = nombre de places disponibles.
                    // prix = montant demande pour le trajet.
                    prepareAndExecute(
                        $connexion,
                        "INSERT INTO offre_demande (type, sens, statut, places_proposees, prix, id_personne, id_lieu, id_lieu_destination, id_date, id_vehicule)
                         VALUES ('offre', 'aller', 'ouverte', ?, ?, ?, ?, ?, ?, ?)",
                        'idiiiii',
                        [$nbPlace, $prix, $idPersonne, $idLieu, $idLieuDestination, $idDate, $idVehicule]
                    );

                    // Si tout est bon, on valide la transaction.
                    mysqli_commit($connexion);
                    $_SESSION['user']['adresse'] = $adresse;
                    $_SESSION['user']['ville'] = $ville;
                    $_SESSION['user']['latitude'] = $latitude;
                    $_SESSION['user']['longitude'] = $longitude;

                    mysqli_close($connexion);
                    echo "<script>
                        alert('Votre offre a bien ete enregistree. Vous recevrez une notification quand un usager aura selectionne votre voiture.');
                        window.location.href = '../index.php';
                    </script>";
                    exit;
                }
            } catch (Exception $exception) {
                // Si une seule requete plante, on annule tout pour eviter une base incoherente.
                mysqli_rollback($connexion);
                $message = 'Erreur pendant l enregistrement : ' . $exception->getMessage();
                $messageType = 'erreur';
            }
        }

        mysqli_close($connexion);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposer un trajet</title>
    <?php $styleVersion = file_exists(__DIR__ . '/proposer_trajet.css') ? filemtime(__DIR__ . '/proposer_trajet.css') : time(); ?>
    <link rel="stylesheet" href="proposer_trajet.css?v=<?= $styleVersion; ?>">
    <style>
        .logo-overlay {
            position: absolute;
            top: 10px;
            left: 40px;
            width: 180px;
            z-index: 9999;
            filter: drop-shadow(0px 4px 8px rgba(0,0,0,0.6));
            pointer-events: none;
        }

        .main-nav {
            background-color: #003366;
            min-height: 70px;
            position: relative;
            z-index: 1000;
            box-shadow: 0 3px 0 0 #FFD700;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: flex-end;
            padding: 0 20px;
            height: 70px;
            align-items: center;
        }

        .menu-links {
            list-style: none;
            display: flex;
            margin: 0;
            padding: 0;
        }

        .menu-links li {
            list-style: none;
        }

        .menu-links li a {
            text-decoration: none;
            color: white;
            font-weight: bold;
            padding: 10px 15px;
            font-size: 0.85rem;
            transition: 0.3s;
        }

        .menu-links li a:hover {
            color: #FFD700;
        }

        @media (max-width: 760px) {
            .logo-overlay {
                width: 92px;
                left: 14px;
                top: 8px;
            }

            .main-nav {
                min-height: auto;
            }

            .nav-container {
                justify-content: center;
                height: auto;
                padding: 14px 10px;
            }

            .menu-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
            }

            .menu-links li a {
                display: block;
                padding: 8px 10px;
                font-size: 0.73rem;
            }
        }
    </style>
</head>

<body class="offer-page">
    <img src="../logo_FCSM.png" alt="Logo FCSM" class="logo-overlay">

    <header>
        <nav class="main-nav">
            <div class="nav-container">
                <ul class="menu-links">
                    <li><a href="demandeur/dem.php">TROUVER UN TRAJET</a></li>
                    <li><a href="proposer_trajet.php">PROPOSER UN TRAJET</a></li>
                    <li><a href="../reserv.php">MES RÉSERVATIONS</a></li>
                    <li><a href="../trajet.php">MES TRAJETS</a></li>
                    <?php if (empty($sessionUser)): ?>
                        <li><a href="../connexion.php">CONNEXION</a></li>
                    <?php else: ?>
                        <li><a href="../deconnexion.php">DÉCONNEXION</a></li>
                    <?php endif; ?>
                    <li><a href="../index.php">AIDE</a></li>
                    <li>
                        <div id="google_translate_element"></div>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <main class="page">
        <section class="offer-layout">
            <section class="carte">
                <div class="carte-header">
                    <span class="carte-kicker">formulaire conducteur</span>
                    <h2>Configurer votre offre</h2>
                    <p>Les informations personnelles restent liees a votre session. Ici, vous renseignez seulement le vehicule et le trajet.</p>
                </div>

                <?php if ($message !== ''): ?>
                    <p class="message <?= e($messageType); ?>"><?= e($message); ?></p>
                <?php endif; ?>

                <!--
                    Le formulaire offreur ne montre plus la partie eleve.
                    Les informations personnelles viennent uniquement de la session.
                    L utilisateur remplit ici seulement le vehicule et le trajet.
                -->
                <form class="class" action="" method="post" id="trajet-form">
                    <section class="section-formulaire">
                        <div class="section-heading">
                            <span class="section-index">01</span>
                            <div>
                                <h3>Voiture</h3>
                                <p>Decrivez le vehicule avec lequel vous proposez le trajet.</p>
                            </div>
                        </div>

                        <div class="champ">
                            <label for="type_vehicule">Type de véhicule</label>
                            <input type="text" id="type_vehicule" name="type_vehicule" placeholder="Exemple : citadine" value="<?= e(old('type_vehicule')); ?>" required>
                        </div>

                        <div class="form-grid two-cols">
                            <div class="champ">
                                <label for="nb_place">Nombre de places</label>
                                <input type="number" id="nb_place" name="nb_place" placeholder="Exemple : 4" min="1" value="<?= e(old('nb_place')); ?>" required>
                            </div>

                            <div class="champ">
                                <label for="immatriculation">Immatriculation</label>
                                <input type="text" id="immatriculation" name="immatriculation" placeholder="Exemple : AB-123-CD" value="<?= e(old('immatriculation')); ?>">
                            </div>
                        </div>

                        <div class="form-grid two-cols">
                            <div class="champ">
                                <span class="titre-radio">Assurance</span>
                                <div class="choix-radio">
                                    <label class="option-radio" for="assurance_oui">
                                        <input type="radio" id="assurance_oui" name="assurance" value="oui" <?= old('assurance') === 'oui' ? 'checked' : ''; ?> required>
                                        <span>Oui</span>
                                    </label>

                                    <label class="option-radio" for="assurance_non">
                                        <input type="radio" id="assurance_non" name="assurance" value="non" <?= old('assurance') === 'non' ? 'checked' : ''; ?> required>
                                        <span>Non</span>
                                    </label>
                                </div>
                            </div>

                            <div class="champ">
                                <span class="titre-radio">Contrôle technique</span>
                                <div class="choix-radio">
                                    <label class="option-radio" for="controle_oui">
                                        <input type="radio" id="controle_oui" name="controle_technique" value="oui" <?= old('controle_technique') === 'oui' ? 'checked' : ''; ?> required>
                                        <span>Oui</span>
                                    </label>

                                    <label class="option-radio" for="controle_non">
                                        <input type="radio" id="controle_non" name="controle_technique" value="non" <?= old('controle_technique') === 'non' ? 'checked' : ''; ?> required>
                                        <span>Non</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="section-formulaire">
                        <div class="section-heading">
                            <span class="section-index">02</span>
                            <div>
                                <h3>Trajet</h3>
                                <p>Choisissez le depart, l'horaire et la destination de votre offre.</p>
                            </div>
                        </div>

                        <?php if ($needsDepartureFields): ?>
                            <div class="champ">
                                <label for="departure_address">Adresse de depart</label>
                                <input
                                    type="text"
                                    id="departure_address"
                                    name="departure_address"
                                    placeholder="Exemple : 12 rue de la Gare"
                                    value="<?= e(old('departure_address')); ?>"
                                    required
                                >
                            </div>

                            <div class="champ">
                                <label for="departure_city">Ville de depart</label>
                                <input
                                    type="text"
                                    id="departure_city"
                                    name="departure_city"
                                    placeholder="Exemple : Montbeliard"
                                    value="<?= e(old('departure_city')); ?>"
                                    required
                                >
                            </div>
                        <?php endif; ?>

                        <div class="form-grid three-cols">
                            <div class="champ">
                                <label for="jour">Jour</label>
                                <select id="jour" name="jour" required>
                                    <option value="">Choisir un jour</option>
                                    <option value="lundi" <?= old('jour') === 'lundi' ? 'selected' : ''; ?>>Lundi</option>
                                    <option value="mardi" <?= old('jour') === 'mardi' ? 'selected' : ''; ?>>Mardi</option>
                                    <option value="mercredi" <?= old('jour') === 'mercredi' ? 'selected' : ''; ?>>Mercredi</option>
                                    <option value="jeudi" <?= old('jour') === 'jeudi' ? 'selected' : ''; ?>>Jeudi</option>
                                    <option value="vendredi" <?= old('jour') === 'vendredi' ? 'selected' : ''; ?>>Vendredi</option>
                                    <option value="samedi" <?= old('jour') === 'samedi' ? 'selected' : ''; ?>>Samedi</option>
                                    <option value="dimanche" <?= old('jour') === 'dimanche' ? 'selected' : ''; ?>>Dimanche</option>
                                </select>
                            </div>

                            <div class="champ">
                                <label for="date_trajet">Date</label>
                                <input type="date" id="date_trajet" name="date_trajet" value="<?= e(old('date_trajet')); ?>" required>
                            </div>

                            <div class="champ">
                                <label for="prix">Prix demandé</label>
                                <input type="number" id="prix" name="prix" placeholder="Exemple : 10" min="0" step="0.01" value="<?= e(old('prix')); ?>" required>
                            </div>
                        </div>

                        <div class="form-grid two-cols">
                            <div class="champ">
                                <label for="heure_depart">Heure de départ</label>
                                <input type="time" id="heure_depart" name="heure_depart" value="<?= e(old('heure_depart')); ?>" required>
                            </div>

                            <div class="champ">
                                <label for="heure_arrivee">Heure d arrivée</label>
                                <input type="time" id="heure_arrivee" name="heure_arrivee" value="<?= e(old('heure_arrivee')); ?>" required>
                            </div>
                        </div>

                        <div class="champ">
                            <span class="titre-radio">Destination</span>
                            <div class="choix-radio">
                                <label class="option-radio" for="destination_stade_bonal">
                                    <input type="radio" id="destination_stade_bonal" name="destination_choice" value="stade_bonal" <?= old('destination_choice') !== 'event_stade_bonal' ? 'checked' : ''; ?> required>
                                    <span>Stade Bonal</span>
                                </label>

                                <label class="option-radio" for="destination_event_stade_bonal">
                                    <input type="radio" id="destination_event_stade_bonal" name="destination_choice" value="event_stade_bonal" <?= old('destination_choice') === 'event_stade_bonal' ? 'checked' : ''; ?> required>
                                    <span>Evenement</span>
                                </label>
                            </div>
                        </div>

                        <div id="event-destination-fields" <?= old('destination_choice') === 'event_stade_bonal' ? '' : 'hidden'; ?>>
                            <div class="champ">
                                <label for="destination_address">Adresse de l evenement</label>
                                <input type="text" id="destination_address" name="destination_address" placeholder="Exemple : 12 rue de la Gare" value="<?= e(old('destination_address')); ?>">
                            </div>

                            <div class="champ">
                                <label for="destination_city">Ville de l evenement</label>
                                <input type="text" id="destination_city" name="destination_city" placeholder="Exemple : Montbeliard" value="<?= e(old('destination_city')); ?>">
                            </div>
                        </div>

                        <input type="hidden" id="destination_latitude" name="destination_latitude" value="<?= e(old('destination_latitude')); ?>">
                        <input type="hidden" id="destination_longitude" name="destination_longitude" value="<?= e(old('destination_longitude')); ?>">
                        <input type="hidden" id="departure_latitude" name="departure_latitude" value="<?= e(old('departure_latitude')); ?>">
                        <input type="hidden" id="departure_longitude" name="departure_longitude" value="<?= e(old('departure_longitude')); ?>">
                    </section>

                    <button type="submit">Enregistrer l offre</button>
                </form>
            </section>
        </section>
    </main>
    <script>
        const trajetForm = document.getElementById('trajet-form');
        const destinationChoiceInputs = document.querySelectorAll('input[name="destination_choice"]');
        const eventFields = document.getElementById('event-destination-fields');
        const destinationAddressInput = document.getElementById('destination_address');
        const destinationCityInput = document.getElementById('destination_city');
        const destinationLatitudeInput = document.getElementById('destination_latitude');
        const destinationLongitudeInput = document.getElementById('destination_longitude');
        const departureAddressInput = document.getElementById('departure_address');
        const departureCityInput = document.getElementById('departure_city');
        const departureLatitudeInput = document.getElementById('departure_latitude');
        const departureLongitudeInput = document.getElementById('departure_longitude');
        const needsDepartureCoordinates = <?= $sessionLatitude === '' || $sessionLongitude === '' ? 'true' : 'false'; ?>;
        let submitWithDestinationCoordinates = false;

        function updateDestinationFields() {
            const selectedChoice = document.querySelector('input[name="destination_choice"]:checked')?.value;
            const isEventDestination = selectedChoice === 'event_stade_bonal';

            eventFields.hidden = !isEventDestination;
            destinationAddressInput.required = isEventDestination;
            destinationCityInput.required = isEventDestination;

            if (!isEventDestination) {
                destinationLatitudeInput.value = '';
                destinationLongitudeInput.value = '';
            }
        }

        async function geocodeAddress(address) {
            const url = new URL('https://nominatim.openstreetmap.org/search');
            url.searchParams.set('format', 'json');
            url.searchParams.set('limit', '1');
            url.searchParams.set('q', address);

            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Le service de geocodage est indisponible.');
            }

            const data = await response.json();

            if (!Array.isArray(data) || data.length === 0) {
                throw new Error('Adresse de destination introuvable.');
            }

            return {
                lat: data[0].lat,
                lng: data[0].lon
            };
        }

        destinationChoiceInputs.forEach((input) => {
            input.addEventListener('change', updateDestinationFields);
        });

        updateDestinationFields();

        trajetForm.addEventListener('submit', async function (event) {
            const selectedChoice = document.querySelector('input[name="destination_choice"]:checked')?.value;
            const mustGeocodeDeparture = needsDepartureCoordinates && departureLatitudeInput && departureLongitudeInput;

            if (submitWithDestinationCoordinates) {
                return;
            }

            const needsDestinationCoordinates = selectedChoice === 'event_stade_bonal';

            if (!mustGeocodeDeparture && !needsDestinationCoordinates) {
                return;
            }

            try {
                event.preventDefault();

                if (mustGeocodeDeparture) {
                    const departureAddress = departureAddressInput ? departureAddressInput.value.trim() : <?= json_encode($sessionAdresse); ?>;
                    const departureCity = departureCityInput ? departureCityInput.value.trim() : <?= json_encode($sessionVille); ?>;
                    const departureText = `${departureAddress}, ${departureCity}, France`;
                    const departureCoordinates = await geocodeAddress(departureText);
                    departureLatitudeInput.value = departureCoordinates.lat;
                    departureLongitudeInput.value = departureCoordinates.lng;
                }

                if (needsDestinationCoordinates) {
                    const destinationText = `${destinationAddressInput.value.trim()}, ${destinationCityInput.value.trim()}, France`;
                    const coordinates = await geocodeAddress(destinationText);
                    destinationLatitudeInput.value = coordinates.lat;
                    destinationLongitudeInput.value = coordinates.lng;
                }

                submitWithDestinationCoordinates = true;
                trajetForm.submit();
            } catch (error) {
                alert(error.message);
            }
        });
    </script>

    <script>
    function googleTranslateElementInit() {
        new google.translate.TranslateElement({
            pageLanguage: 'fr',
            includedLanguages: 'fr,en',
            layout: google.translate.TranslateElement.InlineLayout.SIMPLE
        }, 'google_translate_element');
    }
    </script>

    <script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</body>

</html>