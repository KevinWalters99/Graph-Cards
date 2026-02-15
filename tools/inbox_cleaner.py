"""
Inbox Cleaner v3 - Header-fetch approach.
Yahoo IMAP caps folder visibility at 10,000 messages AND caps search results at ~1,000.
Strategy: Fetch ALL message IDs, check headers individually, move non-order emails.
"""
import imaplib
import email
import re
import sys

IMAP_HOST = 'imap.mail.yahoo.com'
IMAP_PORT = 993
YAHOO_EMAIL = 'collinwalters123@yahoo.com'
YAHOO_APP_PASSWORD = 'pjnpsukyleqttwoq'

TARGET_DATE = 'Jan 2025'
BATCH_SIZE = 200
FOLDER_CAP = 9500  # Switch folders before hitting Yahoo's 10k cap


def safe(s):
    return (s or '').encode('ascii', errors='replace').decode('ascii')


def is_order_confirmation(subject):
    """Check if the email subject indicates an eBay order confirmation."""
    subj_lower = (subject or '').lower()
    return 'order confirmed' in subj_lower or 'order is confirmed' in subj_lower


def connect():
    mail = imaplib.IMAP4_SSL(IMAP_HOST, IMAP_PORT)
    mail.login(YAHOO_EMAIL, YAHOO_APP_PASSWORD)
    return mail


def main():
    print('Connecting to Yahoo Mail...', flush=True)
    mail = connect()

    # Create target folders
    target_folders = ['Cleaned-A', 'Cleaned-B', 'Cleaned-C', 'Cleaned-D', 'Cleaned-E']
    for folder in target_folders:
        try:
            mail.create(folder)
        except Exception:
            pass

    target_idx = 0
    target = target_folders[target_idx]
    folder_count = 0
    total_moved = 0
    total_kept = 0
    rounds = 0

    while True:
        rounds += 1

        try:
            mail.select('Inbox')
        except Exception:
            mail = connect()
            mail.select('Inbox')

        # Get ALL message IDs
        s, d = mail.search(None, 'ALL')
        all_ids = d[0].split() if d[0] else []

        if not all_ids:
            print('Inbox empty!', flush=True)
            break

        # Check oldest date
        try:
            s2, d2 = mail.fetch(all_ids[0], '(BODY.PEEK[HEADER.FIELDS (DATE)])')
            msg = email.message_from_bytes(d2[0][1])
            oldest_date = msg.get('Date', '?')[:40]
        except Exception:
            oldest_date = '?'

        if rounds % 5 == 1:
            print('R%d: inbox=%d, oldest=%s, moved=%d, kept=%d, target=%s'
                  % (rounds, len(all_ids), oldest_date, total_moved, total_kept, target), flush=True)

        if TARGET_DATE in oldest_date:
            print('Reached target: %s' % oldest_date, flush=True)
            break

        # Take a batch of IDs from the oldest end (beginning of list)
        batch = all_ids[:BATCH_SIZE]

        # Fetch FROM and SUBJECT headers for the batch
        id_set = b','.join(batch)
        try:
            s3, d3 = mail.fetch(id_set, '(BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)])')
        except Exception as e:
            print('  Fetch error: %s' % safe(str(e)), flush=True)
            try:
                mail = connect()
            except Exception:
                pass
            continue

        # Parse results - d3 contains pairs of (header_info, header_data)
        to_move = []
        to_keep = 0
        idx = 0

        for item in d3:
            if isinstance(item, tuple) and len(item) == 2:
                # Extract the message ID from the response
                header_line = item[0]
                if isinstance(header_line, bytes):
                    # Parse "123 (BODY[HEADER.FIELDS (FROM SUBJECT)] {NNN}"
                    m = re.match(rb'^(\d+)', header_line)
                    if m:
                        msg_id = m.group(1)
                        try:
                            hdr = email.message_from_bytes(item[1])
                            subject = hdr.get('Subject', '')
                            if is_order_confirmation(subject):
                                to_keep += 1
                            else:
                                to_move.append(msg_id)
                        except Exception:
                            to_move.append(msg_id)  # Move if can't parse

        if not to_move:
            if to_keep > 0:
                # All emails in this batch are order confirmations
                # We can't move these - they're what we want to keep
                # But we need to get past them to reach older emails
                total_kept += to_keep
                print('  Batch of %d order confirmations, skipping...' % to_keep, flush=True)

                # If ALL remaining emails are order confirmations, we're done
                if to_keep >= len(batch) and len(all_ids) <= to_keep + total_kept:
                    print('Only order confirmations remain.', flush=True)
                    break
                continue
            else:
                print('  No emails to process in batch', flush=True)
                break

        # Move non-order emails
        move_set = b','.join(to_move)
        try:
            result = mail.copy(move_set, target)
            if result[0] == 'OK':
                mail.store(move_set, '+FLAGS', '(\\Deleted)')
                mail.expunge()
                moved = len(to_move)
                total_moved += moved
                folder_count += moved
            else:
                print('  Copy failed: %s' % str(result), flush=True)
                # Try next folder
                target_idx += 1
                if target_idx < len(target_folders):
                    target = target_folders[target_idx]
                    folder_count = 0
                    print('  Switching to: %s' % target, flush=True)
                else:
                    print('All folders exhausted', flush=True)
                    break
        except Exception as e:
            print('  Move error: %s' % safe(str(e)), flush=True)
            target_idx += 1
            if target_idx < len(target_folders):
                target = target_folders[target_idx]
                folder_count = 0
                print('  Switching to: %s' % target, flush=True)
            else:
                print('All folders exhausted', flush=True)
                break

        # Rotate folder if approaching cap
        if folder_count >= FOLDER_CAP:
            target_idx += 1
            if target_idx < len(target_folders):
                target = target_folders[target_idx]
                folder_count = 0
                print('  Folder cap reached, switching to: %s' % target, flush=True)
            else:
                print('All folders exhausted', flush=True)
                break

        if total_moved >= 200000:
            print('Safety limit reached', flush=True)
            break

    # Final status
    try:
        mail.select('Inbox')
        s, d = mail.search(None, 'ALL')
        final_ids = d[0].split() if d[0] else []
        final_oldest = '?'
        if final_ids:
            s2, d2 = mail.fetch(final_ids[0], '(BODY.PEEK[HEADER.FIELDS (DATE)])')
            msg = email.message_from_bytes(d2[0][1])
            final_oldest = msg.get('Date', '?')[:40]
        print('\nFinal: %d msgs, oldest=%s, moved=%d, kept=%d'
              % (len(final_ids), final_oldest, total_moved, total_kept), flush=True)
    except Exception:
        print('\nTotal moved: %d' % total_moved, flush=True)

    mail.logout()


if __name__ == '__main__':
    main()
