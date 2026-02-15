"""
Card Graph - Comprehensive Email Importer
Connects to Yahoo Mail via IMAP, processes multiple email types:

1. eBay 'Order confirmed' -> Insert purchase data, move to 'eBay' folder
2. PayPal 'eBay Commerce Inc.' -> Insert purchase data (source=paypal_ebay), move to 'PayPal' folder
3. PayPal 'You sent' -> Insert direct payment (source=paypal_direct), move to 'PayPal' folder
4. eBay 'ORDER DELIVERED' -> Update delivery_date on matching orders, move to 'eBay-Notice' folder
5. eBay 'We sent your payout' -> Log payout info, move to 'eBay-Notice' folder
6. eBay notices (Outbid, You won!, etc.) -> Move to 'eBay-Notice' folder

Usage:
    python ebay_import.py              # Full run: import all + move all
    python ebay_import.py --dry-run    # Preview without importing or moving
    python ebay_import.py --no-move    # Import but don't move emails
    python ebay_import.py --phase X    # Run specific phase only (1-6)
"""
import imaplib
import email
from email.header import decode_header
from email.utils import parsedate_to_datetime
import sys
import pymysql
from ebay_parser import (
    parse_order_confirmed_email,
    parse_paypal_ebay_email,
    parse_paypal_direct_email,
    parse_order_delivered_email,
    parse_payout_email,
    parse_delivery_timestamp,
    html_to_text,
)

# === Configuration ===
IMAP_HOST = 'imap.mail.yahoo.com'
IMAP_PORT = 993
YAHOO_EMAIL = 'collinwalters123@yahoo.com'
YAHOO_APP_PASSWORD = 'pjnpsukyleqttwoq'

EBAY_FOLDER = 'eBay'           # Order confirmations (after import)
PAYPAL_FOLDER = 'PayPal'       # PayPal receipts (after import)
NOTICE_FOLDER = 'eBay-Notice'  # Notices, delivered, payouts, etc.

DB_CONFIG = {
    'host': '192.168.0.215',
    'port': 3307,
    'user': 'cg_app',
    'password': 'ACe!sysD#0kVnBWF',
    'database': 'card_graph',
    'charset': 'utf8mb4',
}


def safe(s):
    """Make string ASCII-safe for Windows console output."""
    return (s or '').encode('ascii', errors='replace').decode('ascii')


def decode_subject(msg):
    """Decode email subject header."""
    subject = msg.get('Subject', '')
    decoded_parts = decode_header(subject)
    result = ''
    for part, enc in decoded_parts:
        if isinstance(part, bytes):
            result += part.decode(enc or 'utf-8', errors='replace')
        else:
            result += part
    return result


def get_body_html(msg):
    """Get HTML body from email message."""
    if msg.is_multipart():
        for part in msg.walk():
            if part.get_content_type() == 'text/html':
                payload = part.get_payload(decode=True)
                if payload:
                    charset = part.get_content_charset() or 'utf-8'
                    return payload.decode(charset, errors='replace')
    else:
        payload = msg.get_payload(decode=True)
        if payload:
            charset = msg.get_content_charset() or 'utf-8'
            return payload.decode(charset, errors='replace')
    return ''


def parse_email_date(msg):
    """Parse email Date header into a datetime string."""
    date_str = msg.get('Date', '')
    try:
        dt = parsedate_to_datetime(date_str)
        return dt.strftime('%Y-%m-%d %H:%M:%S')
    except Exception:
        return None


def get_existing_order_numbers(db_conn):
    """Get set of order numbers already in the database."""
    cursor = db_conn.cursor()
    cursor.execute("SELECT order_number FROM CG_EbayOrders")
    existing = {row[0] for row in cursor.fetchall()}
    cursor.close()
    return existing


def get_existing_paypal_txn_ids(db_conn):
    """Get set of PayPal transaction IDs already in the database."""
    cursor = db_conn.cursor()
    cursor.execute("SELECT paypal_transaction_id FROM CG_EbayOrders WHERE paypal_transaction_id IS NOT NULL")
    existing = {row[0] for row in cursor.fetchall()}
    cursor.close()
    return existing


