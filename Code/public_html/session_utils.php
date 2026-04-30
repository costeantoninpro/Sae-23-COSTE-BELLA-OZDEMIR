<?php

const APP_DB_HOST = '127.0.0.1';
const APP_DB_USER = 'u129649329_root';
const APP_DB_PASSWORD = '2tM7XQZDmjfJCKJz';
const APP_DB_NAME = 'u129649329_sae';

function startAppSession()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function appUrl($path = '')
{
    $appRootFs = realpath(__DIR__) ?: __DIR__;
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $currentDirFs = realpath(dirname($scriptFilename)) ?: dirname($scriptFilename);
    $relativeDir = trim(str_replace($appRootFs, '', $currentDirFs), DIRECTORY_SEPARATOR);
    $depth = $relativeDir === '' ? 0 : count(explode(DIRECTORY_SEPARATOR, $relativeDir));

    $scriptDirUrl = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $segments = $scriptDirUrl === '' ? [] : explode('/', $scriptDirUrl);
    $baseSegments = $depth > 0 ? array_slice($segments, 0, max(0, count($segments) - $depth)) : $segments;
    $basePath = implode('/', $baseSegments);
    $normalizedPath = ltrim($path, '/');

    if ($basePath === '') {
        return $normalizedPath === '' ? '/' : '/' . $normalizedPath;
    }

    return $normalizedPath === ''
        ? '/' . $basePath . '/'
        : '/' . $basePath . '/' . $normalizedPath;
}

function getSessionUser()
{
    startAppSession();
    return $_SESSION['user'] ?? null;
}

function connectAppDatabase()
{
    $connexion = mysqli_connect(APP_DB_HOST, APP_DB_USER, APP_DB_PASSWORD, APP_DB_NAME);

    if (!$connexion) {
        return null;
    }

    mysqli_set_charset($connexion, 'utf8mb4');
    return $connexion;
}

function fetchPersonById($connexion, $idPersonne)
{
    $statement = mysqli_prepare(
        $connexion,
        "SELECT id_personne, nom, prenom, email, telephone
         FROM personne
         WHERE id_personne = ?
         LIMIT 1"
    );

    if (!$statement) {
        return null;
    }

    mysqli_stmt_bind_param($statement, 'i', $idPersonne);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $personne = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($statement);

    return $personne ?: null;
}

function fetchLatestPersonLocation($connexion, $idPersonne)
{
    $statement = mysqli_prepare(
        $connexion,
        "SELECT l.adresse, l.ville, l.latitude, l.longitude
         FROM offre_demande od
         INNER JOIN lieu l ON od.id_lieu = l.id_lieu
         WHERE od.id_personne = ?
           AND od.id_lieu IS NOT NULL
         ORDER BY od.id_offre_demande DESC
         LIMIT 1"
    );

    if (!$statement) {
        return null;
    }

    mysqli_stmt_bind_param($statement, 'i', $idPersonne);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $lieu = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($statement);

    return $lieu ?: null;
}

function buildSessionUserFromDatabase($connexion, $rawSessionUser)
{
    $idPersonne = (int) ($rawSessionUser['id_personne'] ?? 0);

    if ($idPersonne <= 0) {
        return null;
    }

    $personne = fetchPersonById($connexion, $idPersonne);

    if (!$personne) {
        return null;
    }

    $lieu = fetchLatestPersonLocation($connexion, $idPersonne) ?? [];

    return [
        'id_compte' => (int) ($rawSessionUser['id_compte'] ?? 0),
        'id_personne' => $idPersonne,
        'username' => $rawSessionUser['username'] ?? '',
        'nom' => $personne['nom'] ?? '',
        'prenom' => $personne['prenom'] ?? '',
        'email' => $personne['email'] ?? '',
        'telephone' => $personne['telephone'] ?? '',
        'groupe' => '',
        'sous_groupe' => '',
        'adresse' => $rawSessionUser['adresse'] ?? ($lieu['adresse'] ?? ''),
        'ville' => $rawSessionUser['ville'] ?? ($lieu['ville'] ?? ''),
        'latitude' => isset($rawSessionUser['latitude']) && $rawSessionUser['latitude'] !== ''
            ? $rawSessionUser['latitude']
            : ($lieu['latitude'] ?? ''),
        'longitude' => isset($rawSessionUser['longitude']) && $rawSessionUser['longitude'] !== ''
            ? $rawSessionUser['longitude']
            : ($lieu['longitude'] ?? ''),
    ];
}

function setAuthenticatedSessionUser($user)
{
    startAppSession();
    $_SESSION['user'] = $user;
}

function isValidSessionUser($user)
{
    return is_array($user)
        && (int) ($user['id_personne'] ?? 0) > 0
        && ((string) ($user['username'] ?? '') !== '' || (int) ($user['id_compte'] ?? 0) > 0);
}

function requireSessionUser($nextPage)
{
    $rawSessionUser = getSessionUser();

    if (!isValidSessionUser($rawSessionUser)) {
        $target = appUrl('connexion.php?next=' . urlencode($nextPage));
        header('Location: ' . $target);
        exit;
    }

    $connexion = connectAppDatabase();

    if (!$connexion) {
        return $rawSessionUser;
    }

    $user = buildSessionUserFromDatabase($connexion, $rawSessionUser);
    mysqli_close($connexion);

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        $target = appUrl('connexion.php?next=' . urlencode($nextPage));
        header('Location: ' . $target);
        exit;
    }

    setAuthenticatedSessionUser($user);
    return $user;
}
