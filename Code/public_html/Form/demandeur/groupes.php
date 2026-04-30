<?php
header('Content-Type: application/json; charset=utf-8');

const STADE_BONAL_ADDRESS = 'Impasse de la Forge';
const STADE_BONAL_CITY = 'Montbeliard';
const STADE_BONAL_LATITUDE = 47.512311;
const STADE_BONAL_LONGITUDE = 6.811345;

$dbHost = '127.0.0.1';
$dbUser = 'u129649329_root';
$dbPassword = '2tM7XQZDmjfJCKJz';
$dbName = 'u129649329_sae';
$maxDistance = 60;

function jsonError($message)
{
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
    exit;
}

function haversineDistance($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

$connexion = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$connexion) {
    jsonError('Connexion a la base impossible.');
}

mysqli_set_charset($connexion, 'utf8mb4');

$sqlOffres = "
    SELECT
        od.id_offre_demande,
        p.nom,
        p.prenom,
        p.telephone,
        l.adresse,
        l.ville,
        l.latitude,
        l.longitude,
        dt.date_trajet,
        dt.heure_depart,
        dt.heure_arrivee,
        lieu_destination.adresse AS destination_adresse,
        lieu_destination.ville AS destination_ville,
        lieu_destination.latitude AS destination_latitude,
        lieu_destination.longitude AS destination_longitude,
        od.prix,
        od.places_proposees,
        SUM(
            CASE
                WHEN part.id_participation IS NOT NULL
                     AND part.validation = 'acceptee'
                THEN 1
                ELSE 0
            END
        ) AS nb_participants
    FROM offre_demande od
    INNER JOIN personne p ON od.id_personne = p.id_personne
    INNER JOIN lieu l ON od.id_lieu = l.id_lieu
    LEFT JOIN lieu lieu_destination ON od.id_lieu_destination = lieu_destination.id_lieu
    LEFT JOIN date_trajet dt ON od.id_date = dt.id_date
    LEFT JOIN participation part ON od.id_offre_demande = part.id_offre_demande
    WHERE od.type = 'offre'
      AND l.latitude IS NOT NULL
      AND l.longitude IS NOT NULL
    GROUP BY
        od.id_offre_demande,
        p.nom,
        p.prenom,
        p.telephone,
        l.adresse,
        l.ville,
        l.latitude,
        l.longitude,
        dt.date_trajet,
        dt.heure_depart,
        dt.heure_arrivee,
        lieu_destination.adresse,
        lieu_destination.ville,
        lieu_destination.latitude,
        lieu_destination.longitude,
        od.prix,
        od.places_proposees
    HAVING COALESCE(nb_participants, 0) < od.places_proposees
    ORDER BY od.id_offre_demande DESC
";

$resultatOffres = mysqli_query($connexion, $sqlOffres);

if (!$resultatOffres) {
    $error = mysqli_error($connexion);
    mysqli_close($connexion);
    jsonError('Erreur SQL offres : ' . $error);
}

$offres = [];