def log_email_processed(db_conn, email_uid, email_source, email_type, action):
    """Log a processed email for dedup tracking."""
    cursor = db_conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO CG_EmailProcessLog (email_uid, email_source, email_type, action_taken)
            VALUES (%s, %s, %s, %s)
        """, (str(email_uid), email_source, email_type, action))
        db_conn.commit()
    except Exception:
        pass  # Ignore duplicate key errors
    cursor.close()


def is_email_processed(db_conn, email_uid):
    """Check if an email UID has already been processed."""
    cursor = db_conn.cursor()
    cursor.execute("SELECT log_id FROM CG_EmailProcessLog WHERE email_uid = %s", (str(email_uid),))
    result = cursor.fetchone()
    cursor.close()
    return result is not None


def insert_order(db_conn, parsed, email_uid, email_subject, source='ebay_confirmed'):
    """Insert a parsed order into the database. Returns the order ID or None if duplicate."""
    cursor = db_conn.cursor()

    # Check if order already exists
    cursor.execute("SELECT ebay_order_id FROM CG_EbayOrders WHERE order_number = %s", (parsed['order_number'],))
    existing = cursor.fetchone()
    if existing:
        cursor.close()
        return None

    # Determine seller name from first item
    seller = None
    if parsed['items']:
        seller = parsed['items'][0].get('seller')

    # Insert order with source
    paypal_txn_id = parsed.get('paypal_transaction_id')
    reported_count = parsed.get('reported_item_count')
    cursor.execute("""
        INSERT INTO CG_EbayOrders
            (order_number, order_date, transaction_type, subtotal, shipping_cost,
             sales_tax, total_amount, seller_buyer_name, email_uid, email_subject,
             status, source, paypal_transaction_id, reported_item_count)
        VALUES (%s, %s, 'PURCHASE', %s, %s, %s, %s, %s, %s, %s, 'Confirmed', %s, %s, %s)
    """, (
        parsed['order_number'],
        parsed['order_date'],
        parsed['subtotal'],
        parsed.get('shipping_cost', parsed.get('shipping', 0)),
        parsed['sales_tax'],
        parsed['total'],
        seller,
        str(email_uid),
        email_subject[:500] if email_subject else None,
        source,
        paypal_txn_id,
        reported_count,
    ))
    order_id = cursor.lastrowid

    # Insert items
    for item in parsed['items']:
        cursor.execute("""
            INSERT INTO CG_EbayOrderItems
                (ebay_order_id, item_title, item_price, ebay_item_number, seller_buyer_name)
            VALUES (%s, %s, %s, %s, %s)
        """, (
            order_id,
            item['title'][:500] if item['title'] else 'Unknown Item',
            item['price'],
            item['item_id'],
            item['seller'],
        ))

    db_conn.commit()
    cursor.close()
    return order_id


def move_emails(mail, email_ids, target_folder, label, dry_run=False):
    """Move a list of email IDs to the target folder. Returns count moved."""
    if not email_ids:
        return 0

    if dry_run:
        print(f"  Would move {len(email_ids)} emails to '{target_folder}'")
        return len(email_ids)

    moved = 0
    for eid in email_ids:
        try:
            result = mail.copy(eid, target_folder)
            if result[0] == 'OK':
                mail.store(eid, '+FLAGS', '\\Deleted')
                moved += 1
        except Exception as e:
            print(f"  Warning: Could not move email {eid}: {safe(str(e))}")

    if moved > 0:
        mail.expunge()
    print(f"  Moved {moved} {label} to '{target_folder}'")
    return moved


def reselect_inbox(mail):
    """Re-select inbox after expunge to ensure clean state."""
    mail.select('Inbox')


# =============================================================================
# Phase 1: eBay Order Confirmations
# =============================================================================
def phase1_ebay_orders(mail, db_conn, dry_run=False, no_move=False):
    """Import eBay order confirmation emails from Inbox and eBay-Notice."""
    print("\n=== PHASE 1: eBay Order Confirmations ===")

    existing_orders = get_existing_order_numbers(db_conn)
    print(f"  {len(existing_orders)} orders already in database")

    # Search multiple folders (eBay-Notice has orders moved by v1 cleaner)
    search_folders = ['Inbox', 'eBay-Notice']
    # List of (email_id, folder) tuples
    all_emails = []

    for folder in search_folders:
        try:
            mail.select(folder)
            status, data = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "order confirmed")')
            folder_ids = data[0].split() if data[0] else []

            status2, data2 = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "Your order is confirmed")')
            if data2[0]:
                ids_set = set(folder_ids)
                for eid in data2[0].split():
                    if eid not in ids_set:
                        folder_ids.append(eid)

            if folder_ids:
                print(f"  Found {len(folder_ids)} order emails in {folder}")
                for eid in folder_ids:
                    all_emails.append((eid, folder))
        except Exception as e:
            print(f"  Error searching {folder}: {safe(str(e))}")

    if not all_emails:
        print("  No order confirmation emails found.")
        return 0, 0, 0

    email_ids = all_emails
    print(f"  Total: {len(email_ids)} order confirmation emails across folders")

    imported = 0
    skipped = 0
    errors = 0
    current_folder = None

    for idx, (eid, folder) in enumerate(email_ids):
        try:
            # Select the correct folder if needed
            if folder != current_folder:
                mail.select(folder)
                current_folder = folder

            status, data = mail.fetch(eid, '(RFC822)')
            if status != 'OK':
                continue

            msg = email.message_from_bytes(data[0][1])
            subject = decode_subject(msg)
            email_date = parse_email_date(msg)
            html_body = get_body_html(msg)

            if not html_body or not email_date:
                skipped += 1
                continue

            parsed = parse_order_confirmed_email(html_body, email_date)

            if not parsed['order_number'] or not parsed['items']:
                print(f"    [{idx+1}/{len(email_ids)}] SKIP (no data): {safe(subject)[:60]}")
                skipped += 1
                continue

            if parsed['order_number'] in existing_orders:
                skipped += 1
                continue

            if dry_run:
                print(f"    [{idx+1}/{len(email_ids)}] WOULD IMPORT: #{parsed['order_number']} "
                      f"({len(parsed['items'])} items, ${parsed['total']:.2f})")
                imported += 1
            else:
                order_id = insert_order(db_conn, parsed, eid.decode(), subject, 'ebay_confirmed')
                if order_id:
                    print(f"    [{idx+1}/{len(email_ids)}] IMPORTED: #{parsed['order_number']} "
                          f"({len(parsed['items'])} items, ${parsed['total']:.2f})")
                    imported += 1
                    existing_orders.add(parsed['order_number'])
                    log_email_processed(db_conn, eid.decode(), 'ebay', 'order_confirmed', 'imported')
                else:
                    skipped += 1

        except Exception as e:
            print(f"    [{idx+1}/{len(email_ids)}] ERROR: {safe(str(e))}")
            errors += 1

    # Re-select inbox for subsequent phases
    reselect_inbox(mail)

    print(f"  Result: {imported} imported, {skipped} skipped, {errors} errors")
    return imported, skipped, errors


# =============================================================================
# Phase 2: PayPal eBay Commerce Purchases
# =============================================================================
def phase2_paypal_ebay(mail, db_conn, dry_run=False, no_move=False):
    """Import PayPal eBay Commerce Inc. purchase receipts."""
    print("\n=== PHASE 2: PayPal eBay Commerce Purchases ===")

    existing_orders = get_existing_order_numbers(db_conn)
    existing_txns = get_existing_paypal_txn_ids(db_conn)

    # Search for PayPal eBay Commerce emails
    status, data = mail.search(None, '(FROM "service@paypal.com" SUBJECT "eBay Commerce")')
    email_ids = data[0].split() if data[0] else []

    if not email_ids:
        print("  No PayPal eBay Commerce emails found.")
        return 0, 0, 0

    print(f"  Found {len(email_ids)} PayPal eBay Commerce emails")

    imported = 0
    skipped = 0
    errors = 0
    linked = 0
    move_ids = []

    for idx, eid in enumerate(email_ids):
        try:
            status, data = mail.fetch(eid, '(RFC822)')
            if status != 'OK':
                continue

            msg = email.message_from_bytes(data[0][1])
            subject = decode_subject(msg)
            email_date = parse_email_date(msg)
            html_body = get_body_html(msg)

            if not html_body or not email_date:
                skipped += 1
                continue

            parsed = parse_paypal_ebay_email(html_body, email_date)

            if not parsed['order_number'] or parsed['total'] <= 0:
                print(f"    [{idx+1}/{len(email_ids)}] SKIP (no data): {safe(subject)[:60]}")
                skipped += 1
                move_ids.append(eid)
                continue

            # Check dedup by order number OR PayPal transaction ID
            if parsed['order_number'] in existing_orders:
                skipped += 1
                move_ids.append(eid)
                continue

            txn_id = parsed.get('paypal_transaction_id', '')
            if txn_id and txn_id in existing_txns:
                skipped += 1
                move_ids.append(eid)
                continue

            # Check if this PayPal eBay purchase matches an existing eBay confirmed order
            # by total amount and close date (same day) - link instead of duplicating
            matched_existing = False
            if parsed['total'] > 0 and parsed['order_date'] and not dry_run:
                cursor = db_conn.cursor()
                cursor.execute("""
                    SELECT ebay_order_id, order_number FROM CG_EbayOrders
                    WHERE source = 'ebay_confirmed'
                      AND ABS(total_amount - %s) < 0.02
                      AND DATE(order_date) = DATE(%s)
                      AND paypal_transaction_id IS NULL
                    LIMIT 1
                """, (parsed['total'], parsed['order_date']))
                match = cursor.fetchone()
                if match:
                    # Link the PayPal transaction ID to the existing eBay order
                    cursor.execute("""
                        UPDATE CG_EbayOrders
                        SET paypal_transaction_id = %s
                        WHERE ebay_order_id = %s
                    """, (txn_id, match[0]))
                    db_conn.commit()
                    print(f"    [{idx+1}/{len(email_ids)}] LINKED: ${parsed['total']:.2f} "
                          f"TXN:{txn_id[:12]}... -> existing #{match[1]}")
                    linked += 1
                    matched_existing = True
                    if txn_id:
                        existing_txns.add(txn_id)
                    log_email_processed(db_conn, eid.decode(), 'paypal', 'ebay_commerce', 'linked:' + str(match[1]))
                cursor.close()

            if matched_existing:
                move_ids.append(eid)
                continue

            if dry_run:
                print(f"    [{idx+1}/{len(email_ids)}] WOULD IMPORT: ${parsed['total']:.2f} "
                      f"TXN:{txn_id[:12]}... - {safe(subject)[:50]}")
                imported += 1
                move_ids.append(eid)
            else:
                order_id = insert_order(db_conn, parsed, eid.decode(), subject, 'paypal_ebay')
                if order_id:
                    print(f"    [{idx+1}/{len(email_ids)}] IMPORTED: ${parsed['total']:.2f} "
                          f"TXN:{txn_id[:12]}...")
                    imported += 1
                    existing_orders.add(parsed['order_number'])
                    if txn_id:
                        existing_txns.add(txn_id)
                    log_email_processed(db_conn, eid.decode(), 'paypal', 'ebay_commerce', 'imported')
                else:
                    skipped += 1
                move_ids.append(eid)

        except Exception as e:
            print(f"    [{idx+1}/{len(email_ids)}] ERROR: {safe(str(e))}")
            errors += 1

    moved = 0
    if not no_move and move_ids:
        moved = move_emails(mail, move_ids, PAYPAL_FOLDER, 'PayPal eBay emails', dry_run)
        if not dry_run:
            reselect_inbox(mail)

    print(f"  Result: {imported} imported, {linked} linked, {skipped} skipped, {errors} errors, {moved} moved")
    return imported, skipped, errors


# =============================================================================
# Phase 3: PayPal Direct Payments
# =============================================================================
def phase3_paypal_direct(mail, db_conn, dry_run=False, no_move=False):
    """Import PayPal 'You sent' direct payment emails."""
    print("\n=== PHASE 3: PayPal Direct Payments ===")

    existing_orders = get_existing_order_numbers(db_conn)
    existing_txns = get_existing_paypal_txn_ids(db_conn)

    # Search for "You sent" PayPal emails
    status, data = mail.search(None, '(FROM "service@paypal.com" SUBJECT "You sent")')
    email_ids = data[0].split() if data[0] else []

    if not email_ids:
        print("  No PayPal direct payment emails found.")
        return 0, 0, 0

    print(f"  Found {len(email_ids)} PayPal direct payment emails")

    imported = 0
    skipped = 0
    errors = 0
    move_ids = []

    for idx, eid in enumerate(email_ids):
        try:
            status, data = mail.fetch(eid, '(RFC822)')
            if status != 'OK':
                continue

            msg = email.message_from_bytes(data[0][1])
            subject = decode_subject(msg)
            email_date = parse_email_date(msg)
            html_body = get_body_html(msg)

            if not html_body or not email_date:
                skipped += 1
                continue

            parsed = parse_paypal_direct_email(html_body, email_date)

            if not parsed['order_number'] or parsed['total'] <= 0:
                print(f"    [{idx+1}/{len(email_ids)}] SKIP (no data): {safe(subject)[:60]}")
                skipped += 1
                move_ids.append(eid)
                continue

            if parsed['order_number'] in existing_orders:
                skipped += 1
                move_ids.append(eid)
                continue

            txn_id = parsed.get('paypal_transaction_id', '')
            if txn_id and txn_id in existing_txns:
                skipped += 1
                move_ids.append(eid)
                continue

            if dry_run:
                print(f"    [{idx+1}/{len(email_ids)}] WOULD IMPORT: ${parsed['total']:.2f} "
                      f"to {safe(parsed.get('recipient', '?'))} - TXN:{txn_id[:12]}...")
                imported += 1
                move_ids.append(eid)
            else:
                order_id = insert_order(db_conn, parsed, eid.decode(), subject, 'paypal_direct')
                if order_id:
                    print(f"    [{idx+1}/{len(email_ids)}] IMPORTED: ${parsed['total']:.2f} "
                          f"to {safe(parsed.get('recipient', '?'))}")
                    imported += 1
                    existing_orders.add(parsed['order_number'])
                    if txn_id:
                        existing_txns.add(txn_id)
                    log_email_processed(db_conn, eid.decode(), 'paypal', 'direct_payment', 'imported')
                else:
                    skipped += 1
                move_ids.append(eid)

        except Exception as e:
            print(f"    [{idx+1}/{len(email_ids)}] ERROR: {safe(str(e))}")
            errors += 1

    moved = 0
    if not no_move and move_ids:
        moved = move_emails(mail, move_ids, PAYPAL_FOLDER, 'PayPal direct emails', dry_run)
        if not dry_run:
            reselect_inbox(mail)

    print(f"  Result: {imported} imported, {skipped} skipped, {errors} errors, {moved} moved")
    return imported, skipped, errors


# =============================================================================
# Phase 4: ORDER DELIVERED - Update delivery dates
# =============================================================================
def phase4_delivered(mail, db_conn, dry_run=False, no_move=False):
    """Process ORDER DELIVERED emails from Inbox and Cleaned folders."""
    print("\n=== PHASE 4: ORDER DELIVERED (Update Delivery Dates) ===")

    # Search multiple folders for delivery emails
    search_folders = ['Inbox', 'eBay-Notice', 'Cleaned-1', 'Cleaned-2', 'Cleaned-A', 'Cleaned-B']
    all_emails = []

    for folder in search_folders:
        try:
            mail.select(folder)
            status, data = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "ORDER DELIVERED")')
            folder_ids = data[0].split() if data[0] else []
            if folder_ids:
                print(f"  Found {len(folder_ids)} delivery emails in {folder}")
                for eid in folder_ids:
                    all_emails.append((eid, folder))
        except Exception:
            pass

    if not all_emails:
        print("  No ORDER DELIVERED emails found.")
        return 0, 0

    email_ids = all_emails
    print(f"  Total: {len(email_ids)} ORDER DELIVERED emails")

    updated = 0
    no_match = 0
    errors = 0
    current_folder = None

    for idx, (eid, folder) in enumerate(email_ids):
        try:
            if folder != current_folder:
                mail.select(folder)
                current_folder = folder

            status, data = mail.fetch(eid, '(RFC822)')
            if status != 'OK':
                continue

            msg = email.message_from_bytes(data[0][1])
            subject = decode_subject(msg)
            email_date = parse_email_date(msg)
            html_body = get_body_html(msg)

            if not html_body:
                continue

            parsed = parse_order_delivered_email(html_body, email_date)

            if not parsed['order_number']:
                no_match += 1
                continue

            # Convert delivery timestamp to datetime
            delivery_dt = parse_delivery_timestamp(parsed['delivery_timestamp'], email_date)

            if dry_run:
                print(f"    [{idx+1}/{len(email_ids)}] WOULD UPDATE: #{parsed['order_number']} "
                      f"delivered {delivery_dt or '?'} - {safe(parsed.get('item_title', ''))[:40]}")
                updated += 1
            else:
                # Update the order's delivery_date and status
                cursor = db_conn.cursor()
                cursor.execute("""
                    UPDATE CG_EbayOrders
                    SET delivery_date = %s, status = 'Delivered'
                    WHERE order_number = %s AND delivery_date IS NULL
                """, (delivery_dt, parsed['order_number']))

                if cursor.rowcount > 0:
                    print(f"    [{idx+1}/{len(email_ids)}] UPDATED: #{parsed['order_number']} "
                          f"delivered {delivery_dt}")
                    updated += 1
                else:
                    # Check if order exists at all
                    cursor.execute("SELECT ebay_order_id, status FROM CG_EbayOrders WHERE order_number = %s",
                                   (parsed['order_number'],))
                    row = cursor.fetchone()
                    if row:
                        # Already has delivery date or already delivered
                        pass
                    else:
                        no_match += 1

                db_conn.commit()
                cursor.close()
                log_email_processed(db_conn, eid.decode(), 'ebay', 'order_delivered', 'updated')

        except Exception as e:
            print(f"    [{idx+1}/{len(email_ids)}] ERROR: {safe(str(e))}")
            errors += 1

    # Re-select inbox for subsequent phases
    reselect_inbox(mail)

    print(f"  Result: {updated} updated, {no_match} no matching order, {errors} errors")
    return updated, no_match


# =============================================================================
# Phase 5: Payout Emails
# =============================================================================
def phase5_payouts(mail, db_conn, dry_run=False, no_move=False):
    """Process eBay payout emails - log payout info."""
    print("\n=== PHASE 5: eBay Payout Emails ===")

    status, data = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "We sent your payout")')
    email_ids = data[0].split() if data[0] else []

    if not email_ids:
        print("  No payout emails found.")
        return 0

    print(f"  Found {len(email_ids)} payout emails")

    logged = 0
    move_ids = []

    for idx, eid in enumerate(email_ids):
        try:
            status, data = mail.fetch(eid, '(RFC822)')
            if status != 'OK':
                continue

            msg = email.message_from_bytes(data[0][1])
            subject = decode_subject(msg)
            email_date = parse_email_date(msg)
            html_body = get_body_html(msg)

            if not html_body:
                move_ids.append(eid)
                continue

            parsed = parse_payout_email(html_body, email_date)

            if dry_run:
                print(f"    [{idx+1}/{len(email_ids)}] PAYOUT: ${parsed['payout_amount']:.2f} "
                      f"ID:{parsed['payout_id']} Date:{parsed['payout_date']} "
                      f"Bank:{safe(parsed.get('bank_info', ''))}")
                logged += 1
            else:
                print(f"    [{idx+1}/{len(email_ids)}] PAYOUT: ${parsed['payout_amount']:.2f} "
                      f"ID:{parsed['payout_id']} Date:{parsed['payout_date']}")
                log_email_processed(db_conn, eid.decode(), 'ebay', 'payout',
                                    'logged:$%.2f:ID:%s' % (parsed['payout_amount'], parsed['payout_id']))
                logged += 1

            move_ids.append(eid)

        except Exception as e:
            print(f"    [{idx+1}/{len(email_ids)}] ERROR: {safe(str(e))}")

    moved = 0
    if not no_move and move_ids:
        moved = move_emails(mail, move_ids, NOTICE_FOLDER, 'payout emails', dry_run)
        if not dry_run:
            reselect_inbox(mail)

    print(f"  Result: {logged} logged, {moved} moved")
    return logged


# =============================================================================
# Phase 6: Remaining PayPal emails (refunds, other)
# =============================================================================
def phase6_paypal_other(mail, db_conn, dry_run=False, no_move=False):
    """Move remaining PayPal emails to PayPal folder."""
    print("\n=== PHASE 6: Remaining PayPal Emails ===")

    status, data = mail.search(None, '(FROM "paypal.com")')
    email_ids = data[0].split() if data[0] else []

    if not email_ids:
        print("  No remaining PayPal emails found.")
        return 0

    print(f"  Found {len(email_ids)} remaining PayPal emails")

    move_ids = []
    for idx, eid in enumerate(email_ids):
        try:
            # Just peek at subject for logging
            status, data = mail.fetch(eid, '(BODY.PEEK[HEADER.FIELDS (SUBJECT)])')
            raw = data[0][1]
            msg = email.message_from_bytes(raw)
            subject = decode_subject(msg)
            print(f"    {safe(subject)[:70]}")
            move_ids.append(eid)
        except Exception:
            move_ids.append(eid)

    moved = 0
    if not no_move and move_ids:
        moved = move_emails(mail, move_ids, PAYPAL_FOLDER, 'PayPal other emails', dry_run)
        if not dry_run:
            reselect_inbox(mail)

    print(f"  Result: {moved} moved")
    return moved


# =============================================================================
# Phase 7: eBay Notice Cleanup (Outbid, You won!, etc.)
# =============================================================================
def phase7_notices(mail, dry_run=False, no_move=False):
    """Move remaining eBay notification emails to eBay-Notice folder."""
    print("\n=== PHASE 7: eBay Notice Cleanup ===")

    notice_searches = [
        ('Outbid', '(FROM "ebay@ebay.com" SUBJECT "Outbid")'),
        ('You won!', '(FROM "ebay@ebay.com" SUBJECT "You won")'),
        ('Order update', '(FROM "ebay@ebay.com" SUBJECT "Order update")'),
        ('update on your order', '(FROM "ebay@ebay.com" SUBJECT "update on your order")'),
        ('Refund issued', '(FROM "ebay@ebay.com" SUBJECT "Refund issued")'),
        ('has shipped', '(FROM "ebay@ebay.com" SUBJECT "has shipped")'),
        ('on its way', '(FROM "ebay@ebay.com" SUBJECT "on its way")'),
        ('Leave feedback', '(FROM "ebay@ebay.com" SUBJECT "Leave feedback")'),
        ('Rate your purchase', '(FROM "ebay@ebay.com" SUBJECT "Rate your purchase")'),
        ('Tracking', '(FROM "ebay@ebay.com" SUBJECT "Tracking")'),
        ('Delivery', '(FROM "ebay@ebay.com" SUBJECT "Delivery")'),
        ('similar items', '(FROM "ebay@ebay.com" SUBJECT "similar items")'),
        ('price drop', '(FROM "ebay@ebay.com" SUBJECT "price drop")'),
        ('Daily Deals', '(FROM "ebay@ebay.com" SUBJECT "Daily Deals")'),
        ('Complete purchase', '(FROM "ebay@ebay.com" SUBJECT "Complete your purchase")'),
        ('Still interested', '(FROM "ebay@ebay.com" SUBJECT "Still interested")'),
        ('ending', '(FROM "ebay@ebay.com" SUBJECT "ending")'),
        ('New message', '(FROM "ebay@ebay.com" SUBJECT "New message from")'),
        ('Pay now', '(FROM "ebay@ebay.com" SUBJECT "Pay now")'),
    ]

    notice_ids = set()

    print("  Searching by category...")
    for label, query in notice_searches:
        try:
            status, data = mail.search(None, query)
            if data[0]:
                ids = data[0].split()
                notice_ids.update(ids)
                if len(ids) > 0:
                    print(f"    {label}: {len(ids)}")
        except Exception:
            pass

    if not notice_ids:
        print("  No notice emails to move.")
        return 0

    notice_list = list(notice_ids)
    print(f"  Total: {len(notice_list)} notice emails")

    moved = 0
    if not no_move:
        moved = move_emails(mail, notice_list, NOTICE_FOLDER, 'notice emails', dry_run)
        if not dry_run and moved > 0:
            reselect_inbox(mail)

    print(f"  Result: {moved} moved")
    return moved


# =============================================================================
# Main
# =============================================================================
def main():
    dry_run = '--dry-run' in sys.argv
    no_move = '--no-move' in sys.argv

    # Allow running a specific phase
    phase_arg = None
    for arg in sys.argv:
        if arg.startswith('--phase'):
            if '=' in arg:
                phase_arg = int(arg.split('=')[1])
            else:
                idx = sys.argv.index(arg)
                if idx + 1 < len(sys.argv):
                    phase_arg = int(sys.argv[idx + 1])

    if dry_run:
        print("=== DRY RUN MODE - No changes will be made ===")

    # Connect to database
    print("Connecting to database...")
    db_conn = pymysql.connect(**DB_CONFIG)

    # Connect to Yahoo Mail
    print("Connecting to Yahoo Mail...")
    mail = imaplib.IMAP4_SSL(IMAP_HOST, IMAP_PORT)
    mail.login(YAHOO_EMAIL, YAHOO_APP_PASSWORD)
    print("  Connected!")

    # Ensure folders exist
    for folder in [EBAY_FOLDER, PAYPAL_FOLDER, NOTICE_FOLDER]:
        try:
            mail.create(folder)
        except Exception:
            pass  # Already exists

    mail.select('Inbox')

    # Track totals
    results = {}

    phases = {
        1: ('eBay Orders', phase1_ebay_orders, [mail, db_conn, dry_run, no_move]),
        2: ('PayPal eBay', phase2_paypal_ebay, [mail, db_conn, dry_run, no_move]),
        3: ('PayPal Direct', phase3_paypal_direct, [mail, db_conn, dry_run, no_move]),
        4: ('Delivered', phase4_delivered, [mail, db_conn, dry_run, no_move]),
        5: ('Payouts', phase5_payouts, [mail, db_conn, dry_run, no_move]),
        6: ('PayPal Other', phase6_paypal_other, [mail, db_conn, dry_run, no_move]),
        7: ('Notices', phase7_notices, [mail, dry_run, no_move]),
    }

    if phase_arg:
        if phase_arg in phases:
            label, func, args = phases[phase_arg]
            results[label] = func(*args)
        else:
            print(f"Unknown phase: {phase_arg}")
    else:
        for num in sorted(phases.keys()):
            label, func, args = phases[num]
            results[label] = func(*args)

    # Final summary
    print(f"\n{'='*50}")
    print("Final Summary:")
    for label, result in results.items():
        if isinstance(result, tuple):
            print(f"  {label}: {result}")
        else:
            print(f"  {label}: {result}")
    print(f"{'='*50}")

    mail.logout()
    db_conn.close()


if __name__ == '__main__':
    main()
