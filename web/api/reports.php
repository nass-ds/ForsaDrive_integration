<?php
/**
 * /reports/* endpoints — report-by-ForsaDrive-ID workflow.
 *
 * - POST   /reports         create a report (any authenticated user)
 * - GET    /reports/my      list the caller's own reports
 * - GET    /reports         (admin/helpdesk) list all reports
 * - PATCH  /reports/:id     (admin/helpdesk) update status
 */
require_once __DIR__ . '/../classes/reports.php';

$action  = $segments[1] ?? '';
$idParam = ctype_digit((string)$action) ? (int)$action : null;

switch (true) {
    // POST /reports
    case ($method === 'POST' && $action === ''): {
        $u = auth_user();
        $b = body();
        $public = trim((string)($b['reported_public_id'] ?? $b['public_id'] ?? ''));
        $cat    = trim((string)($b['category'] ?? ''));
        $desc   = trim((string)($b['description'] ?? ''));
        $rideId = isset($b['ride_id']) ? (int)$b['ride_id'] : null;
        if ($rideId !== null && $rideId <= 0) $rideId = null;

        [$ok, $msg, $rid] = ReportService::create(db(), (int)$u['id'], $public, $cat, $desc, $rideId);
        if (!$ok) json_error($msg, 422);
        json_ok(['message' => $msg, 'report_id' => $rid], 201);
    }

    // GET /reports/my
    case ($method === 'GET' && $action === 'my'): {
        $u = auth_user();
        $rows = ReportService::listMine(db(), (int)$u['id']);
        // Decorate with the human label so the client doesn't need a category map.
        foreach ($rows as &$r) {
            $r['category_label'] = ReportService::categoryLabel($r['category']);
        }
        json_ok(['reports' => $rows]);
    }

    // GET /reports
    case ($method === 'GET' && $action === ''): {
        $u = auth_user();
        if (empty($u['is_admin']) && empty($u['is_helpdesk_agent'])) {
            json_error('Forbidden', 403);
        }
        $rows = ReportService::listAll(db(), [
            'status'    => $_GET['status']    ?? null,
            'public_id' => $_GET['public_id'] ?? null,
        ]);
        foreach ($rows as &$r) {
            $r['category_label'] = ReportService::categoryLabel($r['category']);
        }
        json_ok(['reports' => $rows]);
    }

    // PATCH /reports/:id
    case ($method === 'PATCH' && $idParam !== null): {
        $u = auth_user();
        if (empty($u['is_admin']) && empty($u['is_helpdesk_agent'])) {
            json_error('Forbidden', 403);
        }
        $b = body();
        $status = trim((string)($b['status'] ?? ''));
        $note   = trim((string)($b['admin_note'] ?? ''));
        [$ok, $msg] = ReportService::updateStatus(db(), $idParam, (int)$u['id'], $status, $note);
        if (!$ok) json_error($msg, 422);
        json_ok(['message' => $msg]);
    }

    // GET /reports/categories
    case ($method === 'GET' && $action === 'categories'): {
        $list = [];
        foreach (ReportService::CATEGORIES as $code => $label) {
            $list[] = ['code' => $code, 'label' => $label];
        }
        json_ok(['categories' => $list]);
    }

    default:
        json_error('Not found', 404);
}
