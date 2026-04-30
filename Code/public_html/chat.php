<?php
require_once __DIR__ . '/session_utils.php';

$user = requireSessionUser('chat.php');

$pdo = new PDO(
    'mysql:host=' . APP_DB_HOST . ';dbname=' . APP_DB_NAME . ';charset=utf8mb4',
    APP_DB_USER,
    APP_DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableHasColumn($pdo, $table, $column)
{
    $statement = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $statement->execute([$column]);
    return (bool) $statement->fetch(PDO::FETCH_ASSOC);
}

function ensureChatSchema($pdo)
{
    if (!tableHasColumn($pdo, 'messages', 'id_participation')) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN id_participation INT NULL AFTER id_offre_demande");
        $pdo->exec("ALTER TABLE messages ADD INDEX idx_messages_participation (id_participation)");
    }
}

function findConversationForPassenger($pdo, $offerId, $userId)
{
    $statement = $pdo->prepare(
        "SELECT
            part.id_participation,
            part.validation,
            part.id_personne AS demandeur_id,
            od.id_offre_demande,
            od.id_personne AS offreur_id
         FROM participation part
         INNER JOIN offre_demande od ON od.id_offre_demande = part.id_offre_demande
         WHERE part.id_offre_demande = ?
           AND part.id_personne = ?
           AND part.role = 'demandeur'
         ORDER BY part.id_participation DESC
         LIMIT 1"
    );
    $statement->execute([$offerId, $userId]);
    $conversation = $statement->fetch(PDO::FETCH_ASSOC);

    return $conversation ?: null;
}

function findConversationForDriver($pdo, $offerId, $userId)
{
    $statement = $pdo->prepare(
        "SELECT
            part.id_participation,
            part.validation,
            part.id_personne AS demandeur_id,
            od.id_offre_demande,
            od.id_personne AS offreur_id
         FROM participation part
         INNER JOIN offre_demande od ON od.id_offre_demande = part.id_offre_demande
         WHERE part.id_offre_demande = ?
           AND od.id_personne = ?
           AND part.role = 'demandeur'
         ORDER BY part.id_participation DESC
         LIMIT 2"
    );
    $statement->execute([$offerId, $userId]);
    $conversations = $statement->fetchAll(PDO::FETCH_ASSOC);

    if (count($conversations) !== 1) {
        return null;
    }

    return $conversations[0];
}

function findConversationByParticipation($pdo, $offerId, $participationId)
{
    $statement = $pdo->prepare(
        "SELECT
            part.id_participation,
            part.validation,
            part.id_personne AS demandeur_id,
            od.id_offre_demande,
            od.id_personne AS offreur_id
         FROM participation part
         INNER JOIN offre_demande od ON od.id_offre_demande = part.id_offre_demande
         WHERE part.id_participation = ?
           AND part.id_offre_demande = ?
           AND part.role = 'demandeur'
         LIMIT 1"
    );
    $statement->execute([$participationId, $offerId]);
    $conversation = $statement->fetch(PDO::FETCH_ASSOC);

    return $conversation ?: null;
}

function resolveConversation($pdo, $offerId, $participationId, $userId)
{
    if ($participationId > 0) {
        return findConversationByParticipation($pdo, $offerId, $participationId);
    }

    $conversation = findConversationForPassenger($pdo, $offerId, $userId);
    if ($conversation) {
        return $conversation;
    }

    return findConversationForDriver($pdo, $offerId, $userId);
}

ensureChatSchema($pdo);

$id_offre_demande = (int) ($_GET['id_offre_demande'] ?? 0);
$id_participation = (int) ($_GET['id_participation'] ?? 0);

if ($id_offre_demande <= 0) {
    http_response_code(400);
    die('Erreur : id_offre_demande manquant.');
}

$userId = (int) ($user['id_personne'] ?? 0);
$expediteur = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
$conversation = resolveConversation($pdo, $id_offre_demande, $id_participation, $userId);

if (!$conversation) {
    http_response_code(403);
    die('Acces refuse a cette conversation.');
}

$isAllowedUser = $userId === (int) $conversation['demandeur_id'] || $userId === (int) $conversation['offreur_id'];

if (!$isAllowedUser) {
    http_response_code(403);
    die('Acces refuse a cette conversation.');
}

if (trim((string) ($conversation['validation'] ?? '')) === 'refusee') {
    http_response_code(403);
    die('Cette conversation n est plus accessible car la demande a ete refusee.');
}

