DROP PROCEDURE IF EXISTS sp_void_payment;
DELIMITER $$
CREATE PROCEDURE sp_void_payment(
    IN p_payment_id BIGINT UNSIGNED,
    IN p_reason VARCHAR(255),
    IN p_user_id INT UNSIGNED
)
BEGIN
    DECLARE v_invoice_id BIGINT UNSIGNED;
    DECLARE v_amount DECIMAL(10,2);
    DECLARE v_payment_status VARCHAR(20);
    DECLARE v_owner_id INT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN ROLLBACK; RESIGNAL; END;

    IF p_reason IS NULL OR CHAR_LENGTH(TRIM(p_reason))=0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A correction reason is required.';
    END IF;

    START TRANSACTION;
    SELECT invoice_id,amount_paid,payment_status
    INTO v_invoice_id,v_amount,v_payment_status
    FROM Payments WHERE payment_id=p_payment_id FOR UPDATE;
    IF v_invoice_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment not found.';
    END IF;
    IF v_payment_status<>'Recorded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only recorded payments can be voided.';
    END IF;

    SELECT p.owner_id INTO v_owner_id
    FROM Invoices i
    JOIN Appointments a ON a.appointment_id=i.appointment_id
    JOIN Pets p ON p.pet_id=a.pet_id
    WHERE i.invoice_id=v_invoice_id;

    INSERT INTO PaymentCorrections
        (payment_id,previous_amount,new_amount,previous_status,new_status,reason,corrected_by_user_id)
    VALUES(p_payment_id,v_amount,0,'Recorded','Voided',p_reason,p_user_id);
    UPDATE Payments
    SET payment_status='Voided',void_reason=p_reason,corrected_at=NOW()
    WHERE payment_id=p_payment_id;
    INSERT INTO ActivityLog(user_id,action,entity,entity_id,previous_status,new_status,details)
    VALUES(p_user_id,'payment.voided','payment',p_payment_id,'Recorded','Voided',p_reason);

    UPDATE Invoices i
    SET invoice_status=CASE
        WHEN COALESCE((SELECT SUM(amount_paid) FROM Payments WHERE invoice_id=i.invoice_id AND payment_status='Recorded'),0)<=0 THEN 'Unpaid'
        WHEN COALESCE((SELECT SUM(amount_paid) FROM Payments WHERE invoice_id=i.invoice_id AND payment_status='Recorded'),0)>=
             (SELECT SUM(line_total) FROM InvoiceLines WHERE invoice_id=i.invoice_id) THEN 'Paid'
        ELSE 'Partially Paid'
    END
    WHERE invoice_id=v_invoice_id;

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment voided',
           CONCAT('A payment of ₱',FORMAT(v_amount,2),' for invoice #',v_invoice_id,' was voided.'),
           'client/billing.php',CONCAT('payment_voided:',p_payment_id)
    FROM Users u WHERE u.`role`='client' AND u.owner_id=v_owner_id AND u.status='active';

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment correction',
           CONCAT('Payment ₱',FORMAT(v_amount,2),' for invoice #',v_invoice_id,' was voided.'),
           'admin/admin_reports.php',CONCAT('payment_voided_admin:',p_payment_id)
    FROM Users u WHERE u.`role`='admin' AND u.status='active';

    COMMIT;
END$$
DELIMITER ;
