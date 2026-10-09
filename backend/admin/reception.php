<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$currentUser = requireRole('admin', 'receptionist');

startAppSession();
if (empty($_SESSION['reception_csrf'])) {
    $_SESSION['reception_csrf'] = bin2hex(random_bytes(32));
}

$paymentMethods = [
    'cash' => 'Efectivo',
    'debit_card' => 'Tarjeta de débito',
    'credit_card' => 'Tarjeta de crédito',
    'bank_transfer' => 'Transferencia bancaria',
    'online' => 'Pago en línea',
];
$paymentStatuses = ['pending' => 'Pendiente', 'paid' => 'Pagado'];
$roomStatuses = [
    'available' => 'Disponible',
    'occupied' => 'Ocupada',
    'maintenance' => 'Mantenimiento',
    'cleaning' => 'Limpieza',
];
$roomTypes = [
    'Ocean View Suite',
    'Garden Villa',
    'Deluxe King Residence',
    'Family Residence',
    'Suite Vista al Océano',
    'Villa Privada Jardín',
    'Residencia Deluxe King',
    'Residencia Familiar',
];
$notice = $_SESSION['reception_notice'] ?? null;
unset($_SESSION['reception_notice']);
$error = null;

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

try {
    $pdo = getDbConnection();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['reception_csrf'], $token)) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_payment') {
            $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
            $method = (string) ($_POST['payment_method'] ?? '');
            $status = (string) ($_POST['payment_status'] ?? '');
            if (!$reservationId || !isset($paymentMethods[$method], $paymentStatuses[$status])) {
                throw new RuntimeException('Selecciona un método y estado de pago válidos.');
            }

            $statement = $pdo->prepare('UPDATE `reservations` SET `payment_method` = :method, `payment_status` = :status WHERE `id` = :id');
            $statement->execute([':method' => $method, ':status' => $status, ':id' => $reservationId]);
            $_SESSION['reception_notice'] = 'Se actualizó el pago de la reserva.';
        } elseif ($action === 'update_room') {
            $roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['room_status'] ?? '');
            if (!$roomId || !isset($roomStatuses[$status])) {
                throw new RuntimeException('Selecciona un estado de habitación válido.');
            }

            $statement = $pdo->prepare('UPDATE `rooms` SET `status` = :status WHERE `id` = :id');
            $statement->execute([':status' => $status, ':id' => $roomId]);
            $_SESSION['reception_notice'] = 'Se actualizó el estado de la habitación.';
        } elseif ($action === 'create_room') {
            $roomNumber = trim((string) ($_POST['room_number'] ?? ''));
            $roomType = trim((string) ($_POST['room_type'] ?? ''));
            if ($roomNumber === '' || strlen($roomNumber) > 20 || !in_array($roomType, $roomTypes, true)) {
                throw new RuntimeException('Indica un número de habitación y una categoría válidos.');
            }

            $statement = $pdo->prepare('INSERT INTO `rooms` (`room_number`, `room_type`) VALUES (:number, :type)');
            $statement->execute([':number' => $roomNumber, ':type' => $roomType]);
            $_SESSION['reception_notice'] = 'Se agregó la habitación al inventario.';
        } else {
            throw new RuntimeException('La acción solicitada no es válida.');
        }

        header('Location: reception.php');
        exit;
    }

    $reservations = $pdo->query('SELECT r.`id`, r.`booking_code`, r.`guest_name`, r.`room_type`, r.`check_in`, r.`check_out`, r.`total_price`, r.`payment_method`, r.`payment_status`, rooms.`room_number` FROM `reservations` r LEFT JOIN `rooms` ON rooms.`id` = r.`room_id` WHERE r.`status` <> \'cancelled\' ORDER BY r.`check_in` ASC LIMIT 200')->fetchAll();
    $rooms = $pdo->query("SELECT rooms.`id`, rooms.`room_number`, rooms.`room_type`, CASE WHEN rooms.`status` IN ('maintenance', 'cleaning') THEN rooms.`status` WHEN EXISTS (SELECT 1 FROM `reservations` WHERE `reservations`.`room_id` = rooms.`id` AND `reservations`.`status` <> 'cancelled' AND `reservations`.`check_in` <= CURDATE() AND `reservations`.`check_out` > CURDATE()) THEN 'occupied' ELSE rooms.`status` END AS `status` FROM `rooms` ORDER BY rooms.`room_number` ASC")->fetchAll();
    $roomCounts = array_fill_keys(array_keys($roomStatuses), 0);
    foreach ($rooms as $room) {
        if (isset($roomCounts[$room['status']])) {
            $roomCounts[$room['status']]++;
        }
    }
    $pendingPayments = (int) $pdo->query("SELECT COUNT(*) FROM `reservations` WHERE `status` <> 'cancelled' AND `payment_status` = 'pending'")->fetchColumn();
} catch (Throwable $exception) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'No se pudo completar la operación. Revisa la conexión y los datos e inténtalo de nuevo.';
    } else {
        $error = 'No se pudo cargar recepción. Verifica que la base de datos esté disponible.';
    }
    $reservations = $reservations ?? [];
    $rooms = $rooms ?? [];
    $roomCounts = $roomCounts ?? array_fill_keys(array_keys($roomStatuses), 0);
    $pendingPayments = $pendingPayments ?? 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recepción | Ombara Hotel &amp; Resort</title>
    <link rel="icon" type="image/png" href="../../assets/ico.png">
    <link href="../../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/all.min.css">
    <style>
        :root {
            --bg: #121921;
            --surface: #1a222c;
            --line: rgba(255, 255, 255, .09);
            --gold: #c5a880;
            --text: #e6dfd7;
            --muted: rgba(230, 223, 215, .65);
        }
        body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .reception-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 2rem; border-bottom: 1px solid rgba(197, 168, 128, .25); }
        .brand { display: flex; align-items: center; gap: .8rem; color: var(--gold); font-weight: 700; text-decoration: none; }
        .page-title { margin: 0; font-size: 1.65rem; }
        .muted { color: var(--muted); }
        .metric { height: 100%; padding: 1.2rem; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; }
        .metric-label { color: var(--muted); font-size: .82rem; text-transform: uppercase; }
        .metric-value { margin: .3rem 0 0; color: var(--gold); font-size: 1.8rem; font-weight: 700; }
        .section { margin-top: 1.5rem; padding: 1.35rem; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; }
        .section-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
        .section-heading h2 { margin: 0; font-size: 1.1rem; }
        .table { --bs-table-color: var(--text); --bs-table-bg: transparent; --bs-table-border-color: var(--line); margin: 0; }
        .table thead th { color: var(--gold); font-size: .75rem; text-transform: uppercase; white-space: nowrap; }
        .table td { vertical-align: middle; }
        .form-select, .form-control { min-width: 125px; color: var(--text); background-color: #121921; border-color: rgba(255,255,255,.18); }
        .form-select:focus, .form-control:focus { color: var(--text); background-color: #121921; border-color: var(--gold); box-shadow: 0 0 0 .2rem rgba(197,168,128,.15); }
        .form-select option { color: var(--text); background: #121921; }
        .btn-gold { color: #121921; background: var(--gold); border-color: var(--gold); font-weight: 600; }
        .btn-gold:hover { color: #121921; background: #d3b991; border-color: #d3b991; }
        .state { display: inline-block; padding: .25rem .55rem; border: 1px solid currentColor; border-radius: 20px; font-size: .75rem; white-space: nowrap; }
        .state-available, .state-paid { color: #81c784; }
        .state-occupied { color: #64b5f6; }
        .state-maintenance { color: #ffb74d; }
        .state-cleaning, .state-pending { color: #ce93d8; }
        .room-form { display: grid; grid-template-columns: minmax(120px, .7fr) minmax(200px, 1.4fr) auto; gap: .6rem; }
        @media (max-width: 767.98px) {
            .reception-header { align-items: flex-start; padding: 1rem; }
            .reception-header .header-actions { display: grid !important; gap: .5rem !important; }
            .header-actions .btn { white-space: nowrap; }
            .section { padding: 1rem; }
            .section-heading { flex-direction: column; }
            .room-form { grid-template-columns: 1fr; }
        }
        @media print {
            body { color: #111; background: #fff; }
            .reception-header .header-actions, form, .btn { display: none !important; }
            .section, .metric { color: #111; background: #fff; border-color: #ccc; }
        }
    </style>
</head>
<body>
    <header class="reception-header">
        <a class="brand" href="index.php">
            <img src="../../assets/logo-white.png" alt="Ombara" height="30">
            <span>Recepción</span>
        </a>
        <div class="header-actions d-flex align-items-center gap-2">
            <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-chart-line me-1"></i> Administración</a>
            <?php endif; ?>
            <a href="../../index.html" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Sitio web</a>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= escape(appCsrfToken()) ?>">
                <button class="btn btn-outline-light btn-sm" type="submit"><i class="fa-solid fa-right-from-bracket me-1"></i> Salir</button>
            </form>
        </div>
    </header>

    <main class="container-fluid px-lg-5 py-4">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
            <div>
                <p class="muted text-uppercase small mb-1">Operación diaria</p>
                <h1 class="page-title">Control de recepción</h1>
            </div>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Imprimir</button>
        </div>

        <?php if ($notice): ?>
            <div class="alert alert-success" role="status"><?= escape((string) $notice) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= escape($error) ?></div>
        <?php endif; ?>

        <section class="row g-3" aria-label="Resumen de operación">
            <div class="col-6 col-xl-3"><div class="metric"><div class="metric-label">Pagos pendientes</div><p class="metric-value"><?= number_format($pendingPayments) ?></p></div></div>
            <?php foreach ($roomStatuses as $status => $label): ?>
                <div class="col-6 col-xl-<?= $status === 'available' ? '3' : '2' ?>"><div class="metric"><div class="metric-label"><?= escape($label) ?></div><p class="metric-value"><?= number_format($roomCounts[$status] ?? 0) ?></p></div></div>
            <?php endforeach; ?>
        </section>

        <section class="section" aria-labelledby="payments-heading">
            <div class="section-heading">
                <div><h2 id="payments-heading">Reservas y pagos</h2><p class="muted small mb-0 mt-1">Actualiza el método recibido y marca el cobro al confirmarlo.</p></div>
                <span class="muted small"><?= count($reservations) ?> reservas activas</span>
            </div>
            <?php if (!$reservations): ?>
                <p class="muted text-center py-4 mb-0">No hay reservas activas para gestionar.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Reserva</th><th>Huésped</th><th>Habitación asignada</th><th>Estancia</th><th>Total</th><th>Pago</th></tr></thead>
                        <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                            <?php $currentMethod = (string) ($reservation['payment_method'] ?? ''); $currentStatus = (string) ($reservation['payment_status'] ?? 'pending'); ?>
                            <tr>
                                <td><strong><?= escape((string) $reservation['booking_code']) ?></strong></td>
                                <td><?= escape((string) $reservation['guest_name']) ?></td>
                                <td><?= escape((string) $reservation['room_type']) ?><?php if ($reservation['room_number']): ?><br><small class="muted">N.º <?= escape((string) $reservation['room_number']) ?></small><?php endif; ?></td>
                                <td><small><?= escape((string) $reservation['check_in']) ?><br><?= escape((string) $reservation['check_out']) ?></small></td>
                                <td>$<?= number_format((float) $reservation['total_price'], 2) ?></td>
                                <td>
                                    <form method="post" class="d-flex flex-wrap align-items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['reception_csrf']) ?>">
                                        <input type="hidden" name="action" value="update_payment">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                        <select class="form-select form-select-sm" name="payment_method" aria-label="Método de pago para <?= escape((string) $reservation['booking_code']) ?>" required>
                                            <?php if ($currentMethod === ''): ?>
                                                <option value="" selected disabled>Sin método</option>
                                            <?php endif; ?>
                                            <?php foreach ($paymentMethods as $value => $label): ?>
                                                <option value="<?= escape($value) ?>" <?= $currentMethod === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select class="form-select form-select-sm" name="payment_status" aria-label="Estado de pago para <?= escape((string) $reservation['booking_code']) ?>" required>
                                            <?php foreach ($paymentStatuses as $value => $label): ?>
                                                <option value="<?= escape($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-gold btn-sm" type="submit" title="Guardar pago"><i class="fa-solid fa-floppy-disk"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="section" aria-labelledby="rooms-heading">
            <div class="section-heading">
                <div><h2 id="rooms-heading">Estado de habitaciones</h2><p class="muted small mb-0 mt-1">Registra habitaciones y mantén disponible el estado operativo actualizado.</p></div>
            </div>
            <form method="post" class="room-form mb-4">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['reception_csrf']) ?>">
                <input type="hidden" name="action" value="create_room">
                <input class="form-control" name="room_number" maxlength="20" placeholder="N.º de habitación" aria-label="Número de habitación" required>
                <select class="form-select" name="room_type" aria-label="Categoría de habitación" required>
                    <option value="" selected disabled>Selecciona una categoría</option>
                    <?php foreach ($roomTypes as $roomType): ?>
                        <option value="<?= escape($roomType) ?>"><?= escape($roomType) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-gold" type="submit"><i class="fa-solid fa-plus me-1"></i> Agregar habitación</button>
            </form>

            <?php if (!$rooms): ?>
                <p class="muted text-center py-4 mb-0">El inventario está vacío. Agrega habitaciones para comenzar a controlar su estado.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Número</th><th>Categoría</th><th>Estado actual</th><th>Actualizar estado</th></tr></thead>
                        <tbody>
                        <?php foreach ($rooms as $room): ?>
                            <?php $roomStatus = (string) $room['status']; ?>
                            <tr>
                                <td><strong><?= escape((string) $room['room_number']) ?></strong></td>
                                <td><?= escape((string) $room['room_type']) ?></td>
                                <td><span class="state state-<?= escape($roomStatus) ?>"><?= escape($roomStatuses[$roomStatus] ?? $roomStatus) ?></span></td>
                                <td>
                                    <form method="post" class="d-flex gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['reception_csrf']) ?>">
                                        <input type="hidden" name="action" value="update_room">
                                        <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                                        <select class="form-select form-select-sm" name="room_status" aria-label="Estado de habitación <?= escape((string) $room['room_number']) ?>" required>
                                            <?php foreach ($roomStatuses as $value => $label): ?>
                                                <option value="<?= escape($value) ?>" <?= $roomStatus === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-gold btn-sm" type="submit" title="Guardar estado"><i class="fa-solid fa-floppy-disk"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script src="../../js/bootstrap.bundle.min.js"></script>
</body>
</html>