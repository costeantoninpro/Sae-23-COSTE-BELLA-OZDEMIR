<?php
require_once __DIR__ . '/../../session_utils.php';

startAppSession();
$sessionUser = getSessionUser();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php
    $styleVersion = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();
    $scriptVersion = file_exists(__DIR__ . '/script.js') ? filemtime(__DIR__ . '/script.js') : time();
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Test carte offreurs</title>
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    >
    <link rel="stylesheet" href="style.css?v=<?= $styleVersion; ?>">
    <style>
        .map-toolbar {
            position: static;
            z-index: 1000;
            min-height: 0;
            padding: 0;
            background: transparent;
            box-shadow: none;
            pointer-events: auto;
        }

        .map-toolbar-primary {
            position: fixed;
            left: 24px;
            bottom: 24px;
            z-index: 1100;
            display: flex;
            justify-content: flex-start;
        }

        .toolbar-toggle {
            background: #082b73;
            color: #ffffff;
            min-width: 220px;
            min-height: 38px;
            padding: 0 14px;
            border: 2px solid rgba(255, 203, 33, 0.9);
            box-shadow: 0 14px 28px rgba(8, 43, 115, 0.22);
            font-size: 0.76rem;
        }

        .filters-box {
            position: fixed;
            top: 70px;
            left: 0;
            right: 0;
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr)) auto;
            gap: 10px;
            align-items: end;
            width: 100%;
            padding: 10px 14px;
            background: linear-gradient(135deg, #ffcb21 0%, #f6d54e 100%);
            border: 1px solid rgba(8, 43, 115, 0.16);
            box-shadow: 0 20px 40px rgba(8, 43, 115, 0.2);
            z-index: 1090;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }

        .filter-field {
            gap: 6px;
        }

        .filter-field label {
            font-size: 0.76rem;
        }

        .filter-field input {
            min-height: 36px;
            padding: 0 10px;
            font-size: 0.86rem;
        }

        @media (max-width: 768px) {
            .map-toolbar {
                padding: 0;
            }

            .map-toolbar-primary {
                left: 12px;
                right: 12px;
                bottom: 12px;
                justify-content: stretch;
            }

            .filters-box {
                top: 70px;
                grid-template-columns: 1fr;
                width: 100%;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    
        <img src="logo.png" alt="Logo FCSM" class="logo-overlay">

    <header>
        <nav class="main-nav">
            <div class="nav-container">
                <ul class="menu-links">
                    <li><a href="dem.php">TROUVER UN TRAJET</a></li>
                    <li><a href="../proposer_trajet.php">PROPOSER UN TRAJET</a></li>
                    <li><a href="../../reserv.php">MES RÉSERVATIONS</a></li>
                    <li><a href="../../trajet.php">MES TRAJETS</a></li>
                    <?php if (empty($sessionUser)): ?>
                        <li><a href="connexion.php">CONNEXION</a></li>
                    <?php else: ?>
                        <li><a href="deconnexion.php">DÉCONNEXION</a></li>
                    <?php endif; ?>
                    <li><a href="../../index.php">AIDE</a></li>
                </ul>
            </div>
        </nav>
    </header>

    <main class="page">
        <section class="map-toolbar">
            <div class="map-toolbar-primary">
                <button id="toggle-filters" type="button" class="toolbar-toggle">Ouvrir les filtres</button>
            </div>

            <div id="filters-box" class="filters-box" hidden>
                <div class="filter-field">
                    <label for="filter-price">Prix maximum</label>
                    <input type="number" id="filter-price" placeholder="Exemple : 10" min="0" step="0.01">
                </div>

                <div class="filter-field">
                    <label for="filter-time-start">Départ minimum</label>
                    <input type="time" id="filter-time-start">
                </div>

                <div class="filter-field">
                    <label for="filter-time-end">Départ maximum</label>
                    <input type="time" id="filter-time-end">
                </div>

                <div class="filter-actions">
                    <button id="apply-filters" type="button" class="filter-button apply">Appliquer</button>
                    <button id="reset-filters" type="button" class="filter-button reset">Réinitialiser</button>
                </div>
            </div>
        </section>

        <div id="map"></div>

        <aside id="panel" class="panel">
            <div class="panel-header">
                <div>
                    <span class="panel-kicker">Zone sélectionnée</span>
                    <h2>Offreurs</h2>
                </div>
                <button id="close-panel" type="button" class="close-button">×</button>
            </div>

            <p id="panel-title" class="panel-title">Clique sur un cercle pour voir les offreurs.</p>
            <div id="panel-list" class="panel-list"></div>
        </aside>
    </main>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script src="script.js?v=<?= $scriptVersion; ?>"></script>
</body>
</html>
