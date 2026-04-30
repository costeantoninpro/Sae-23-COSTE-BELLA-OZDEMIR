<?php
require_once __DIR__ . '/session_utils.php';

// Paramètres de connexion
$dbHost = '127.0.0.1';
$dbUser = 'u129649329_root';
$dbPassword = '2tM7XQZDmjfJCKJz';
$dbName = 'u129649329_sae';

// Vérification de la session
$user = requireSessionUser('trajet.php');
$sessionUser = getSessionUser();
$message = '';
$messageType = '';
$trajets = [];

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatTripDate($value)
{
    if (!$value) {
        return 'Date non renseignee';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date('d/m/Y', $timestamp);
}

function formatTripTime($value)
{
    if (!$value) {
        return 'Non renseignee';
    }

    return substr((string) $value, 0, 5);
}

function prepareAndExecute($connexion, $sql, $types = '', $values = [])
{
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

function normalizeValidationStatus($value)
{
    $status = trim((string) $value);
    if ($status === 'acceptee' || $status === 'refusee') {
        return $status;
    }
    return 'en_attente';
}

function formatValidationLabel($value)
{
    $status = normalizeValidationStatus($value);
    if ($status === 'acceptee') return 'Acceptée';
    if ($status === 'refusee') return 'Refusée';
    return 'En attente';
}

function shouldShowDecisionButtons($value)
{
    return normalizeValidationStatus($value) === 'en_attente';
}

function canOpenParticipantChat($value)
{
    return normalizeValidationStatus($value) !== 'refusee';
}

function computeOccupiedPlaces($participants)
{
    $count = 0;
    foreach ($participants as $participant) {
        if (normalizeValidationStatus($participant['validation'] ?? '') === 'acceptee') {
            $count++;
        }
    }
    return $count;
}

$connexion = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$connexion) {
    $message = 'Connexion à la base impossible.';
} else {
    mysqli_set_charset($connexion, 'utf8mb4');
    $idPersonne = (int) ($user['id_personne'] ?? 0);

    try {
        if ($idPersonne <= 0) {
            throw new Exception('Impossible de retrouver la personne connectée.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $participationId = (int) ($_POST['participation_id'] ?? 0);
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            $decision = trim($_POST['decision'] ?? '');

            if ($participationId <= 0 || $offerId <= 0 || !in_array($decision, ['acceptee', 'refusee'], true)) {
                throw new Exception('Action sur la demande invalide.');
            }

            mysqli_begin_transaction($connexion);

            $statementParticipation = prepareAndExecute(
                $connexion,
                "SELECT part.id_participation, part.validation, od.places_proposees
                 FROM participation part
                 INNER JOIN offre_demande od ON part.id_offre_demande = od.id_offre_demande
                 WHERE part.id_participation = ?
                   AND part.id_offre_demande = ?
                   AND od.id_personne = ?
                   AND od.type = 'offre'
                 LIMIT 1",
                'iii',
                [$participationId, $offerId, $idPersonne]
            );
            $resultParticipation = mysqli_stmt_get_result($statementParticipation);

            if (!$resultParticipation || mysqli_num_rows($resultParticipation) === 0) {
                mysqli_stmt_close($statementParticipation);
                throw new Exception('Cette demande est introuvable pour ce trajet.');
            }

            $participation = mysqli_fetch_assoc($resultParticipation);
            mysqli_stmt_close($statementParticipation);

            $placesProposees = (int) ($participation['places_proposees'] ?? 0);

            if ($decision === 'acceptee') {
                $statementAcceptedCount = prepareAndExecute(
                    $connexion,
                    "SELECT COUNT(*) AS accepted_count
                     FROM participation
                     WHERE id_offre_demande = ?
                       AND validation = 'acceptee'
                       AND id_participation <> ?",
                    'ii',
                    [$offerId, $participationId]
                );
                $resultAcceptedCount = mysqli_stmt_get_result($statementAcceptedCount);
                $acceptedRow = $resultAcceptedCount ? mysqli_fetch_assoc($resultAcceptedCount) : null;
                mysqli_stmt_close($statementAcceptedCount);

                $acceptedCount = (int) ($acceptedRow['accepted_count'] ?? 0);

                if ($placesProposees > 0 && $acceptedCount >= $placesProposees) {
                    throw new Exception('Toutes les places de ce trajet sont deja attribuees.');
                }
            }

            prepareAndExecute(
                $connexion,
                "UPDATE participation SET validation = ? WHERE id_participation = ?",
                'si',
                [$decision, $participationId]
            );

            if ($decision === 'acceptee' && $placesProposees > 0) {
                $statementAcceptedAfterUpdate = prepareAndExecute(
                    $connexion,
                    "SELECT COUNT(*) AS accepted_count
                     FROM participation
                     WHERE id_offre_demande = ?
                       AND validation = 'acceptee'",
                    'i',
                    [$offerId]
                );
                $resultAcceptedAfterUpdate = mysqli_stmt_get_result($statementAcceptedAfterUpdate);
                $acceptedAfterUpdateRow = $resultAcceptedAfterUpdate ? mysqli_fetch_assoc($resultAcceptedAfterUpdate) : null;
                mysqli_stmt_close($statementAcceptedAfterUpdate);

                $acceptedAfterUpdate = (int) ($acceptedAfterUpdateRow['accepted_count'] ?? 0);

                if ($acceptedAfterUpdate >= $placesProposees) {
                    prepareAndExecute(
                        $connexion,
                        "UPDATE participation
                         SET validation = 'refusee'
                         WHERE id_offre_demande = ?
                           AND id_participation <> ?
                           AND (validation IS NULL OR validation = 'en_attente' OR validation = '')",
                        'ii',
                        [$offerId, $participationId]
                    );
                }
            }

            mysqli_commit($connexion);

            $message = $decision === 'acceptee'
                ? 'Le demandeur a ete accepte et les autres demandes en attente ont ete refusees si le trajet est complet.'
                : 'Le demandeur a ete refuse.';
            $messageType = 'succes';
        }

        // Récupération des trajets
        $sqlTrajets = "
            SELECT od.id_offre_demande, od.statut, od.places_proposees, od.prix,
                   veh.type_vehicule, veh.immatriculation, veh.nb_places,
                   lieu.adresse, lieu.ville, dt.jour, dt.date_trajet, dt.heure_depart, dt.heure_arrivee
            FROM offre_demande od
            INNER JOIN lieu ON od.id_lieu = lieu.id_lieu
            LEFT JOIN date_trajet dt ON od.id_date = dt.id_date
            LEFT JOIN vehicule veh ON od.id_vehicule = veh.id_vehicule
            WHERE od.id_personne = ? AND od.type = 'offre'
            ORDER BY od.id_offre_demande DESC";

        $statementTrajets = prepareAndExecute($connexion, $sqlTrajets, 'i', [$idPersonne]);
        $resultTrajets = mysqli_stmt_get_result($statementTrajets);

        while ($trajet = mysqli_fetch_assoc($resultTrajets)) {
            $trajet['participants'] = [];
            $trajet['accepted_count'] = 0; $trajet['pending_count'] = 0; $trajet['refused_count'] = 0;
            $trajets[(int) $trajet['id_offre_demande']] = $trajet;
        }
        mysqli_stmt_close($statementTrajets);

        if (!empty($trajets)) {
            $offerIds = array_keys($trajets);
            $placeholders = implode(',', array_fill(0, count($offerIds), '?'));
            $sqlParticipants = "SELECT
                                    part.*,
                                    dem.nom,
                                    dem.prenom,
                                    dem.email,
                                    dem.telephone,
                                    (
                                        SELECT l.adresse
                                        FROM offre_demande od_dem
                                        INNER JOIN lieu l ON l.id_lieu = od_dem.id_lieu
                                        WHERE od_dem.id_personne = dem.id_personne
                                          AND od_dem.id_lieu IS NOT NULL
                                        ORDER BY od_dem.id_offre_demande DESC
                                        LIMIT 1
                                    ) AS adresse,
                                    (
                                        SELECT l.ville
                                        FROM offre_demande od_dem
                                        INNER JOIN lieu l ON l.id_lieu = od_dem.id_lieu
                                        WHERE od_dem.id_personne = dem.id_personne
                                          AND od_dem.id_lieu IS NOT NULL
                                        ORDER BY od_dem.id_offre_demande DESC
                                        LIMIT 1
                                    ) AS ville
                                FROM participation part
                                INNER JOIN personne dem ON part.id_personne = dem.id_personne
                                WHERE part.id_offre_demande IN ($placeholders) AND part.role = 'demandeur'";

            $statementParticipants = prepareAndExecute($connexion, $sqlParticipants, str_repeat('i', count($offerIds)), $offerIds);
            $resultParticipants = mysqli_stmt_get_result($statementParticipants);

            while ($part = mysqli_fetch_assoc($resultParticipants)) {
                $oid = (int) $part['id_offre_demande'];
                $part['validation'] = normalizeValidationStatus($part['validation']);
                $trajets[$oid]['participants'][] = $part;
                if ($part['validation'] === 'acceptee') $trajets[$oid]['accepted_count']++;
                elseif ($part['validation'] === 'refusee') $trajets[$oid]['refused_count']++;
                else $trajets[$oid]['pending_count']++;
            }
            mysqli_stmt_close($statementParticipants);
            foreach ($trajets as &$t) { $t['occupied_places'] = computeOccupiedPlaces($t['participants']); }
        }
    } catch (Exception $e) {
        if (mysqli_errno($connexion)) {
            mysqli_rollback($connexion);
        }
        $message = $e->getMessage(); $messageType = 'erreur';
    }
    mysqli_close($connexion);
}

// Gestion du cache CSS
$styleFile = 'style2.css';
$styleVersion = file_exists(__DIR__ . '/' . $styleFile) ? filemtime(__DIR__ . '/' . $styleFile) : time();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes trajets - FCSM</title>
    <link rel="stylesheet" href="<?= $styleFile; ?>?v=<?= $styleVersion; ?>">
</head>
<body class="reservations-showcase trips-showcase">

    <img src="logo_FCSM.png" alt="Logo FCSM" class="logo-overlay">

    <header>
        <nav class="main-nav">
            <div class="nav-container">
                <ul class="menu-links">
                    <li><a href="Form/demandeur/dem.php">TROUVER UN TRAJET</a></li>
                    <li><a href="Form/proposer_trajet.php">PROPOSER UN TRAJET</a></li>
                    <li><a href="reserv.php">MES RÉSERVATIONS</a></li>
                    <li><a href="trajet.php">MES TRAJETS</a></li>
                    <?php if (empty($sessionUser)): ?>
                        <li><a href="connexion.php">CONNEXION</a></li>
                    <?php else: ?>
                        <li><a href="deconnexion.php">DÉCONNEXION</a></li>
                    <?php endif; ?>
                    <li><a href="index.php">AIDE</a></li>
                    <li>
                        <div id="google_translate_element"></div>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <section class="hero-banner">
        <video autoplay muted loop playsinline class="hero-background">
            <source src="baniere_sochaux2.mp4" type="video/mp4">
            Votre navigateur ne supporte pas la vidéo.
        </video>
    </section>

    <main class="main-content reservation-page">
        <section class="match-showcase">
            <div class="showcase-shell">
                <aside class="showcase-intro">
                    <span class="showcase-kicker">FCSM Covoiturage</span>
                    <h1>Mes trajets</h1>
                    <p>
                        Chaque carte represente un trajet propose. Les demandes des passagers
                        sont integrees dans la carte et les boutons permettent de les accepter
                        ou de les refuser directement.
                    </p>
                    <div class="showcase-actions">
                        <a href="Form/proposer_trajet.php" class="showcase-link">Proposer un trajet</a>
                        <a href="reserv.php" class="showcase-link alt">Voir mes reservations</a>
                    </div>
                </aside>

                <div class="showcase-stage">
                    <h2 class="showcase-title">Trajets proposes</h2>

                    <?php if ($message !== ''): ?>
                        <div class="showcase-flash <?= e($messageType); ?>">
                            <p><?= e($message); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($trajets)): ?>
                        <div class="reservation-slider" id="trajet-slider">
                            <?php foreach ($trajets as $index => $trajet): ?>
                                <article class="match-card reservation-card-showcase trip-card-showcase">
                                    <div class="match-card-topline"></div>
                                    <div class="match-card-logos">
                                        <img src="logo_FCSM.png" alt="Logo FCSM" class="club-logo-card">
                                        <div class="versus-badge">T<?= $index + 1; ?></div>
                                    </div>

                                    <div class="match-card-body">
                                        <p class="match-card-label">Trajet propose</p>
                                        <h3><?= e($trajet['ville']); ?></h3>
                                        <p class="match-card-route"><?= e($trajet['adresse']); ?></p>

                                        <div class="match-card-meta">
                                            <p><strong>Date</strong> <?= e(formatTripDate($trajet['date_trajet'])); ?><?= $trajet['jour'] !== '' ? ' - ' . e($trajet['jour']) : ''; ?></p>
                                            <p><strong>Depart</strong> <?= e(formatTripTime($trajet['heure_depart'])); ?></p>
                                            <p><strong>Arrivee</strong> <?= e(formatTripTime($trajet['heure_arrivee'])); ?></p>
                                            <p><strong>Vehicule</strong> <?= e($trajet['type_vehicule']); ?></p>
                                            <p><strong>Immatriculation</strong> <?= e($trajet['immatriculation']); ?></p>
                                            <p><strong>Places</strong> <?= e($trajet['occupied_places']); ?> / <?= e($trajet['places_proposees']); ?></p>
                                            <p><strong>Prix</strong> <?= e($trajet['prix']); ?></p>
                                        </div>

                                        <div class="participant-zone">
                                            <h4>Demandes recues</h4>

                                            <?php if (!empty($trajet['participants'])): ?>
                                                <div class="participant-stack">
                                                    <?php foreach ($trajet['participants'] as $p): ?>
                                                        <section class="participant-card">
                                                            <div class="participant-card-header">
                                                                <div>
                                                                    <h5><?= e($p['prenom'] . ' ' . $p['nom']); ?></h5>
                                                                    <p><?= e($p['telephone']); ?></p>
                                                                    <p><?= e($p['email']); ?></p>
                                                                    <?php if (trim((string) ($p['adresse'] ?? '')) !== '' || trim((string) ($p['ville'] ?? '')) !== ''): ?>
                                                                        <p><?= e(trim((string) ($p['adresse'] ?? '') . (trim((string) ($p['ville'] ?? '')) !== '' ? ', ' . (string) $p['ville'] : ''))); ?></p>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php $participantStatus = normalizeValidationStatus($p['validation']); ?>
                                                                <div class="participant-card-aside">
                                                                    <span class="status-pill participant-status participant-status-<?= e($participantStatus); ?>"><?= e(formatValidationLabel($p['validation'])); ?></span>
                                                                    <?php if (canOpenParticipantChat($p['validation'])): ?>
                                                                        <a
                                                                            href="chat.php?id_offre_demande=<?= e($trajet['id_offre_demande']); ?>&amp;id_participation=<?= e($p['id_participation']); ?>"
                                                                            class="participant-chat-link"
                                                                        >Discuter</a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>

                                                            <?php if (shouldShowDecisionButtons($p['validation'])): ?>
                                                                <form method="post" action="trajet.php" class="participant-actions">
                                                                    <input type="hidden" name="offer_id" value="<?= e($trajet['id_offre_demande']); ?>">
                                                                    <input type="hidden" name="participation_id" value="<?= e($p['id_participation']); ?>">
                                                                    <button type="submit" name="decision" value="acceptee" class="decision-button accept-button">Accepter</button>
                                                                    <button type="submit" name="decision" value="refusee" class="decision-button refuse-button">Refuser</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </section>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <p class="participant-empty">Aucun passager pour le moment.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <section class="empty-showcase-card">
                            <h3>Aucun trajet propose</h3>
                            <p>Vous n avez propose aucun trajet.</p>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <script>
    (function () {
        const slider = document.getElementById("trajet-slider");

        if (!slider) {
            return;
        }

        slider.addEventListener("wheel", (event) => {
            if (!event.shiftKey) {
                return;
            }

            event.preventDefault();
            slider.scrollBy({
                left: event.deltaY,
                behavior: "smooth"
            });
        }, { passive: false });
    }());
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