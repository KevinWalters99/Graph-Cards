"""
Card Graph - Fetch sample emails from multiple sources for analysis.
Grabs PayPal receipts, ORDER DELIVERED, payout, You won! emails.
"""
import imaplib
import email
from email.header import decode_header
from html.parser import HTMLParser
import json

IMAP_HOST = 'imap.mail.yahoo.com'
IMAP_PORT = 993
YAHOO_EMAIL = 'collinwalters123@yahoo.com'
YAHOO_APP_PASSWORD = 'pjnpsukyleqttwoq'

class HTMLTextExtractor(HTMLParser):
    def __init__(self):
        super().__init__()
        self.result = []
        self.skip = False
    def handle_starttag(self, tag, attrs):
        if tag in ('style', 'script'):
            self.skip = True
        if tag in ('br', 'p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'td'):
            self.result.append('\n')
    def handle_endtag(self, tag):
        if tag in ('style', 'script'):
            self.skip = False
    def handle_data(self, data):
        if not self.skip:
            self.result.append(data)
    def get_text(self):
        return '\n'.join(self.result)

def html_to_text(html):
    p = HTMLTextExtractor()
    p.feed(html)
    return p.get_text()

def decode_subject(msg):
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

def fetch_samples(mail, query, label, count=2):
    """Fetch sample emails matching query. Save HTML and text versions."""
    print(f"\n{'='*60}")
    print(f"=== {label} ===")
    print(f"Query: {query}")

    status, data = mail.search(None, query)
    if not data[0]:
        print("  No emails found.")
        return

    ids = data[0].split()
    print(f"  Found {len(ids)} emails, fetching last {count}...")

    for eid in ids[-count:]:
        try:
            status, data = mail.fetch(eid, '(RFC822)')
            raw = data[0][1]
            msg = email.message_from_bytes(raw)

            subject = decode_subject(msg)
            from_addr = msg.get('From', '')
            date_str = msg.get('Date', '')
            html = get_body_html(msg)
            text = html_to_text(html) if html else ''

            # Clean text
            lines = [l.strip() for l in text.split('\n') if l.strip()]
            clean_text = '\n'.join(lines)

            safe_subj = subject.encode('ascii', errors='replace').decode('ascii')
            safe_from = from_addr.encode('ascii', errors='replace').decode('ascii')

            print(f"\n  --- Email {eid.decode()} ---")
            print(f"  From:    {safe_from}")
            print(f"  Subject: {safe_subj}")
            print(f"  Date:    {date_str}")
            print(f"  Lines:   {len(lines)}")

            # Save text output
            safe_label = label.lower().replace(' ', '_').replace('/', '_')
            outfile = f"sample_{safe_label}_{eid.decode()}.txt"
            with open(outfile, 'w', encoding='utf-8') as f:
                f.write(f"FROM: {from_addr}\n")
                f.write(f"SUBJECT: {subject}\n")
                f.write(f"DATE: {date_str}\n")
                f.write(f"{'='*60}\n")
                f.write(clean_text)
            print(f"  Saved to {outfile}")

            # Show key lines (first 30)
            for line in lines[:30]:
                safe = line.encode('ascii', errors='replace').decode('ascii')
                print(f"    | {safe[:80]}")
            if len(lines) > 30:
                print(f"    ... ({len(lines) - 30} more lines)")

        except Exception as e:
            safe_err = str(e).encode('ascii', errors='replace').decode('ascii')
            print(f"  Error: {safe_err}")

def main():
    mail = imaplib.IMAP4_SSL(IMAP_HOST, IMAP_PORT)
    mail.login(YAHOO_EMAIL, YAHOO_APP_PASSWORD)
    print("Connected!")
    mail.select('Inbox')

    # 1. PayPal receipt/purchase emails
    fetch_samples(mail, '(FROM "service@paypal.com" SUBJECT "receipt")', 'PayPal Receipt', 2)
    fetch_samples(mail, '(FROM "service@paypal.com" SUBJECT "payment")', 'PayPal Payment', 2)
    fetch_samples(mail, '(FROM "service@paypal.com" SUBJECT "sent")', 'PayPal Sent', 2)
    fetch_samples(mail, '(FROM "service@paypal.com")', 'PayPal All', 1)

    # 2. eBay ORDER DELIVERED
    fetch_samples(mail, '(FROM "ebay@ebay.com" SUBJECT "ORDER DELIVERED")', 'eBay Delivered', 2)

    # 3. eBay payout emails
    fetch_samples(mail, '(FROM "ebay@ebay.com" SUBJECT "payout")', 'eBay Payout', 2)
    fetch_samples(mail, '(FROM "ebay@ebay.com" SUBJECT "We sent")', 'eBay We Sent', 2)

    # 4. You won! (already have these but let's get a fresh sample)
    fetch_samples(mail, '(FROM "ebay@ebay.com" SUBJECT "You won")', 'eBay You Won', 1)

    # 5. Check what PayPal subject patterns exist
    print(f"\n{'='*60}")
    print("=== PayPal Subject Survey (last 30) ===")
    status, data = mail.search(None, '(FROM "paypal.com")')
    if data[0]:
        ids = data[0].split()
        print(f"  Total PayPal emails: {len(ids)}")
        seen = set()
        for eid in ids[-30:]:
            try:
                status2, data2 = mail.fetch(eid, '(BODY.PEEK[HEADER.FIELDS (SUBJECT FROM)])')
                raw = data2[0][1]
                msg = email.message_from_bytes(raw)
                subj = decode_subject(msg)
                from_addr = msg.get('From', '')
                safe = subj[:70].encode('ascii', errors='replace').decode('ascii')
                safe_from = from_addr.encode('ascii', errors='replace').decode('ascii')
                key = safe[:40]
                if key not in seen:
                    seen.add(key)
                    print(f"    [{safe_from[:30]}] {safe}")
            except:
                pass
    else:
        print("  No PayPal emails found.")

    # 6. Check eBay payout/seller subject patterns
    print(f"\n{'='*60}")
    print("=== eBay Payout/Seller Subject Survey ===")
    for query_label, query in [
        ('payout', '(FROM "ebay.com" SUBJECT "payout")'),
        ('We sent', '(FROM "ebay.com" SUBJECT "We sent")'),
        ('funds', '(FROM "ebay.com" SUBJECT "funds")'),
        ('deposit', '(FROM "ebay.com" SUBJECT "deposit")'),
        ('payment', '(FROM "ebay.com" SUBJECT "payment")'),
    ]:
        status, data = mail.search(None, query)
        count = len(data[0].split()) if data[0] else 0
        print(f"  {query_label}: {count}")

    mail.logout()
    print("\nDone!")

if __name__ == '__main__':
    main()
