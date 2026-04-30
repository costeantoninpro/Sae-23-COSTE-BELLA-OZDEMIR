<?php
require_once __DIR__ . '/session_utils.php';

// Paramètres de connexion
$dbHost = '127.0.0.1';
$dbUser = 'u129649329_root';
$dbPassword = '2tM7XQZDmjfJCKJz';
$dbName = 'u129649329_sae';

// Vérification de la session
$user = requireSessionUser('reserv.php');
$sessionUser = getSessionUser();
$message = '';
$reservations = [];

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatReservationDate($value)
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

function formatReservationTime($value)
{
    if (!$value) {
        return 'Non renseignee';
    }

    return substr((string) $value, 0, 5);
}

function formatReservationValidation($value)
{
    $status = trim((string) $value);

    if ($status === 'acceptee') {
        return 'Acceptee';
    }

    if ($status === 'refusee') {
        return 'Refusee';
    }

    return 'En attente';
}

function canOpenReservationChat($validation)
{
    return trim((string) $validation) !== 'refusee';
}

$connexion = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$connexion) {
    $message = 'Connexion a la base impossible.';
} else {
    mysqli_set_charset($connexion, 'utf8mb4');

    $idPersonne = (int) ($user['id_personne'] ?? 0);

    if ($idPersonne <= 0) {
        $message = 'Impossible de retrouver la personne connectee.';
    } else {
        $sqlReservation = "
            SELECT
                part.id_participation,
                part.validation,
                od.id_offre_demande,
                od.statut,
                od.places_proposees,
                od.prix,
                conducteur.nom AS conducteur_nom,
                conducteur.prenom AS conducteur_prenom,
                conducteur.telephone AS conducteur_telephone,
                lieu.adresse,
                lieu.ville,
                dt.date_trajet,
                dt.heure_depart,
                dt.heure_arrivee
            FROM participation part
            INNER JOIN offre_demande od ON part.id_offre_demande = od.id_offre_demande
            INNER JOIN personne conducteur ON od.id_personne = conducteur.id_personne
            INNER JOIN lieu ON od.id_lieu = lieu.id_lieu
            LEFT JOIN date_trajet dt ON od.id_date = dt.id_date
            WHERE part.id_personne = ?
              AND od.type = 'offre'
              AND part.role = 'demandeur'
            ORDER BY part.id_participation DESC
        ";

        $statementReservation = mysqli_prepare($connexion, $sqlReservation);

        if (!$statementReservation) {
            $message = mysqli_error($connexion);
        } else {
            mysqli_stmt_bind_param($statementReservation, 'i', $idPersonne);
            mysqli_stmt_execute($statementReservation);
            $resultReservation = mysqli_stmt_get_result($statementReservation);

            if ($resultReservation && mysqli_num_rows($resultReservation) > 0) {
                while ($reservation = mysqli_fetch_assoc($resultReservation)) {
                    $reservations[] = $reservation;
                }
            } else {
                $message = 'Aucune reservation n a encore ete enregistree.';
            }

            mysqli_stmt_close($statementReservation);
        }
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
    <title>Mes réservations - FCSM</title>
    <link rel="stylesheet" href="<?= $styleFile ?>?v=<?= $styleVersion; ?>">
</head>
<body class="reservations-showcase">

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
                    <h1>Mes reservations</h1>
                    <p>
                        Chaque carte represente une reservation. Trois cartes restent visibles
                        , puis le reste se parcourt horizontalement a la souris.
                    </p>
                    <div class="showcase-actions">
                        <a href="Form/demandeur/dem.php" class="showcase-link">Trouver un trajet</a>
                    </div>
                </aside>

                <div class="showcase-stage">
                    <h2 class="showcase-title">Reservations en cours</h2>

                    <?php if (!empty($reservations)): ?>
                        <div class="reservation-slider" id="reservation-slider">
                            <?php foreach ($reservations as $index => $reservation): ?>
                                <article class="match-card reservation-card-showcase">
                                    <div class="match-card-topline"></div>
                                    <div class="match-card-logos">
                                        <img src="logo_FCSM.png" alt="Logo FCSM" class="club-logo-card">
                                        <div class="versus-badge">R<?= $index + 1; ?></div>
                                    </div>

                                    <div class="match-card-body">
                                        <p class="match-card-label">Conducteur choisi</p>
                                        <h3><?= e($reservation['conducteur_prenom'] . ' ' . $reservation['conducteur_nom']); ?></h3>
                                        <p class="match-card-route"><?= e($reservation['ville']); ?></p>

                                        <div class="match-card-meta">
                                            <p><strong>Date</strong> <?= e(formatReservationDate($reservation['date_trajet'])); ?></p>
                                            <p><strong>Depart</strong> <?= e(formatReservationTime($reservation['heure_depart'])); ?></p>
                                            <p><strong>Arrivee</strong> <?= e(formatReservationTime($reservation['heure_arrivee'])); ?></p>
                                            <p><strong>Telephone</strong> <?= e($reservation['conducteur_telephone']); ?></p>
                                            <p><strong>Adresse</strong> <?= e($reservation['adresse']); ?></p>
                                            <p><strong>Places</strong> <?= e($reservation['places_proposees']); ?></p>
                                            <p><strong>Prix</strong> <?= e($reservation['prix']); ?></p>
                                        </div>
                                    </div>

                                    <div class="match-card-footer">
                                        
                                        <span class="status-pill validation-status"><?= e(formatReservationValidation($reservation['validation'])); ?></span>
                                        <?php if (canOpenReservationChat($reservation['validation'])): ?>
                                            <a
                                                href="chat.php?id_offre_demande=<?= e($reservation['id_offre_demande']); ?>&amp;id_participation=<?= e($reservation['id_participation']); ?>"
                                                class="chat-link-button"
                                            >Discuter</a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <section class="empty-showcase-card">
                            <h3>Aucune reservation pour le moment</h3>
                            <p><?= e($message); ?></p>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <script>
    (function () {
        const slider = document.getElementById("reservation-slider");

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