<?php
declare(strict_types=1);

function admin_permission_columns(): array
{
    return [
        "can_manage_users" => [
            "label" => "Administration",
            "default" => 1,
            "session_key" => "administration",
        ],
        "can_view_perimetre" => [
            "label" => "Périmètre",
            "default" => 1,
            "session_key" => "perimetre",
        ],
        "can_view_rvtools" => [
            "label" => "RVTool",
            "default" => 1,
            "session_key" => "rvtools",
        ],
        "can_view_catalogue" => [
            "label" => "Catalogue",
            "default" => 1,
            "session_key" => "catalogue",
        ],
        "can_view_besoin" => [
            "label" => "Besoin client",
            "default" => 1,
            "session_key" => "besoin",
        ],
        "can_view_devis" => [
            "label" => "Résumé des devis",
            "default" => 1,
            "session_key" => "devis",
        ],
    ];
}

function default_admin_permissions(): array
{
    $permissions = [];
    foreach (admin_permission_columns() as $column => $meta) {
        $permissions[$meta["session_key"]] = (bool)$meta["default"];
    }
    return $permissions;
}

function ensure_admin_user_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS catalogue_admin_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(120) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            can_manage_users TINYINT(1) NOT NULL DEFAULT 1,
            can_view_perimetre TINYINT(1) NOT NULL DEFAULT 1,
            can_view_rvtools TINYINT(1) NOT NULL DEFAULT 1,
            can_view_catalogue TINYINT(1) NOT NULL DEFAULT 1,
            can_view_besoin TINYINT(1) NOT NULL DEFAULT 1,
            can_view_devis TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $existingColumns = $pdo->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'catalogue_admin_users'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $existingLookup = [];
    foreach ($existingColumns as $columnName) {
        $existingLookup[(string)$columnName] = true;
    }

    foreach (admin_permission_columns() as $column => $meta) {
        if (!isset($existingLookup[$column])) {
            $pdo->exec(
                sprintf(
                    "ALTER TABLE catalogue_admin_users
                     ADD COLUMN %s TINYINT(1) NOT NULL DEFAULT %d",
                    $column,
                    (int)$meta["default"]
                )
            );
        }
    }
}

function extract_admin_permissions(array $user): array
{
    $permissions = default_admin_permissions();
    foreach (admin_permission_columns() as $column => $meta) {
        $sessionKey = (string)$meta["session_key"];
        if (array_key_exists($column, $user)) {
            $permissions[$sessionKey] = (bool)$user[$column];
        }
    }
    return $permissions;
}

function store_admin_session_user(array $user): void
{
    $permissions = extract_admin_permissions($user);
    $_SESSION["catalogue_admin"] = true;
    $_SESSION["catalogue_admin_id"] = (int)$user["id"];
    $_SESSION["catalogue_admin_username"] = (string)$user["username"];
    $_SESSION["admin_authenticated"] = true;
    $_SESSION["admin_user_id"] = (int)$user["id"];
    $_SESSION["admin_username"] = (string)$user["username"];
    $_SESSION["admin_permissions"] = $permissions;
}

function current_admin_permissions(): array
{
    $stored = $_SESSION["admin_permissions"] ?? null;
    if (!is_array($stored)) {
        return default_admin_permissions();
    }

    return array_merge(default_admin_permissions(), $stored);
}

function current_admin_has_permission(string $key): bool
{
    $permissions = current_admin_permissions();
    return !empty($permissions[$key]);
}

function admin_user_columns_sql(): string
{
    return "id, username, password_hash, can_manage_users, can_view_perimetre, can_view_rvtools, can_view_catalogue, can_view_besoin, can_view_devis, created_at, updated_at";
}

function load_admin_user_by_id(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT " . admin_user_columns_sql() . "
         FROM catalogue_admin_users
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([":id" => $userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function count_manage_users(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(*)
         FROM catalogue_admin_users
         WHERE can_manage_users = 1"
    )->fetchColumn();
}

function require_manage_users_permission(): void
{
    $isLoggedIn = !empty($_SESSION["catalogue_admin"]) && $_SESSION["catalogue_admin"] === true;
    if (!$isLoggedIn) {
        header("Location: index.php");
        exit;
    }

    if (!current_admin_has_permission("administration")) {
        http_response_code(403);
        echo "Accès refusé.";
        exit;
    }
}