$id_participation = (int) $conversation['id_participation'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $message = trim($_POST["message"] ?? "");

    if ($message !== "") {
        $stmt = $pdo->prepare("
            INSERT INTO messages (id_offre_demande, id_participation, expediteur, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$id_offre_demande, $id_participation, $expediteur, $message]);

        header("Location: chat.php?id_offre_demande=" . $id_offre_demande . "&id_participation=" . $id_participation);
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT *
    FROM messages
    WHERE id_participation = ?
    ORDER BY date_message ASC
");
$stmt->execute([$id_participation]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Chat trajet</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(135deg, #052160 0%, #0a3a8f 58%, #0d4aaf 100%);
            margin: 0;
            min-height: 100vh;
            padding: 28px;
        }

        .chat-container {
            max-width: 920px;
            margin: auto;
            background: rgba(255, 255, 255, 0.97);
            border-radius: 0;
            overflow: hidden;
            box-shadow: 0 26px 46px rgba(2, 17, 53, 0.24);
        }

        .chat-header {
            padding: 28px 30px 22px;
            background: linear-gradient(180deg, rgba(3, 38, 110, 0.98) 0%, rgba(8, 43, 115, 0.98) 100%);
            border-left: 6px solid #ffcb21;
            color: #ffffff;
        }

        .chat-back {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            padding: 0 14px;
            margin-bottom: 18px;
            color: #082b73;
            background: #ffcb21;
            font-size: 0.8rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-decoration: none;
        }

        .chat-kicker {
            display: inline-block;
            margin-bottom: 10px;
            color: #ffcb21;
            font-size: 0.84rem;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #ffffff;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1;
        }

        .chat-subtitle {
            margin: 0;
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.6;
        }

        .chat-body {
            padding: 24px 30px 28px;
        }

        .messages {
            padding: 20px;
            height: 440px;
            overflow-y: auto;
            background: linear-gradient(180deg, #f6f8fc 0%, #edf2fb 100%);
            margin-bottom: 18px;
        }

        .message {
            max-width: 78%;
            margin-bottom: 14px;
            padding: 14px 16px;
            background: #ffffff;
            box-shadow: 0 10px 20px rgba(8, 43, 115, 0.08);
        }

        .message.moi {
            margin-left: auto;
            background: #082b73;
            color: #ffffff;
        }

        .auteur {
            margin-bottom: 6px;
            font-weight: 800;
            color: #082b73;
            font-size: 0.82rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .message.moi .auteur {
            color: #ffcb21;
        }

        .texte {
            line-height: 1.6;
            word-break: break-word;
        }

        .date {
            margin-top: 8px;
            font-size: 0.76rem;
            color: #6a7ea8;
        }

        .message.moi .date {
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state {
            margin: 0;
            padding: 22px;
            background: #ffffff;
            color: #48618f;
            text-align: center;
        }

        form {
            display: flex;
            gap: 12px;
            align-items: stretch;
        }

        input[type="text"] {
            flex: 1;
            min-height: 52px;
            padding: 0 16px;
            border: 1px solid #cbd7ec;
            background: #ffffff;
            color: #082b73;
            font-size: 0.96rem;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #082b73;
            box-shadow: 0 0 0 3px rgba(8, 43, 115, 0.12);
        }

        button {
            min-height: 52px;
            padding: 0 20px;
            background: #ffcb21;
            color: #082b73;
            border: none;
            font-size: 0.84rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            body {
                padding: 0;
            }

            .chat-header,
            .chat-body {
                padding: 20px 16px;
            }

            .messages {
                height: calc(100vh - 330px);
                min-height: 320px;
                padding: 14px;
            }

            .message {
                max-width: 100%;
            }

            form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="chat-container">
    <div class="chat-header">
        <a class="chat-back" href="javascript:history.back()">Retour</a>
        <h1>Chat du trajet #<?= e($id_offre_demande); ?></h1>
    </div>

    <div class="chat-body">
    <div class="messages" id="messages">
        <?php if (empty($messages)): ?>
            <p class="empty-state">Aucun message pour le moment.</p>
        <?php endif; ?>

        <?php foreach ($messages as $msg): ?>
            <div class="message <?= ($msg['expediteur'] === $expediteur) ? 'moi' : ''; ?>">
                <div class="auteur"><?= e($msg["expediteur"]); ?></div>
                <div class="texte"><?= e($msg["message"]); ?></div>
                <div class="date"><?= e($msg["date_message"]); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post">
        <input type="text" name="message" placeholder="Écrire un message..." required>
        <button type="submit">Envoyer</button>
    </form>
    </div>
</div>

<script>
    const messages = document.getElementById("messages");
    messages.scrollTop = messages.scrollHeight;
</script>

</body>
</html>
