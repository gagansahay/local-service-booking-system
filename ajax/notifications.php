<?php
/**
 * =====================================================================
 *  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
 *  BCSP-064 -- Bachelor of Computer Applications, IGNOU
 * ---------------------------------------------------------------------
 *  FILE    : ajax/notifications.php
 *  MODULE  : 9 -- Notification
 *  PURPOSE : Serve the signed-in user's notifications, and mark them
 *            read when the bell panel is opened.
 *
 *  Every query is scoped by user_id from the SESSION, never from a
 *  request parameter. That is what stops one user reading another
 *  user's notifications by changing a number in the URL.
 * =====================================================================
 */

require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Not signed in.'], 401);
}

$pdo    = db();
$userId = current_user_id();
$action = get('action', 'list');

switch ($action) {

    case 'mark_read':
        $stmt = $pdo->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute([':uid' => $userId]);

        json_response(['ok' => true, 'marked' => $stmt->rowCount(), 'unread' => 0]);
        // no break needed -- json_response() exits

    case 'count':
        json_response(['ok' => true, 'unread' => unread_count($pdo, $userId)]);

    case 'list':
    default:
        $stmt = $pdo->prepare(
            'SELECT notification_id, title, message, link, icon, is_read, created_at
               FROM notifications
              WHERE user_id = :uid
              ORDER BY created_at DESC
              LIMIT 20'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['is_read']    = (bool) $row['is_read'];
            $row['created_at'] = show_datetime($row['created_at']);
        }
        unset($row);

        json_response([
            'ok'            => true,
            'unread'        => unread_count($pdo, $userId),
            'notifications' => $rows,
        ]);
}
