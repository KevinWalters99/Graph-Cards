"""
Card Graph - eBay Email IMAP Proof of Concept
Connects to Yahoo Mail via IMAP and fetches eBay order confirmation emails.
"""
import imaplib
import email
from email.header import decode_header
import re
import json
from datetime import datetime

# Yahoo IMAP settings
IMAP_HOST = 'imap.mail.yahoo.com'
IMAP_PORT = 993

# Credentials from .env
YAHOO_EMAIL = 'collinwalters123@yahoo.com'
YAHOO_APP_PASSWORD = 'pjnpsukyleqttwoq'

def connect_imap(email_addr, app_password):
    """Connect to Yahoo IMAP and return the mailbox."""
    print(f"Connecting to {IMAP_HOST}:{IMAP_PORT}...")
    mail = imaplib.IMAP4_SSL(IMAP_HOST, IMAP_PORT)
    mail.login(email_addr, app_password)
    print("Login successful!")
    return mail

def list_folders(mail):
    """List all mailbox folders."""
    status, folders = mail.list()
    print("\n=== Mailbox Folders ===")
    for f in folders:
        print(f"  {f.decode()}")
    return folders

def decode_subject(msg):
    """Decode email subject header."""
    subject = msg.get('Subject', '')
    decoded_parts = decode_header(subject)
    result = ''
    for part, encoding in decoded_parts:
        if isinstance(part, bytes):
            result += part.decode(encoding or 'utf-8', errors='replace')
        else:
            result += part
    return result

def get_body_text(msg):
    """Extract plain text body from email message."""
    body = ''
    if msg.is_multipart():
        for part in msg.walk():
            content_type = part.get_content_type()
            if content_type == 'text/plain':
                payload = part.get_payload(decode=True)
                if payload:
                    charset = part.get_content_charset() or 'utf-8'
                    body += payload.decode(charset, errors='replace')
            elif content_type == 'text/html' and not body:
                payload = part.get_payload(decode=True)
                if payload:
                    charset = part.get_content_charset() or 'utf-8'
                    body = payload.decode(charset, errors='replace')
    else:
        payload = msg.get_payload(decode=True)
        if payload:
            charset = msg.get_content_charset() or 'utf-8'
            body = payload.decode(charset, errors='replace')
    return body

def search_ebay_emails(mail, folder='INBOX', max_results=5):
    """Search for eBay order confirmation emails."""
    mail.select(folder, readonly=True)

    # Search for emails from eBay
    search_queries = [
        '(FROM "ebay@ebay.com")',
        '(FROM "ebay.com" SUBJECT "order")',
        '(FROM "ebay.com" SUBJECT "confirmed")',
        '(FROM "ebay.com" SUBJECT "paid")',
        '(FROM "ebay.com" SUBJECT "purchase")',
        '(FROM "ebay.com")',
    ]

    all_ids = set()
    for query in search_queries:
        try:
            status, data = mail.search(None, query)
            if status == 'OK' and data[0]:
                ids = data[0].split()
                all_ids.update(ids)
                print(f"  Query '{query}' found {len(ids)} emails")
        except Exception as e:
            print(f"  Query '{query}' failed: {e}")

    if not all_ids:
        print("\nNo eBay emails found in this folder.")
        return []

    # Sort by ID (roughly chronological) and take the most recent
    sorted_ids = sorted(all_ids, key=lambda x: int(x), reverse=True)
    fetch_ids = sorted_ids[:max_results]

    print(f"\nFetching {len(fetch_ids)} most recent eBay emails...")

    results = []
    for eid in fetch_ids:
        try:
            status, data = mail.fetch(eid, '(RFC822)')
            if status != 'OK':
                continue

            raw = data[0][1]
            msg = email.message_from_bytes(raw)

            subject = decode_subject(msg)
            from_addr = msg.get('From', '')
            date_str = msg.get('Date', '')
            body = get_body_text(msg)

            result = {
                'id': eid.decode(),
                'from': from_addr,
                'subject': subject,
                'date': date_str,
                'body_preview': body[:500] if body else '(empty)',
                'body_length': len(body),
            }
            results.append(result)

            # Use ascii-safe printing for Windows console
            safe_subject = subject.encode('ascii', errors='replace').decode('ascii')
            safe_from = from_addr.encode('ascii', errors='replace').decode('ascii')
            safe_preview = body[:200].encode('ascii', errors='replace').decode('ascii') if body else '(empty)'

            print(f"\n--- Email #{eid.decode()} ---")
            print(f"  From:    {safe_from}")
            print(f"  Subject: {safe_subject}")
            print(f"  Date:    {date_str}")
            print(f"  Body:    {len(body)} chars")
            print(f"  Preview: {safe_preview}...")

        except Exception as e:
            print(f"  Error fetching email {eid}: {e}")

    return results

def main():
    email_addr = YAHOO_EMAIL

    try:
        mail = connect_imap(email_addr, YAHOO_APP_PASSWORD)

        # List folders first
        list_folders(mail)

        # Search INBOX for eBay emails
        print("\n=== Searching INBOX for eBay emails ===")
        results = search_ebay_emails(mail, 'INBOX', max_results=5)

        if not results:
            # Try other common folders
            for folder in ['"Sent"', '"Archive"', '"[Gmail]/All Mail"']:
                try:
                    print(f"\n=== Trying folder: {folder} ===")
                    results = search_ebay_emails(mail, folder, max_results=3)
                    if results:
                        break
                except Exception as e:
                    print(f"  Could not access {folder}: {e}")

        # Save raw results for analysis
        if results:
            outfile = 'ebay_email_samples.json'
            with open(outfile, 'w', encoding='utf-8') as f:
                json.dump(results, f, indent=2, ensure_ascii=False)
            print(f"\nSaved {len(results)} email samples to {outfile}")

        mail.logout()
        print("\nDone!")

    except imaplib.IMAP4.error as e:
        print(f"IMAP error: {e}")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == '__main__':
    main()
