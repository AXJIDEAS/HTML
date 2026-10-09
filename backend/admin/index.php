<?php
declare(strict_types=1);

/**
 * ========================================================
 * Hotel Ombara - Panel de Administración
 * ========================================================
 * Archivo: backend/admin/index.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$currentUser = requireRole('admin');

$error = null;
$reservations = [];
$memberships = [];
$notifications = [];
$stats = [
    'total_reservations' => 0,
    'total_revenue'      => 0.0,
    'active_members'     => 0,
];

try {
    $pdo = getDbConnection();

    // Consultar Reservas
    $resStmt = $pdo->query("SELECT reservations.*, rooms.`room_number` FROM `reservations` LEFT JOIN `rooms` ON rooms.`id` = reservations.`room_id` ORDER BY reservations.`id` DESC LIMIT 100");
    $reservations = $resStmt->fetchAll();

    // Consultar Membresías / Consultas
    $memStmt = $pdo->query("SELECT * FROM `memberships` ORDER BY `id` DESC LIMIT 50");
    $memberships = $memStmt->fetchAll();

    $notifications = $pdo->query('SELECT notification_logs.*, reservations.`booking_code` FROM `notification_logs` LEFT JOIN `reservations` ON reservations.`id` = notification_logs.`reservation_id` ORDER BY notification_logs.`id` DESC LIMIT 100')->fetchAll();

    // Estadísticas
    $stats['total_reservations'] = count($reservations);
    foreach ($reservations as $r) {
        $stats['total_revenue'] += (float) ($r['total_price'] ?? 0);
    }
    $stats['active_members'] = count($memberships);

} catch (PDOException $e) {
    $error = "Nota: Base de datos no conectada aún. " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración | Ombara Hotel &amp; Resort</title>
    <link rel="icon" type="image/png" href="../../assets/ico.png">
    <link href="../../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/all.min.css">
    <style>
        :root {
            --bg-dark: #121921;
            --card-dark: #1a222c;
            --accent: #c5a880;
            --text-main: #e6dfd7;
            --text-muted: rgba(230, 223, 215, 0.65);
        }
        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
        }
        .admin-header {
            background: rgba(18, 25, 33, 0.95);
            border-bottom: 1px solid rgba(197, 168, 128, 0.25);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .admin-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--accent);
            text-decoration: none;
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        .stat-card {
            background: var(--card-dark);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            border-color: rgba(197, 168, 128, 0.4);
        }
        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.5rem;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
            margin: 0;
        }
        .panel-box {
            background: var(--card-dark);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 1.75rem;
            margin-top: 1.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        .table {
            color: var(--text-main);
            margin-bottom: 0;
        }
        .table thead th {
            border-bottom: 1px solid rgba(197, 168, 128, 0.25);
            color: var(--accent);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: transparent;
        }
        .table tbody td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.9rem;
            vertical-align: middle;
            background: transparent;
        }
        .pnr-badge {
            background: rgba(197, 168, 128, 0.15);
            border: 1px solid var(--accent);
            color: var(--accent);
            padding: 3px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: 600;
        }
        .badge-status {
            background: rgba(46, 125, 50, 0.2);
            color: #81c784;
            border: 1px solid #2e7d32;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
        }
        .nav-tabs .nav-link {
            color: var(--text-muted);
            border: none;
            border-bottom: 2px solid transparent;
            font-weight: 500;
            padding: 0.75rem 1.25rem;
        }
        .nav-tabs .nav-link.active {
            color: var(--accent);
            background: transparent;
            border-color: var(--accent);
        }
        .btn-hotel {
            background: linear-gradient(135deg, #c5a880 0%, #a8895e 100%);
            color: #0e263a;
            font-weight: 600;
            border: none;
            padding: 0.45rem 1rem;
            border-radius: 6px;
            text-decoration: none;
        }
        .btn-hotel:hover {
            color: #000;
            opacity: 0.9;
        }
    </style>
</head>
<body>

    <header class="admin-header">
        <a href="../../index.html" class="admin-brand">
            <img src="../../assets/logo-white.png" alt="Ombara" height="32">
            <span>Gestión Hotelera</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="users.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-users-gear me-1"></i> Personal</a>
            <a href="reception.php" class="btn btn-hotel btn-sm">
                <i class="fa-solid fa-bell-concierge me-1"></i> Recepción
            </a>
            <a href="../../index.html" class="btn btn-outline-light btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> Ir al Sitio Web
            </a>
            <button class="btn btn-hotel btn-sm" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i> Imprimir Reporte
            </button>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(appCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn btn-outline-light btn-sm" type="submit"><i class="fa-solid fa-right-from-bracket me-1"></i> Salir</button>
            </form>
        </div>
    </header>

    <div class="container-fluid py-4 px-lg-5">
        <?php if ($error): ?>
            <div class="alert alert-warning mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Métricas Principales -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label"><i class="fa-solid fa-calendar-check me-2"></i> Reservas Registradas</div>
                    <p class="stat-value"><?= number_format($stats['total_reservations']) ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label"><i class="fa-solid fa-dollar-sign me-2"></i> Ingresos Totales (USD)</div>
                    <p class="stat-value">$<?= number_format($stats['total_revenue'], 2) ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-label"><i class="fa-solid fa-users me-2"></i> Consultas / Miembros</div>
                    <p class="stat-value"><?= number_format($stats['active_members']) ?></p>
                </div>
            </div>
        </div>

        <!-- Pestañas y Tablas -->
        <div class="panel-box">
            <ul class="nav nav-tabs mb-4" id="adminTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="res-tab" data-bs-toggle="tab" data-bs-target="#tab-reservations" type="button" role="tab">
                        <i class="fa-solid fa-bed me-2"></i> Reservas Recientes (<?= count($reservations) ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="mem-tab" data-bs-toggle="tab" data-bs-target="#tab-memberships" type="button" role="tab">
                        <i class="fa-solid fa-address-book me-2"></i> Consultas de Contacto (<?= count($memberships) ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#tab-notifications" type="button" role="tab">
                        <i class="fa-solid fa-paper-plane me-2"></i> Notificaciones (<?= count($notifications) ?>)
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="adminTabContent">
                <!-- Pestaña de Reservaciones -->
                <div class="tab-pane fade show active table-responsive" id="tab-reservations" role="tabpanel">
                    <?php if (empty($reservations)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-inbox fa-3x mb-3 text-secondary"></i>
                            <p>No hay reservas registradas en este momento.</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Código PNR</th>
                                    <th>Huésped</th>
                                    <th>Habitación</th>
                                    <th>Llegada</th>
                                    <th>Salida</th>
                                    <th>Noches</th>
                                    <th>Huéspedes</th>
                                    <th>Total USD</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $r): ?>
                                    <tr>
                                        <td><span class="pnr-badge"><?= htmlspecialchars($r['booking_code'] ?? 'OMB') ?></span></td>
                                        <td>
                                            <strong><?= htmlspecialchars($r['guest_name'] ?? '') ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($r['guest_email'] ?? '') ?> | <?= htmlspecialchars($r['guest_phone'] ?? '') ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($r['room_type'] ?? '') ?><?php if (!empty($r['room_number'])): ?><br><small class="text-muted">N.º <?= htmlspecialchars($r['room_number']) ?></small><?php endif; ?></td>
                                        <td><?= htmlspecialchars($r['check_in'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['check_out'] ?? '') ?></td>
                                        <td><?= htmlspecialchars((string)($r['total_nights'] ?? 1)) ?></td>
                                        <td><?= htmlspecialchars((string)($r['adults'] ?? 1)) ?> ad. / <?= htmlspecialchars((string)($r['children'] ?? 0)) ?> niñ.</td>
                                        <td><strong>$<?= number_format((float)($r['total_price'] ?? 0), 2) ?></strong></td>
                                        <td><span class="badge-status"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Confirmada')) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Pestaña de Consultas / Miembros -->
                <div class="tab-pane fade table-responsive" id="tab-memberships" role="tabpanel">
                    <?php if (empty($memberships)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-inbox fa-3x mb-3 text-secondary"></i>
                            <p>No hay solicitudes de contacto registradas.</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Correo</th>
                                    <th>Teléfono</th>
                                    <th>País</th>
                                    <th>Origen</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($memberships as $m): ?>
                                    <tr>
                                        <td>#<?= htmlspecialchars((string)($m['id'] ?? '')) ?></td>
                                        <td><strong><?= htmlspecialchars($m['full_name'] ?? '') ?></strong></td>
                                        <td><?= htmlspecialchars($m['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($m['phone'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($m['country'] ?? '') ?></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($m['source_page'] ?? 'Web') ?></span></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($m['created_at'] ?? '') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade table-responsive" id="tab-notifications" role="tabpanel">
                    <?php if (!$notifications): ?>
                        <div class="text-center py-5 text-muted"><i class="fa-solid fa-inbox fa-3x mb-3 text-secondary"></i><p>No hay intentos de notificación registrados.</p></div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead><tr><th>Reserva</th><th>Canal</th><th>Destinatario</th><th>Resultado</th><th>Fecha</th></tr></thead>
                            <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($notification['booking_code'] ?? 'Reserva eliminada'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $notification['channel'] === 'whatsapp' ? 'WhatsApp' : 'Correo' ?></td>
                                    <td><?= htmlspecialchars($notification['recipient'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="badge <?= $notification['delivery_status'] === 'sent' ? 'bg-success' : ($notification['delivery_status'] === 'failed' ? 'bg-danger' : 'bg-secondary') ?>"><?= htmlspecialchars($notification['delivery_status'], ENT_QUOTES, 'UTF-8') ?></span><?php if ($notification['error_code']): ?><small class="d-block text-muted"><?= htmlspecialchars($notification['error_code'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="../../js/bootstrap.bundle.min.js"></script>
</body>
</html>
