<?php
require_once __DIR__ . '/session_utils.php';

startAppSession();

$message = '';
$messageType = '';
$activeMode = trim($_GET['mode'] ?? $_POST['mode'] ?? 'login');
$nextPage = trim($_GET['next'] ?? $_POST['next'] ?? 'index.php');

function old($key)
{
    return $_POST[$key] ?? '';
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $connexion = connectAppDatabase();

    if (!$connexion) {
        $message = 'Connexion a la base impossible.';
        $messageType = 'erreur';
    } else {
        try {
            if ($activeMode === 'register') {
                $username = trim($_POST['username'] ?? '');
                $password = (string) ($_POST['password'] ?? '');
                $nom = trim($_POST['nom'] ?? '');
                $prenom = trim($_POST['prenom'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $telephone = trim($_POST['telephone'] ?? '');
                $adresse = trim($_POST['adresse'] ?? '');
                $ville = trim($_POST['ville'] ?? '');
                $latitude = trim($_POST['latitude'] ?? '');
                $longitude = trim($_POST['longitude'] ?? '');

                if (
                    $username === '' || $password === '' || $nom === '' || $prenom === '' ||
                    $email === '' || $telephone === '' || $adresse === '' ||
                    $ville === '' || $latitude === '' || $longitude === ''
                ) {
                    throw new Exception('Merci de remplir tous les champs et de verifier l adresse.');
                }

                $statementUsername = prepareAndExecute(
                    $connexion,
                    "SELECT id_compte
                     FROM compte_utilisateur
                     WHERE username = ?
                     LIMIT 1",
                    's',
                    [$username]
                );
                $resultUsername = mysqli_stmt_get_result($statementUsername);

                if ($resultUsername && mysqli_num_rows($resultUsername) > 0) {
                    mysqli_stmt_close($statementUsername);
                    throw new Exception('Ce nom d utilisateur existe deja.');
                }
                mysqli_stmt_close($statementUsername);

                mysqli_begin_transaction($connexion);

                $statementPersonne = prepareAndExecute(
                    $connexion,
                    "SELECT id_personne
                     FROM personne
                     WHERE nom = ? AND prenom = ? AND email = ? AND telephone = ?
                     LIMIT 1",
                    'ssss',
                    [$nom, $prenom, $email, $telephone]
                );
                $resultPersonne = mysqli_stmt_get_result($statementPersonne);

                if ($resultPersonne && mysqli_num_rows($resultPersonne) > 0) {
                    $personne = mysqli_fetch_assoc($resultPersonne);
                    $idPersonne = (int) $personne['id_personne'];
                } else {
                    prepareAndExecute(
                        $connexion,
                        "INSERT INTO personne (nom, prenom, email, telephone)
                         VALUES (?, ?, ?, ?)",
                        'ssss',
                        [$nom, $prenom, $email, $telephone]
                    );
                    $idPersonne = mysqli_insert_id($connexion);
                }
                mysqli_stmt_close($statementPersonne);

                $statementComptePersonne = prepareAndExecute(
                    $connexion,
                    "SELECT id_compte
                     FROM compte_utilisateur
                     WHERE id_personne = ?
                     LIMIT 1",
                    'i',
                    [$idPersonne]
                );
                $resultComptePersonne = mysqli_stmt_get_result($statementComptePersonne);

                if ($resultComptePersonne && mysqli_num_rows($resultComptePersonne) > 0) {
                    mysqli_stmt_close($statementComptePersonne);
                    throw new Exception('Cette personne possede deja un compte. Utilisez la connexion.');
                }
                mysqli_stmt_close($statementComptePersonne);

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                prepareAndExecute(
                    $connexion,
                    "INSERT INTO compte_utilisateur (username, password_hash, role, id_personne, actif)
                     VALUES (?, ?, 'user', ?, 1)",
                    'ssi',
                    [$username, $passwordHash, $idPersonne]
                );
                $idCompte = mysqli_insert_id($connexion);
                mysqli_commit($connexion);

                session_regenerate_id(true);
                setAuthenticatedSessionUser([
                    'id_compte' => $idCompte,
                    'id_personne' => $idPersonne,
                    'username' => $username,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'email' => $email,
                    'telephone' => $telephone,
                    'adresse' => $adresse,
                    'ville' => $ville,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                mysqli_close($connexion);
                header('Location: ' . $nextPage);
                exit;
            }

            $username = trim($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if ($username === '' || $password === '') {
                throw new Exception('Merci de saisir votre nom d utilisateur et votre mot de passe.');
            }

            $statementCompte = prepareAndExecute(
                $connexion,
                "SELECT cu.id_compte, cu.username, cu.password_hash, cu.actif, cu.id_personne
                 FROM compte_utilisateur cu
                 WHERE cu.username = ?
                 LIMIT 1",
                's',
                [$username]
            );
            $resultCompte = mysqli_stmt_get_result($statementCompte);

            if (!$resultCompte || mysqli_num_rows($resultCompte) === 0) {
                mysqli_stmt_close($statementCompte);
                throw new Exception('Identifiants invalides.');
            }

            $compte = mysqli_fetch_assoc($resultCompte);
            mysqli_stmt_close($statementCompte);

            if ((int) ($compte['actif'] ?? 0) !== 1) {
                throw new Exception('Ce compte est desactive.');
            }

            if (!password_verify($password, (string) ($compte['password_hash'] ?? ''))) {
                throw new Exception('Identifiants invalides.');
            }

            $user = buildSessionUserFromDatabase($connexion, [
                'id_compte' => (int) $compte['id_compte'],
                'id_personne' => (int) $compte['id_personne'],
                'username' => $compte['username'],
            ]);

            if (!$user) {
                throw new Exception('Impossible de charger le profil de cet utilisateur.');
            }

            session_regenerate_id(true);
            setAuthenticatedSessionUser($user);
            mysqli_close($connexion);
            header('Location: ' . $nextPage);
            exit;
        } catch (Exception $exception) {
            if (mysqli_errno($connexion)) {
                mysqli_rollback($connexion);
            }
            $message = $exception->getMessage();
            $messageType = 'erreur';
            mysqli_close($connexion);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php $authStyleVersion = file_exists(__DIR__ . '/auth.css') ? filemtime(__DIR__ . '/auth.css') : time(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion covoiturage</title>
    <link rel="stylesheet" href="auth.css?v=<?= $authStyleVersion; ?>">
</head>
<body class="auth-page">
    <div id="google_translate_element" style="position:fixed;top:10px;right:10px;z-index:9999;"></div>

    <main class="auth-shell">
        <aside class="auth-aside">
            <div class="auth-brand">
                <img src="logo_FCSM.png" alt="FCSM">
                <span>FCSM Covoiturage</span>
            </div>

            <h1><?= $activeMode === 'register' ? 'Créer votre accès' : 'Reprendre votre trajet'; ?></h1>
            <p>
                <?= $activeMode === 'register'
                    ? 'Créez votre compte dans l univers du club pour publier, reserver et suivre vos trajets plus facilement.'
                    : 'Retrouvez vos reservations, vos trajets et vos echanges dans une interface unique aux couleurs du club.'; ?>
            </p>

            <div class="auth-points">
                <div class="auth-point">
                    <strong>1</strong>
                    <span><?= $activeMode === 'register' ? 'Renseignez vos informations et votre adresse de depart.' : 'Accedez a votre espace supporter en quelques secondes.'; ?></span>
                </div>
                <div class="auth-point">
                    <strong>2</strong>
                    <span><?= $activeMode === 'register' ? 'Validez votre compte pour trouver ou proposer un trajet.' : 'Consultez vos demandes, vos trajets et vos validations.'; ?></span>
                </div>
                <div class="auth-point">
                    <strong>3</strong>
                    <span><?= $activeMode === 'register' ? 'Echangez ensuite directement avec les autres utilisateurs.' : 'Revenez au covoiturage FCSM sans perdre de temps.'; ?></span>
                </div>
            </div>
        </aside>

        <section class="auth-main">
            <div class="auth-card">
                <span class="auth-kicker"><?= $activeMode === 'register' ? 'Inscription' : 'Connexion'; ?></span>
                <h2><?= $activeMode === 'register' ? 'Ouvrir votre compte' : 'Entrer dans votre espace'; ?></h2>
                <p>
                    <?= $activeMode === 'register'
                        ? 'Remplissez le formulaire pour creer votre acces et enregistrer votre depart habituel.'
                        : 'Saisissez vos identifiants pour retrouver vos trajets et vos reservations.'; ?>
                </p>

                <?php if ($message !== ''): ?>
                    <p class="message <?= e($messageType); ?>"><?= e($message); ?></p>
                <?php endif; ?>

                <nav class="auth-switch" aria-label="Choix du mode">
                    <a href="?mode=login&amp;next=<?= e(urlencode($nextPage)); ?>" class="<?= $activeMode === 'login' ? 'is-active' : ''; ?>">Se connecter</a>
                    <a href="?mode=register&amp;next=<?= e(urlencode($nextPage)); ?>" class="<?= $activeMode === 'register' ? 'is-active' : ''; ?>">S inscrire</a>
                </nav>

                <?php if ($activeMode === 'register'): ?>
                    <form class="auth-form" action="" method="post" id="register-form">
                        <input type="hidden" name="mode" value="register">
                        <input type="hidden" name="next" value="<?= e($nextPage); ?>">
                        <input type="hidden" id="latitude" name="latitude" value="<?= e(old('latitude')); ?>">
                        <input type="hidden" id="longitude" name="longitude" value="<?= e(old('longitude')); ?>">

                        <section class="auth-section">
                            <h3>Informations du compte</h3>

                            <div class="auth-grid">
                                <div class="field field--full">
                                    <label for="username">Nom d utilisateur</label>
                                    <input type="text" id="username" name="username" value="<?= e(old('username')); ?>" required>
                                </div>

                                <div class="field field--full">
                                    <label for="password">Mot de passe</label>
                                    <input type="password" id="password" name="password" required>
                                </div>

                                <div class="field">
                                    <label for="nom">Nom</label>
                                    <input type="text" id="nom" name="nom" value="<?= e(old('nom')); ?>" required>
                                </div>

                                <div class="field">
                                    <label for="prenom">Prenom</label>
                                    <input type="text" id="prenom" name="prenom" value="<?= e(old('prenom')); ?>" required>
                                </div>

                                <div class="field">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" value="<?= e(old('email')); ?>" required>
                                </div>

                                <div class="field">
                                    <label for="telephone">Telephone</label>
                                    <input type="text" id="telephone" name="telephone" value="<?= e(old('telephone')); ?>" required>
                                </div>

                                <div class="field field--full">
                                    <label for="adresse">Adresse</label>
                                    <input type="text" id="adresse" name="adresse" value="<?= e(old('adresse')); ?>" required>
                                </div>

                                <div class="field field--full">
                                    <label for="ville">Ville</label>
                                    <input type="text" id="ville" name="ville" value="<?= e(old('ville')); ?>" required>
                                </div>
                            </div>
                        </section>

                        <button class="auth-submit" type="submit">Creer mon compte</button>
                    </form>
                <?php else: ?>
                    <form class="auth-form" action="" method="post">
                        <input type="hidden" name="mode" value="login">
                        <input type="hidden" name="next" value="<?= e($nextPage); ?>">

                        <section class="auth-section">
                            <h3>Acces au compte</h3>

                            <div class="auth-grid">
                                <div class="field field--full">
                                    <label for="login-username">Nom d utilisateur</label>
                                    <input type="text" id="login-username" name="username" value="<?= e(old('username')); ?>" required>
                                </div>

                                <div class="field field--full">
                                    <label for="login-password">Mot de passe</label>
                                    <input type="password" id="login-password" name="password" required>
                                </div>
                            </div>
                        </section>

                        <button class="auth-submit" type="submit">Connexion</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
        const registerForm = document.getElementById('register-form');

        if (registerForm) {
            const adresseInput = document.getElementById('adresse');
            const villeInput = document.getElementById('ville');
            const latitudeInput = document.getElementById('latitude');
            const longitudeInput = document.getElementById('longitude');
            let submitAvecCoordonnees = false;

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
                    throw new Error('Adresse introuvable.');
                }

                return {
                    lat: data[0].lat,
                    lng: data[0].lon
                };
            }

            registerForm.addEventListener('submit', async function (event) {
                if (submitAvecCoordonnees) {
                    return;
                }

                event.preventDefault();

                const adresseComplete = `${adresseInput.value.trim()}, ${villeInput.value.trim()}, France`;

                try {
                    const coordinates = await geocodeAddress(adresseComplete);
                    latitudeInput.value = coordinates.lat;
                    longitudeInput.value = coordinates.lng;
                    submitAvecCoordonnees = true;
                    registerForm.submit();
                } catch (error) {
                    alert(error.message);
                }
            });
        }
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