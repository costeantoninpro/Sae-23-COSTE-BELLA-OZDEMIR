<?php
require_once __DIR__ . '/../../session_utils.php';

header('Content-Type: application/json; charset=utf-8');

$dbHost = '127.0.0.1';
$dbUser = 'u129649329_root';
$dbPassword = '2tM7XQZDmjfJCKJz';
$dbName = 'u129649329_sae';

function jsonResponse($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

function jsonError($message, $statusCode = 400)
{
    http_response_code($statusCode);
    jsonResponse(false, $message);
}

function prepareAndExecute($connexion, $sql, $types = '', $values = [])
{
    // Cette fonction centralise les requetes preparees pour garder le code lisible.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Methode non autorisee.', 405);
}

$user = requireSessionUser('Form/demandeur/dem.php');
startAppSession();
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
$selectedOfferId = (int) ($payload['selected_offer_id'] ?? 0);

if ($selectedOfferId <= 0) {
    jsonError('Offre selectionnee invalide.');
}

$connexion = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$connexion) {
    jsonError('Connexion a la base impossible.', 500);
}

mysqli_set_charset($connexion, 'utf8mb4');

$idPersonne = (int) ($user['id_personne'] ?? 0);
$adresse = trim($user['adresse'] ?? '');
$ville = trim($user['ville'] ?? '');
$latitude = trim($user['latitude'] ?? '');
$longitude = trim($user['longitude'] ?? '');

if (
    $idPersonne <= 0 || $adresse === '' || $ville === ''
) {
    mysqli_close($connexion);
    jsonError('La session utilisateur est incomplete.', 400);
}

mysqli_begin_transaction($connexion);

