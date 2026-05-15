<?php
// cron/archive_bookings.php
// Run this monthly via cron:
// 0 0 1 * * php /path/to/mctbs/cron/archive_bookings.php
// Or in XAMPP: Add to Task Scheduler
//
// This script:
// 1. Finds bookings older than 3 years that are completed or cancelled
// 2. Exports them to CSV in /backups/
// 3. Moves them to archive tables (bookings_archive, receipts_archive)
// 4. Deletes them from active tables
//
// CSV files can be opened in Excel for review.

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// Get bookings older than 3 years that are completed or cancelled
$threeYearsAgo = date('Y-m-d H:i:s', strtotime('-3 years'));

$stmt = $db->prepare("
    SELECT * FROM bookings
    WHERE (status = 'completed' OR status = 'cancelled')
    AND created_at < ?
    ORDER BY created_at ASC
");
$stmt->execute([$threeYearsAgo]);
$oldBookings = $stmt->fetchAll();

if (empty($oldBookings)) {
    echo "No bookings to archive.\n";
    exit;
}

echo "Found " . count($oldBookings) . " bookings to archive.\n";

// Get booking IDs for receipts
$bookingIds = array_column($oldBookings, 'id');
$placeholders = str_repeat('?,', count($bookingIds) - 1) . '?';

$receiptStmt = $db->prepare("
    SELECT * FROM receipts
    WHERE booking_id IN ($placeholders)
    ORDER BY generated_at ASC
");
$receiptStmt->execute($bookingIds);
$oldReceipts = $receiptStmt->fetchAll();

// Get payments for archiving
$paymentStmt = $db->prepare("
    SELECT * FROM payments
    WHERE booking_id IN ($placeholders)
    ORDER BY submitted_at ASC
");
$paymentStmt->execute($bookingIds);
$oldPayments = $paymentStmt->fetchAll();

echo "Found " . count($oldReceipts) . " receipts to archive.\n";

// Export to CSV
$csvFile = __DIR__ . '/../backups/booking_archive_' . date('Y-m-d_H-i-s') . '.csv';
$csvHandle = fopen($csvFile, 'w');

// CSV headers for bookings
$headers = [
    'ID', 'Booking Code', 'Unit ID', 'Guest ID', 'Check In', 'Check Out',
    'Num Guests', 'Total Nights', 'Price Per Night', 'Total Amount',
    'Downpayment Amount', 'Remaining Balance', 'Status', 'Payment Status',
    'Cancellation Policy Acknowledged', 'Guest Notes', 'Admin Notes',
    'Rejection Reason', 'Cancellation Reason', 'Cancelled By',
    'Payment Deadline', 'Confirmed At', 'Completed At', 'Created At', 'Updated At'
];
fputcsv($csvHandle, $headers);

// Write booking data
foreach ($oldBookings as $booking) {
    $row = [
        $booking['id'],
        $booking['booking_code'],
        $booking['unit_id'],
        $booking['guest_id'],
        $booking['check_in'],
        $booking['check_out'],
        $booking['num_guests'],
        $booking['total_nights'],
        $booking['price_per_night'],
        $booking['total_amount'],
        $booking['downpayment_amount'],
        $booking['remaining_balance'],
        $booking['status'],
        $booking['payment_status'],
        $booking['cancellation_policy_acknowledged'],
        $booking['guest_notes'],
        $booking['admin_notes'],
        $booking['rejection_reason'],
        $booking['cancellation_reason'],
        $booking['cancelled_by'],
        $booking['payment_deadline'],
        $booking['confirmed_at'],
        $booking['completed_at'],
        $booking['created_at'],
        $booking['updated_at']
    ];
    fputcsv($csvHandle, $row);
}

// Add payments section
fputcsv($csvHandle, ['']); // Empty row
fputcsv($csvHandle, ['PAYMENTS']);
$paymentHeaders = [
    'ID', 'Booking ID', 'Payment Type', 'Payment Method', 'Amount',
    'Reference Number', 'Receipt Path', 'Num Guests', 'Checkout Date',
    'Notes', 'Status', 'Verified By', 'Verified At', 'Submitted At'
];
fputcsv($csvHandle, $paymentHeaders);

foreach ($oldPayments as $payment) {
    fputcsv($csvHandle, [
        $payment['id'],
        $payment['booking_id'],
        $payment['payment_type'],
        $payment['payment_method'],
        $payment['amount'],
        $payment['reference_number'],
        $payment['receipt_path'],
        $payment['num_guests'],
        $payment['checkout_date'],
        $payment['notes'],
        $payment['status'],
        $payment['verified_by'],
        $payment['verified_at'],
        $payment['submitted_at']
    ]);
}

// Add receipts section
fputcsv($csvHandle, ['']); // Empty row
fputcsv($csvHandle, ['RECEIPTS']);
$receiptHeaders = ['ID', 'Booking ID', 'Receipt Number', 'Generated At'];
fputcsv($csvHandle, $receiptHeaders);

foreach ($oldReceipts as $receipt) {
    fputcsv($csvHandle, [
        $receipt['id'],
        $receipt['booking_id'],
        $receipt['receipt_number'],
        $receipt['generated_at']
    ]);
}

fclose($csvHandle);

echo "Exported to CSV: $csvFile\n";

// Move to archive table
$archiveStmt = $db->prepare("
    INSERT INTO bookings_archive (
        booking_code, unit_id, guest_id, check_in, check_out, num_guests,
        total_nights, price_per_night, total_amount, downpayment_amount,
        remaining_balance, status, payment_status, cancellation_policy_acknowledged,
        guest_notes, admin_notes, archive_note, rejection_reason, cancellation_reason,
        cancelled_by, payment_deadline, confirmed_at, completed_at,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
");

$receiptArchiveStmt = $db->prepare("
    INSERT INTO receipts_archive (booking_id, receipt_number, generated_at)
    VALUES (?, ?, ?)
");

$deleteStmt = $db->prepare("DELETE FROM bookings WHERE id = ?");
// Receipts will be deleted automatically due to CASCADE

$archivedCount = 0;
foreach ($oldBookings as $booking) {
    try {
        $archiveStmt->execute([
            $booking['booking_code'],
            $booking['unit_id'],
            $booking['guest_id'],
            $booking['check_in'],
            $booking['check_out'],
            $booking['num_guests'],
            $booking['total_nights'],
            $booking['price_per_night'],
            $booking['total_amount'],
            $booking['downpayment_amount'],
            $booking['remaining_balance'],
            $booking['status'],
            $booking['payment_status'],
            $booking['cancellation_policy_acknowledged'],
            $booking['guest_notes'],
            $booking['admin_notes'],
            'Automatically archived - older than 3 years',
            $booking['rejection_reason'],
            $booking['cancellation_reason'],
            $booking['cancelled_by'],
            $booking['payment_deadline'],
            $booking['confirmed_at'],
            $booking['completed_at'],
            $booking['created_at'],
            $booking['updated_at']
        ]);

        $deleteStmt->execute([$booking['id']]);
        $archivedCount++;
    } catch (Exception $e) {
        echo "Error archiving booking {$booking['id']}: " . $e->getMessage() . "\n";
    }
}

// Archive payments
$paymentArchiveStmt = $db->prepare("
    INSERT INTO payments_archive (
        booking_id, payment_type, payment_method, amount, reference_number,
        receipt_path, num_guests, checkout_date, notes, status,
        verified_by, verified_at, submitted_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$paymentArchivedCount = 0;
foreach ($oldPayments as $payment) {
    try {
        $paymentArchiveStmt->execute([
            $payment['booking_id'],
            $payment['payment_type'],
            $payment['payment_method'],
            $payment['amount'],
            $payment['reference_number'],
            $payment['receipt_path'],
            $payment['num_guests'],
            $payment['checkout_date'],
            $payment['notes'],
            $payment['status'],
            $payment['verified_by'],
            $payment['verified_at'],
            $payment['submitted_at']
        ]);
        $paymentArchivedCount++;
    } catch (Exception $e) {
        echo "Error archiving payment {$payment['id']}: " . $e->getMessage() . "\n";
    }
}

// Archive receipts
$receiptArchivedCount = 0;
foreach ($oldReceipts as $receipt) {
    try {
        $receiptArchiveStmt->execute([
            $receipt['booking_id'],
            $receipt['receipt_number'],
            $receipt['generated_at']
        ]);
        $receiptArchivedCount++;
    } catch (Exception $e) {
        echo "Error archiving receipt {$receipt['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Successfully archived $archivedCount bookings, $paymentArchivedCount payments, and $receiptArchivedCount receipts.\n";
echo "Archive process completed.\n";
?>