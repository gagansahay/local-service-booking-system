<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : ajax/check-slot.php
 *  MODULE  : 5 -- Booking Management
 *  PURPOSE : Return the bookable start times for one professional on one
 *            date, as JSON, so the booking form can update without a
 *            page reload.
 *
 *  This endpoint is a CONVENIENCE, not a gate. customer/book.php runs
 *  check_slot_available() again before it writes anything, so a caller
 *  who skips this endpoint or fakes its response gains nothing.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

// Only signed-in customers browse the slot grid.
if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Please sign in to view availability.'], 401);
}

$providerId = get_int('provider_id');
$date       = get('date');
$duration   = get_int('duration', 60);

if ($providerId <= 0 || !valid_date($date)) {
    json_response(['ok' => false, 'message' => 'A professional and a valid date are required.'], 400);
}

// Clamp the duration to the range the schema permits, so a crafted
// value cannot make the slot grid loop excessively.
$duration = max(15, min(600, $duration));

$pdo = db();

// The professional must exist, be verified and be active.
$stmt = $pdo->prepare(
    "SELECT p.provider_id, u.full_name
       FROM providers p
       JOIN users u ON u.user_id = p.user_id
      WHERE p.provider_id = :pid
        AND p.verification_status = 'verified'
        AND u.status = 'active'"
);
$stmt->execute([':pid' => $providerId]);

if (!$stmt->fetch()) {
    json_response(['ok' => false, 'message' => 'That professional is not currently accepting bookings.'], 404);
}

$slots = build_slot_grid($pdo, $providerId, $date, $duration);

if (!$slots) {
    json_response([
        'ok'      => false,
        'slots'   => [],
        'message' => 'No working hours are published for that day.',
    ]);
}

$free = array_values(array_filter($slots, static fn(array $s): bool => $s['free']));

json_response([
    'ok'    => true,
    'date'  => $date,
    'count' => count($free),
    'slots' => $slots,
]);