try {
    // ETAPE 1 :
    // On verifie que l offre cliquée existe bien avant de creer la demande.
    $statementOffre = prepareAndExecute(
        $connexion,
        "SELECT id_offre_demande, places_proposees
         FROM offre_demande
         WHERE id_offre_demande = ? AND type = 'offre'
         LIMIT 1",
        'i',
        [$selectedOfferId]
    );
    $resultOffre = mysqli_stmt_get_result($statementOffre);

    if (!$resultOffre || mysqli_num_rows($resultOffre) === 0) {
        mysqli_stmt_close($statementOffre);
        throw new Exception('L offre choisie n existe plus.');
    }

    $offre = mysqli_fetch_assoc($resultOffre);
    $placesProposees = (int) ($offre['places_proposees'] ?? 0);

    mysqli_stmt_close($statementOffre);

    // ETAPE 2 :
    // Recuperer ou creer le lieu du demandeur.
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
        prepareAndExecute(
            $connexion,
            "INSERT INTO lieu (adresse, ville, type_lieu, latitude, longitude)
             VALUES (?, ?, 'depart', ?, ?)",
            'ssdd',
            [$adresse, $ville, (float) $latitude, (float) $longitude]
        );
        $idLieu = mysqli_insert_id($connexion);
    }
    mysqli_stmt_close($statementLieu);

    // ETAPE 3 :
    // On cree la demande seulement au moment du clic sur le conducteur.
    $statementDemandeExiste = prepareAndExecute(
        $connexion,
        "SELECT id_offre_demande
         FROM offre_demande
         WHERE type = 'demande' AND id_personne = ? AND id_lieu = ? AND id_date IS NULL
         LIMIT 1",
        'ii',
        [$idPersonne, $idLieu]
    );
    $resultDemandeExiste = mysqli_stmt_get_result($statementDemandeExiste);

    if ($resultDemandeExiste && mysqli_num_rows($resultDemandeExiste) > 0) {
        $demande = mysqli_fetch_assoc($resultDemandeExiste);
        $idDemande = (int) $demande['id_offre_demande'];
    } else {
        prepareAndExecute(
            $connexion,
            "INSERT INTO offre_demande (type, sens, statut, places_proposees, prix, id_personne, id_lieu, id_date, id_vehicule)
             VALUES ('demande', 'aller', 'ouverte', 1, 0, ?, ?, NULL, NULL)",
            'ii',
            [$idPersonne, $idLieu]
        );
        $idDemande = mysqli_insert_id($connexion);
    }
    mysqli_stmt_close($statementDemandeExiste);

    // ETAPE 4 :
    // On cherche d abord si ce demandeur a deja une participation sur cette offre.
    $statementParticipationExiste = prepareAndExecute(
        $connexion,
        "SELECT id_participation, validation
         FROM participation
         WHERE id_personne = ? AND id_offre_demande = ?
         LIMIT 1",
        'ii',
        [$idPersonne, $selectedOfferId]
    );
    $resultParticipationExiste = mysqli_stmt_get_result($statementParticipationExiste);

    if ($resultParticipationExiste && mysqli_num_rows($resultParticipationExiste) > 0) {
        $participation = mysqli_fetch_assoc($resultParticipationExiste);
        $idParticipation = (int) $participation['id_participation'];
        $ancienneValidation = trim((string) ($participation['validation'] ?? ''));
    } else {
        $idParticipation = 0;
        $ancienneValidation = '';
    }
    mysqli_stmt_close($statementParticipationExiste);

    // ETAPE 5 :
    // On bloque la reservation seulement si toutes les places sont deja acceptees.
    // Les demandes en attente ne doivent pas empecher les autres candidats de postuler.
    $statementPlaces = prepareAndExecute(
        $connexion,
        "SELECT COUNT(*) AS occupied_count
         FROM participation
         WHERE id_offre_demande = ?
           AND validation = 'acceptee'
           AND (? = 0 OR id_participation <> ?)",
        'iii',
        [$selectedOfferId, $idParticipation, $idParticipation]
    );
    $resultPlaces = mysqli_stmt_get_result($statementPlaces);
    $places = $resultPlaces ? mysqli_fetch_assoc($resultPlaces) : null;
    mysqli_stmt_close($statementPlaces);

    $occupiedCount = (int) ($places['occupied_count'] ?? 0);
    $currentParticipationIsAccepted = $idParticipation > 0 && $ancienneValidation === 'acceptee';

    if (!$currentParticipationIsAccepted && $placesProposees > 0 && $occupiedCount >= $placesProposees) {
        throw new Exception('Ce trajet est deja complet.');
    }

    // ETAPE 6 :
    // On memorise ici le conducteur choisi par le demandeur.
    // La table participation sert de lien clair entre la personne connectee et l offre selectionnee.
    if ($idParticipation > 0) {
        $nouvelleValidation = $ancienneValidation === 'acceptee' ? 'acceptee' : 'en_attente';

        prepareAndExecute(
            $connexion,
            "UPDATE participation
             SET role = 'demandeur', validation = ?
             WHERE id_participation = ?",
            'si',
            [$nouvelleValidation, $idParticipation]
        );
    } else {
        prepareAndExecute(
            $connexion,
            "INSERT INTO participation (role, validation, id_personne, id_offre_demande)
             VALUES ('demandeur', 'en_attente', ?, ?)",
            'ii',
            [$idPersonne, $selectedOfferId]
        );
        $idParticipation = mysqli_insert_id($connexion);
    }

    mysqli_commit($connexion);
    mysqli_close($connexion);

    // On garde aussi le dernier choix en session pour faciliter l affichage de la reservation.
    $_SESSION['last_selected_offer_id'] = $selectedOfferId;
    $_SESSION['last_demande_id'] = $idDemande;
    $_SESSION['last_participation_id'] = $idParticipation;

    jsonResponse(true, 'La demande a bien ete enregistree.', [
        'selected_offer_id' => $selectedOfferId,
        'demande_id' => $idDemande,
        'participation_id' => $idParticipation,
    ]);
} catch (Exception $exception) {
    mysqli_rollback($connexion);
    mysqli_close($connexion);
    jsonError($exception->getMessage(), 500);
}
