<?php

$DB_HOST = "localhost";
$DB_NAME = "u129649329_sae";
$DB_USER = "u129649329_root";
$DB_PASS = "2tM7XQZDmjfJCKJz";

$IMPORTS = [
    "personne" => [
        "label" => "Personnes",
        "file" => __DIR__ . "/data/personne.json",
        "table" => "personne"
    ],
    "compte_utilisateur" => [
        "label" => "Comptes utilisateurs",
        "file" => __DIR__ . "/data/compte.json",
        "table" => "compte_utilisateur"
    ],
    "lieu" => [
        "label" => "Lieux",
        "file" => __DIR__ . "/data/LIEU.json",
        "table" => "lieu"
    ],
    "date_trajet" => [
        "label" => "Dates de trajet",
        "file" => __DIR__ . "/data/DATE.json",
        "table" => "date_trajet",
        "map" => ["date" => "date_trajet"]
    ],
    "vehicule" => [
        "label" => "Véhicules",
        "file" => __DIR__ . "/data/VEHICULE.json",
        "table" => "vehicule"
    ],
    "offre_demande" => [
        "label" => "Offres / demandes",
        "file" => __DIR__ . "/data/OFFRE_DEMANDE.json",
        "table" => "offre_demande"
    ],
    "participation" => [
        "label" => "Participations",
        "file" => __DIR__ . "/data/PARTICIPATION.json",
        "table" => "participation"
    ]
];

$IMPORT_ORDER = ["personne", "compte_utilisateur", "lieu", "date_trajet", "vehicule", "offre_demande", "participation"];
$CLEAR_ORDER = ["participation", "offre_demande", "vehicule", "date_trajet", "lieu", "compte_utilisateur", "personne"];

function connectDB($host, $dbname, $user, $pass) {
    return new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function getColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->query("DESCRIBE `$table`");
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row["Field"]] = $row;
    }
    return $cols;
}

function normalizeValue($value) {
    if ($value === "" || $value === null) {
        return null;
    }
    return $value;
}

function startsWithId(string $key): bool {
    return substr($key, 0, 3) === "id_";
}

function isUsefulRecord(array $row): bool {
    foreach ($row as $key => $value) {
        $v = trim((string)$value);
        if ($v === "") {
            continue;
        }
        if (startsWithId($key)) {
            continue;
        }
        return true;
    }
    return false;
}

function importOne(PDO $pdo, array $config): string {
    $file = $config["file"];
    $table = $config["table"];
    $map = $config["map"] ?? [];

    if (!file_exists($file)) {
        return "Fichier introuvable pour `$table` : $file";
    }

    $json = file_get_contents($file);
    $rows = json_decode($json, true);

    if (!is_array($rows)) {
        return "JSON invalide pour `$table`.";
    }

    $columns = getColumns($pdo, $table);
    $inserted = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        foreach ($map as $jsonKey => $sqlKey) {
            if (array_key_exists($jsonKey, $row)) {
                $row[$sqlKey] = $row[$jsonKey];
                unset($row[$jsonKey]);
            }
        }

        if (!isUsefulRecord($row)) {
            $skipped++;
            continue;
        }

        $data = [];

        foreach ($row as $key => $value) {
            if (!array_key_exists($key, $columns)) {
                continue;
            }

            $value = normalizeValue($value);
            $extra = strtolower($columns[$key]["Extra"] ?? "");

            if (strpos($extra, "auto_increment") !== false && $value === null) {
                continue;
            }

            if ($table === "compte_utilisateur" && $key === "password_hash" && $value !== null) {
                $value = password_hash($value, PASSWORD_DEFAULT);
            }

            $data[$key] = $value;
        }

        if (empty($data)) {
            $skipped++;
            continue;
        }

        $colNames = array_keys($data);
        $sqlCols = "`" . implode("`, `", $colNames) . "`";
        $params = ":" . implode(", :", $colNames);

        $sql = "INSERT INTO `$table` ($sqlCols) VALUES ($params)";
        $stmt = $pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }

        $stmt->execute();
        $inserted++;
    }

    return "`$table` : $inserted ligne(s) importée(s), $skipped ligne(s) ignorée(s).";
}

function clearTables(PDO $pdo, array $tables): array {
    $messages = [];
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM `$table`");
        try {
            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        } catch (Exception $e) {}
        $messages[] = "`$table` vidée.";
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    return $messages;
}

$messages = [];
$error = "";

try {
    $pdo = connectDB($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $action = $_POST["action"] ?? "";

        if ($action === "import_one") {
            $target = $_POST["target"] ?? "";
            if (isset($IMPORTS[$target])) {
                $messages[] = importOne($pdo, $IMPORTS[$target]);
            } else {
                $error = "Table inconnue.";
            }
        }

        if ($action === "import_all") {
            foreach ($IMPORT_ORDER as $key) {
                $messages[] = importOne($pdo, $IMPORTS[$key]);
            }
        }

        if ($action === "clear_all") {
            $messages = clearTables($pdo, $CLEAR_ORDER);
        }

        if ($action === "reset_all") {
            $messages = clearTables($pdo, $CLEAR_ORDER);
            foreach ($IMPORT_ORDER as $key) {
                $messages[] = importOne($pdo, $IMPORTS[$key]);
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin import JSON - Covoiturage FCSM</title>
</head>
<body>
<div class="container">

    <?php if (!empty($error)): ?>
        <div class="error"><strong>Erreur :</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($messages)): ?>
        <div class="messages">
            <?php foreach ($messages as $m): ?>
                <div><?= htmlspecialchars($m) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2>Actions globales</h2>
    <div class="zone-actions">
        <form method="post">
            <input type="hidden" name="action" value="import_all">
            <button class="success" type="submit">Importer tous les JSON</button>
        </form>

        <form method="post" onsubmit="return confirm('Confirmer la suppression de toutes les données ?');">
            <input type="hidden" name="action" value="clear_all">
            <button class="danger" type="submit">Vider toutes les tables</button>
        </form>

        <form method="post" onsubmit="return confirm('Confirmer le reset complet de la base ?');">
            <input type="hidden" name="action" value="reset_all">
            <button class="warning" type="submit">Remettre la BDD à l'état de base</button>
        </form>
    </div>

    <h2>Importer un JSON au choix</h2>

    <?php foreach ($IMPORTS as $key => $item): ?>
        <div class="card">
            <div>
                <strong><?= htmlspecialchars($item["label"]) ?></strong><br>
                <small>Table : <code><?= htmlspecialchars($item["table"]) ?></code> —
                Fichier : <code><?= htmlspecialchars(basename($item["file"])) ?></code></small>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="import_one">
                <input type="hidden" name="target" value="<?= htmlspecialchars($key) ?>">
                <button class="primary" type="submit">Importer</button>
            </form>
        </div>
    <?php endforeach; ?>

    <p><strong>Ordre conseillé :</strong> personne → compte_utilisateur → lieu → date_trajet → vehicule → offre_demande → participation.</p>
</div>
</body>
</html>