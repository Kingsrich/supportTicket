<?php
/**
 * Shared email routing helpers.
 *
 * Usage:
 *
 * $route = getEmailNotificationRoute($connection, 'it_ticket_created');
 *
 * $route['to']; // array of To recipients
 * $route['cc']; // array of CC recipients
 */

function notificationEmailArray(?string $emails): array
{
    if (!$emails) {
        return [];
    }

    $parts = preg_split('/[\s,;]+/', trim($emails));

    return array_values(
        array_unique(
            array_filter(
                array_map(
                    'trim',
                    $parts
                )
            )
        )
    );
}


function getEmailNotificationRoute(
    mysqli $connection,
    string $routeKey
): array {

    $stmt = mysqli_prepare(
        $connection,
        "
        SELECT
            to_emails,
            cc_emails
        FROM email_notification_routes
        WHERE route_key = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $routeKey
    );

    mysqli_stmt_execute(
        $stmt
    );

    $result =
        mysqli_stmt_get_result(
            $stmt
        );

    $row =
        $result
            ? mysqli_fetch_assoc(
                $result
            )
            : null;

    return [
        'to' => notificationEmailArray(
            $row['to_emails']
            ?? ''
        ),

        'cc' => notificationEmailArray(
            $row['cc_emails']
            ?? ''
        )
    ];
}
