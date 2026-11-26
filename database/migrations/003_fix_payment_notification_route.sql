DROP PROCEDURE IF EXISTS sp_record_payment;
DELIMITER $$
CREATE PROCEDURE sp_record_payment(
    IN p_invoice_id BIGINT UNSIGNED,
    IN p_amount DECIMAL(10,2),
    IN p_method VARCHAR(30),
    IN p_user_id INT UNSIGNED,
    IN p_reference VARCHAR(100)
)
BEGIN
    DECLARE v_payment_id BIGINT UNSIGNED;
    DECLARE v_appt_id INT UNSIGNED;
    DECLARE v_owner_id INT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN ROLLBACK; RESIGNAL; END;

    START TRANSACTION;
    SELECT appointment_id INTO v_appt_id FROM Invoices WHERE invoice_id=p_invoice_id FOR UPDATE;
    SELECT p.owner_id INTO v_owner_id
    FROM Appointments a JOIN Pets p ON p.pet_id=a.pet_id
    WHERE a.appointment_id=v_appt_id;

    INSERT INTO Payments(invoice_id,appointment_id,amount_paid,payment_method,verification_status,
                         payment_status,transaction_reference,payment_date,received_by_user_id)
    VALUES(p_invoice_id,v_appt_id,p_amount,p_method,'Verified','Recorded',p_reference,NOW(),p_user_id);
    SET v_payment_id=LAST_INSERT_ID();
    INSERT INTO Receipts(payment_id,receipt_no,issued_by_user_id)
    VALUES(v_payment_id,CONCAT('RCT-',LPAD(v_payment_id,8,'0')),p_user_id);
    INSERT INTO ActivityLog(user_id,action,entity,entity_id,previous_status,new_status,details)
    VALUES(p_user_id,'payment.recorded','payment',v_payment_id,NULL,'Recorded',
           CONCAT('Payment ',p_amount,' via ',p_method,' for invoice #',p_invoice_id));

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment recorded',
           CONCAT('Your payment of ₱',FORMAT(p_amount,2),' was recorded.'),
           'client/billing.php',CONCAT('payment:',v_payment_id)
    FROM Users u WHERE u.`role`='client' AND u.owner_id=v_owner_id AND u.status='active';

    INSERT IGNORE INTO Notifications(user_id,category,title,message,link,event_key)
    SELECT u.user_id,'billing','Payment received',
           CONCAT('Payment ₱',FORMAT(p_amount,2),' received for invoice #',p_invoice_id,'.'),
           'admin/admin_reports.php',CONCAT('payment_admin:',v_payment_id)
    FROM Users u WHERE u.`role`='admin' AND u.status='active';
    COMMIT;
    SELECT v_payment_id AS payment_id;
END$$
DELIMITER ;
