"""
Card Graph - eBay Email POC v2
Targets actual purchase/order confirmation emails and extracts structured data.
"""
import imaplib
import email
from email.header import decode_header
import re
import json
from html.parser import HTMLParser

IMAP_HOST = 'imap.mail.yahoo.com'
IMAP_PORT = 993
YAHOO_EMAIL = 'collinwalters123@yahoo.com'
YAHOO_APP_PASSWORD = 'pjnpsukyleqttwoq'

class HTMLTextExtractor(HTMLParser):
    """Simple HTML to text converter."""
    def __init__(self):
        super().__init__()
        self.result = []
        self.skip = False

    def handle_starttag(self, tag, attrs):
        if tag in ('style', 'script'):
            self.skip = True
        if tag in ('br', 'p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'h4'):
            self.result.append('\n')

    def handle_endtag(self, tag):
        if tag in ('style', 'script'):
            self.skip = False

    def handle_data(self, data):
        if not self.skip:
            self.result.append(data.strip())

    def get_text(self):
        return ' '.join(self.result)

def html_to_text(html):
    parser = HTMLTextExtractor()
    parser.feed(html)
    return parser.get_text()

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
    """Get HTML body from email."""
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

def main():
    mail = imaplib.IMAP4_SSL(IMAP_HOST, IMAP_PORT)
    mail.login(YAHOO_EMAIL, YAHOO_APP_PASSWORD)
    print("Connected!\n")

    mail.select('Inbox', readonly=True)

    # Search specifically for order confirmation / purchase emails
    searches = {
        'order_confirmed': '(FROM "ebay@ebay.com" SUBJECT "order confirmed")',
        'you_won': '(FROM "ebay@ebay.com" SUBJECT "You won")',
        'you_bought': '(FROM "ebay@ebay.com" SUBJECT "bought")',
        'checkout': '(FROM "ebay@ebay.com" SUBJECT "checkout")',
        'payment': '(FROM "ebay@ebay.com" SUBJECT "payment")',
        'you_paid': '(FROM "ebay@ebay.com" SUBJECT "paid")',
        'receipt': '(FROM "ebay@ebay.com" SUBJECT "receipt")',
        'order_placed': '(FROM "ebay@ebay.com" SUBJECT "order")',
    }

    for name, query in searches.items():
        try:
            status, data = mail.search(None, query)
            count = len(data[0].split()) if data[0] else 0
            print(f"  {name:20s} -> {count} emails")
        except Exception as e:
            print(f"  {name:20s} -> ERROR: {e}")

    # Fetch actual order confirmations - try the most promising queries
    print("\n=== Fetching sample ORDER CONFIRMED emails ===")
    status, data = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "order confirmed")')
    if data[0]:
        ids = data[0].split()
        print(f"Found {len(ids)} 'order confirmed' emails")
        # Get 3 most recent
        for eid in ids[-3:]:
            fetch_and_show(mail, eid)

    # Also try "You won" pattern
    print("\n=== Fetching sample YOU WON emails ===")
    status, data = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "You won")')
    if data[0]:
        ids = data[0].split()
        print(f"Found {len(ids)} 'You won' emails")
        for eid in ids[-2:]:
            fetch_and_show(mail, eid)

    # Try "order" broadly and look at subjects
    print("\n=== Sample subjects from ORDER emails (last 20) ===")
    status, data = mail.search(None, '(FROM "ebay@ebay.com" SUBJECT "order")')
    if data[0]:
        ids = data[0].split()
        # Unique subjects from last 50
        seen_subjects = set()
        for eid in ids[-50:]:
            try:
                status2, data2 = mail.fetch(eid, '(BODY.PEEK[HEADER.FIELDS (SUBJECT)])')
                raw = data2[0][1]
                msg = email.message_from_bytes(raw)
                subj = decode_subject(msg)
                # Normalize: remove item-specific text
                # Keep just the pattern prefix
                prefix = subj[:40].encode('ascii', errors='replace').decode('ascii')
                if prefix not in seen_subjects:
                    seen_subjects.add(prefix)
                    safe = subj.encode('ascii', errors='replace').decode('ascii')
                    print(f"  {safe}")
            except:
                pass

    mail.logout()
    print("\nDone!")

def fetch_and_show(mail, eid):
    """Fetch one email and show parsed content."""
    try:
        status, data = mail.fetch(eid, '(RFC822)')
        raw = data[0][1]
        msg = email.message_from_bytes(raw)

        subject = decode_subject(msg)
        date_str = msg.get('Date', '')
        html_body = get_body_html(msg)
        text = html_to_text(html_body)

        safe_subj = subject.encode('ascii', errors='replace').decode('ascii')
        print(f"\n  Subject: {safe_subj}")
        print(f"  Date:    {date_str}")

        # Try to extract structured data from the text
        # Look for price patterns
        prices = re.findall(r'\$[\d,]+\.\d{2}', text)
        if prices:
            print(f"  Prices found: {prices[:10]}")

        # Look for item number patterns
        item_nums = re.findall(r'\b\d{12,14}\b', text)
        if item_nums:
            print(f"  Item numbers: {list(set(item_nums))[:5]}")

        # Look for order number
        order_nums = re.findall(r'order[# ]*[:\s]*(\d[\d-]+\d)', text, re.IGNORECASE)
        if order_nums:
            print(f"  Order numbers: {order_nums[:3]}")

        # Save the full HTML of the first one for analysis
        outfile = f"ebay_sample_{eid.decode()}.html"
        with open(outfile, 'w', encoding='utf-8') as f:
            f.write(html_body)
        print(f"  Saved HTML to {outfile}")

        # Save text version too
        outfile_txt = f"ebay_sample_{eid.decode()}.txt"
        with open(outfile_txt, 'w', encoding='utf-8') as f:
            f.write(text)
        print(f"  Saved text to {outfile_txt}")

    except Exception as e:
        safe_err = str(e).encode('ascii', errors='replace').decode('ascii')
        print(f"  Error: {safe_err}")

if __name__ == '__main__':
    main()
