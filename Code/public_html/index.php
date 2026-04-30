<?php
require_once __DIR__ . '/session_utils.php';

if (function_exists('startAppSession')) {
    startAppSession();
}
$sessionUser = function_exists('getSessionUser') ? getSessionUser() : null;

$styleFile = 'style2.css';
$styleVersion = file_exists(__DIR__ . '/' . $styleFile) ? filemtime(__DIR__ . '/' . $styleFile) : time();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FCSM - Officiel</title>
    <link rel="stylesheet" href="<?= $styleFile; ?>?v=<?= $styleVersion; ?>">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#003b71">
</head>
<body class="homepage">

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

                    <!-- Google Translate -->
                    <li>
                        <div id="google_translate_element"></div>
                    </li>

                </ul>
            </div>
        </nav>
    </header>

    <section class="hero-banner home-hero">
        <video autoplay muted loop playsinline class="hero-background">
            <source src="baniere_sochaux2.mp4" type="video/mp4">
            Votre navigateur ne supporte pas la vidéo.
        </video>
        <div class="home-hero-overlay"></div>
        <div class="home-hero-inner">
            <div class="home-hero-copy">
                <span class="home-kicker">Plateforme de  Convoiturage</span>
                <h1>Simplifiez vos trajets vers le stade les jours de match.</h1>
                <p>
                    Le covoiturage FCSM centralise les trajets, les demandes et les echanges
                    entre supporters dans un espace simple .
                </p>
                <div class="home-hero-actions">
                    <a href="Form/demandeur/dem.php" class="home-primary-link">Trouver un trajet</a>
                    <a href="Form/proposer_trajet.php" class="home-secondary-link">Proposer un trajet</a>
                </div>
            </div>

            <aside class="home-hero-panel"> 
                <p class="home-panel-title">Connectez-vous et accédez à votre espace.</p>
                <div class="home-panel-metrics">
                    <article>
                        <strong>1</strong>
                        <span>Proposez votre offre de trajet.</span>
                    </article>
                    <article>
                        <strong>2</strong>
                        <span>Trouvez le trajet qui vous convient.</span>
                    </article>
                    <article>
                        <strong>3</strong>
                        <span>Consultez vos réservations et vos trajets.</span>
                    </article>
                </div>
            </aside>
        </div>
    </section>

    <!-- Script Google Translate -->
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