while ($ligne = mysqli_fetch_assoc($resultatOffres)) {
    // On prepare ici toutes les donnees utiles pour le panneau a droite.
    // Si un champ manque plus tard, c est ce tableau qu il faudra completer.
    $placesProposees = (int) $ligne['places_proposees'];
    $nbParticipants = (int) $ligne['nb_participants'];
    $destinationAdresse = trim((string) ($ligne['destination_adresse'] ?? ''));
    $destinationVille = trim((string) ($ligne['destination_ville'] ?? ''));
    $destinationLatitude = $ligne['destination_latitude'];
    $destinationLongitude = $ligne['destination_longitude'];
    $hasDestinationLieu = $destinationAdresse !== '' || $destinationVille !== '';

    if (!$hasDestinationLieu) {
        $destinationType = 'stade_bonal';
        $destinationLabel = 'Stade Bonal';
        $destinationAdresse = STADE_BONAL_ADDRESS;
        $destinationVille = STADE_BONAL_CITY;
        $destinationLatitude = STADE_BONAL_LATITUDE;
        $destinationLongitude = STADE_BONAL_LONGITUDE;
    } else {
        $isStadeBonal =
            strtolower($destinationAdresse) === strtolower(STADE_BONAL_ADDRESS) &&
            strtolower($destinationVille) === strtolower(STADE_BONAL_CITY);

        $destinationType = $isStadeBonal ? 'stade_bonal' : 'event_stade_bonal';
        $destinationLabel = $isStadeBonal
            ? 'Stade Bonal'
            : trim($destinationAdresse . ($destinationVille !== '' ? ', ' . $destinationVille : ''));
    }

    $offres[] = [
        'id_offre_demande' => (int) $ligne['id_offre_demande'],
        'nom' => $ligne['nom'],
        'prenom' => $ligne['prenom'],
        'telephone' => $ligne['telephone'],
        'adresse' => $ligne['adresse'],
        'ville' => $ligne['ville'],
        'latitude' => (float) $ligne['latitude'],
        'longitude' => (float) $ligne['longitude'],
        'date_trajet' => $ligne['date_trajet'],
        'heure_depart' => $ligne['heure_depart'],
        'heure_arrivee' => $ligne['heure_arrivee'],
        'destination_type' => $destinationType,
        'destination_label' => $destinationLabel,
        'destination_adresse' => $destinationAdresse,
        'destination_ville' => $destinationVille,
        'destination_latitude' => $destinationLatitude === null ? null : (float) $destinationLatitude,
        'destination_longitude' => $destinationLongitude === null ? null : (float) $destinationLongitude,
        'prix' => $ligne['prix'],
        'places_proposees' => $placesProposees,
        'nb_participants' => $nbParticipants,
    ];
}

if (!mysqli_query($connexion, 'DELETE FROM groupes_offreurs')) {
    $error = mysqli_error($connexion);
    mysqli_close($connexion);
    jsonError('Erreur SQL suppression groupes : ' . $error);
}

$groupes = [];

foreach ($offres as $offre) {
    $nearestGroupIndex = null;
    $nearestDistance = null;

    foreach ($groupes as $index => $groupe) {
        $distance = haversineDistance(
            $offre['latitude'],
            $offre['longitude'],
            $groupe['center_lat'],
            $groupe['center_lng']
        );

        if ($distance <= $maxDistance && ($nearestDistance === null || $distance < $nearestDistance)) {
            $nearestDistance = $distance;
            $nearestGroupIndex = $index;
        }
    }

    if ($nearestGroupIndex === null) {
        $groupes[] = [
            'center_lat' => $offre['latitude'],
            'center_lng' => $offre['longitude'],
            'address_count' => 1,
            'offers' => [$offre],
        ];
        continue;
    }

    $groupe = $groupes[$nearestGroupIndex];
    $newCount = $groupe['address_count'] + 1;

    $groupes[$nearestGroupIndex]['center_lat'] =
        (($groupe['center_lat'] * $groupe['address_count']) + $offre['latitude']) / $newCount;
    $groupes[$nearestGroupIndex]['center_lng'] =
        (($groupe['center_lng'] * $groupe['address_count']) + $offre['longitude']) / $newCount;
    $groupes[$nearestGroupIndex]['address_count'] = $newCount;
    $groupes[$nearestGroupIndex]['offers'][] = $offre;
}

$resultGroups = [];

foreach ($groupes as $groupe) {
    $centerLat = (float) $groupe['center_lat'];
    $centerLng = (float) $groupe['center_lng'];
    $addressCount = (int) $groupe['address_count'];

    $sqlInsertGroup = sprintf(
        "INSERT INTO groupes_offreurs (center_lat, center_lng, address_count, created_at, updated_at)
         VALUES (%.7F, %.7F, %d, NOW(), NOW())",
        $centerLat,
        $centerLng,
        $addressCount
    );

    if (!mysqli_query($connexion, $sqlInsertGroup)) {
        $error = mysqli_error($connexion);
        mysqli_close($connexion);
        jsonError('Erreur SQL insertion groupes : ' . $error);
    }

    $resultGroups[] = [
        'id' => (int) mysqli_insert_id($connexion),
        'center_lat' => $centerLat,
        'center_lng' => $centerLng,
        'address_count' => $addressCount,
        'offers' => $groupe['offers'],
    ];
}

mysqli_close($connexion);

echo json_encode([
    'success' => true,
    'groups' => $resultGroups,
    'total_offers' => count($offres),
]);
