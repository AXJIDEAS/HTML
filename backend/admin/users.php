<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$currentUser = requireRole('admin');
$error = null;
$notice = null;
$users = [];
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Throwable) {
    $error = 'Base de datos no disponible. Puedes recorrer esta pantalla, pero los cambios no se guardarán.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('La base de datos no está disponible para guardar cambios.');
        }
        if (!verifyAppCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
        }

        $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $whatsapp = trim((string) ($_POST['whatsapp_number'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'receptionist');
        $password = (string) ($_POST['password'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || strlen($name) > 150 || !$email || !in_array($role, ['admin', 'receptionist'], true)) {
            throw new RuntimeException('Revisa el nombre, correo y rol seleccionados.');
        }
        if (strlen($phone) > 50 || ($whatsapp !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/', $whatsapp))) {
            throw new RuntimeException('El teléfono de WhatsApp debe incluir el prefijo internacional, por ejemplo +628123456789.');
        }

        if (!$id) {
            if (strlen($password) < 12) {
                throw new RuntimeException('La contraseña inicial debe tener al menos 12 caracteres.');
            }
            $statement = $pdo->prepare('INSERT INTO `users` (`name`, `email`, `phone`, `whatsapp_number`, `role`, `password_hash`, `is_active`) VALUES (:name, :email, :phone, :whatsapp, :role, :password, :active)');
            $statement->execute([
                ':name' => $name, ':email' => $email, ':phone' => $phone ?: null,
                ':whatsapp' => $whatsapp ?: null, ':role' => $role,
                ':password' => password_hash($password, PASSWORD_DEFAULT), ':active' => $active,
            ]);
            $notice = 'Se creó la cuenta del empleado.';
        } else {
            $statement = $pdo->prepare('SELECT `role`, `is_active` FROM `users` WHERE `id` = :id');
            $statement->execute([':id' => $id]);
            $existing = $statement->fetch();
            if (!$existing) {
                throw new RuntimeException('La cuenta seleccionada ya no existe.');
            }
            if ((int) $id === (int) $currentUser['id'] && ($role !== 'admin' || !$active)) {
                throw new RuntimeException('No puedes quitar tus propios permisos de administrador ni desactivar tu cuenta.');
            }
            if ($existing['role'] === 'admin' && (int) $existing['is_active'] === 1 && ($role !== 'admin' || !$active)) {
                $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin' AND `is_active` = 1")->fetchColumn();
                if ($activeAdmins < 2) {
                    throw new RuntimeException('Debe permanecer al menos un administrador activo.');
                }
            }

            $sql = 'UPDATE `users` SET `name` = :name, `email` = :email, `phone` = :phone, `whatsapp_number` = :whatsapp, `role` = :role, `is_active` = :active';
            $params = [':name' => $name, ':email' => $email, ':phone' => $phone ?: null, ':whatsapp' => $whatsapp ?: null, ':role' => $role, ':active' => $active, ':id' => $id];
            if ($password !== '') {
                if (strlen($password) < 12) {
                    throw new RuntimeException('La nueva contraseña debe tener al menos 12 caracteres.');
                }
                $sql .= ', `password_hash` = :password';
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $statement = $pdo->prepare($sql . ' WHERE `id` = :id');
            $statement->execute($params);
            $notice = 'Se actualizaron los datos de la cuenta.';
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : ((string) $exception->getCode() === '23000' ? 'Ese correo ya pertenece a otra cuenta.' : 'No se pudo guardar la cuenta. Verifica los datos y la base de datos.');
    }
}

if ($pdo instanceof PDO) {
    try {
        $users = $pdo->query('SELECT `id`, `name`, `email`, `phone`, `whatsapp_number`, `role`, `is_active` FROM `users` ORDER BY `role`, `name`')->fetchAll();
    } catch (Throwable) {
        $error = $error ?? 'No se pudo cargar el personal. Verifica la base de datos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal | Ombara</title>
    <link rel="icon" type="image/png" href="../../assets/ico.png">
    <link href="../../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/all.min.css">
    <style>
        :root { --bg:#121921; --surface:#1a222c; --line:rgba(255,255,255,.09); --gold:#c5a880; --text:#e6dfd7; --muted:rgba(230,223,215,.65); }
        body { background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 2rem; border-bottom:1px solid rgba(197,168,128,.25); }
        .brand { display:flex; align-items:center; gap:.8rem; color:var(--gold); text-decoration:none; font-weight:700; }
        .panel { margin-top:1.5rem; padding:1.35rem; background:var(--surface); border:1px solid var(--line); border-radius:6px; }
        .muted { color:var(--muted); }
        .form-control,.form-select { color:var(--text); background-color:var(--bg); border-color:rgba(255,255,255,.18); }
        .form-control:focus,.form-select:focus { color:var(--text); background-color:var(--bg); border-color:var(--gold); box-shadow:0 0 0 .2rem rgba(197,168,128,.15); }
        .form-select option { color:var(--text); background:var(--bg); }
        .table { --bs-table-color:var(--text); --bs-table-bg:transparent; --bs-table-border-color:var(--line); }
        .table th { color:var(--gold); font-size:.75rem; text-transform:uppercase; }
        .table td { vertical-align:middle; }
        .btn-gold { color:var(--bg); background:var(--gold); border-color:var(--gold); font-weight:600; }
        @media(max-width:767.98px) { .topbar { padding:1rem; align-items:flex-start; } .panel { padding:1rem; } }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="index.php"><img src="../../assets/logo-white.png" alt="Ombara" height="30"><span>Equipo Ombara</span></a>
        <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Panel admin</a>
    </header>
    <main class="container-fluid px-lg-5 py-4">
        <p class="muted text-uppercase small mb-1">Administración</p>
        <h1 class="h3">Cuentas y contactos</h1>
        <p class="muted">Gestiona el acceso, correo y número de WhatsApp del personal que recibe comprobantes.</p>
        <?php if ($notice): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="panel" aria-labelledby="new-user-heading">
            <h2 class="h5 mb-3" id="new-user-heading">Agregar empleado</h2>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(appCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-3"><label class="form-label" for="new-name">Nombre</label><input class="form-control" id="new-name" name="name" maxlength="150" required></div>
                <div class="col-md-3"><label class="form-label" for="new-email">Correo</label><input class="form-control" id="new-email" name="email" type="email" maxlength="190" required></div>
                <div class="col-md-2"><label class="form-label" for="new-phone">Teléfono</label><input class="form-control" id="new-phone" name="phone" maxlength="50"></div>
                <div class="col-md-2"><label class="form-label" for="new-whatsapp">WhatsApp (E.164)</label><input class="form-control" id="new-whatsapp" name="whatsapp_number" placeholder="+628123456789" maxlength="30"></div>
                <div class="col-md-2"><label class="form-label" for="new-role">Rol</label><select class="form-select" id="new-role" name="role"><option value="receptionist">Recepcionista</option><option value="admin">Administrador</option></select></div>
                <div class="col-md-4"><label class="form-label" for="new-password">Contraseña inicial (12+ caracteres)</label><input class="form-control" id="new-password" name="password" type="password" minlength="12" autocomplete="new-password" required></div>
                <div class="col-md-4 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" checked><span class="form-check-label">Cuenta activa</span></label></div>
                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-gold" type="submit"><i class="fa-solid fa-user-plus me-1"></i> Crear cuenta</button></div>
            </form>
        </section>

        <section class="panel" aria-labelledby="users-heading">
            <h2 class="h5 mb-3" id="users-heading">Personal registrado</h2>
            <?php if (!$users): ?><p class="muted mb-0">Todavía no hay cuentas de personal.</p><?php else: ?>
                <?php foreach ($users as $user): ?><form id="user-form-<?= (int) $user['id'] ?>" method="post"></form><?php endforeach; ?>
                <div class="table-responsive"><table class="table table-hover">
                    <thead><tr><th>Empleado</th><th>Contacto</th><th>Rol y acceso</th><th>Guardar</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><input form="user-form-<?= (int) $user['id'] ?>" type="hidden" name="csrf_token" value="<?= htmlspecialchars(appCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input form="user-form-<?= (int) $user['id'] ?>" type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input form="user-form-<?= (int) $user['id'] ?>" class="form-control mb-2" name="name" aria-label="Nombre" value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" required><input form="user-form-<?= (int) $user['id'] ?>" class="form-control" name="email" type="email" aria-label="Correo" value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                            <td><input form="user-form-<?= (int) $user['id'] ?>" class="form-control mb-2" name="phone" aria-label="Teléfono" placeholder="Teléfono" value="<?= htmlspecialchars((string) $user['phone'], ENT_QUOTES, 'UTF-8') ?>"><input form="user-form-<?= (int) $user['id'] ?>" class="form-control" name="whatsapp_number" aria-label="WhatsApp internacional" placeholder="WhatsApp +628123456789" value="<?= htmlspecialchars((string) $user['whatsapp_number'], ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><select form="user-form-<?= (int) $user['id'] ?>" class="form-select mb-2" name="role" aria-label="Rol"><option value="receptionist" <?= $user['role'] === 'receptionist' ? 'selected' : '' ?>>Recepcionista</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option></select><label class="form-check"><input form="user-form-<?= (int) $user['id'] ?>" class="form-check-input" type="checkbox" name="is_active" <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?>><span class="form-check-label">Activa</span></label><input form="user-form-<?= (int) $user['id'] ?>" class="form-control mt-2" name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Nueva contraseña (opcional)" aria-label="Nueva contraseña"></td>
                            <td><button form="user-form-<?= (int) $user['id'] ?>" class="btn btn-gold btn-sm" type="submit" title="Guardar cambios"><i class="fa-solid fa-floppy-disk"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>