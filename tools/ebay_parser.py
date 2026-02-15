"""
Card Graph - Email Parser Suite
Parses eBay and PayPal emails into structured data for import.
Supports: Order confirmed, You won!, PayPal eBay, PayPal direct,
ORDER DELIVERED, We sent your payout.
"""
import re
from html.parser import HTMLParser


class HTMLTextExtractor(HTMLParser):
    """Extract readable text from HTML email."""
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
    parser = HTMLTextExtractor()
    parser.feed(html)
    return parser.get_text()


def parse_order_confirmed_email(html_body, email_date):
    """
    Parse an eBay 'Order confirmed' email and return structured data.

    Returns dict with:
        order_number: str
        order_date: str (from email date header)
        items: list of dicts with title, price, item_id, seller
        subtotal: float
        shipping: float
        sales_tax: float
        total: float
    """
    text = html_to_text(html_body)

    # Clean up whitespace: collapse multiple newlines and spaces
    lines = []
    for line in text.split('\n'):
        stripped = line.strip()
        if stripped:
            lines.append(stripped)
    text_clean = '\n'.join(lines)

    result = {
        'order_number': None,
        'order_date': email_date,
        'items': [],
        'subtotal': 0.0,
        'shipping': 0.0,
        'sales_tax': 0.0,
        'total': 0.0,
        'reported_item_count': None,
    }

    # Extract items using pattern matching
    # Pattern: lines between item blocks contain Title, Price, Item ID, Order number, Seller
    # We look for "Price:" followed by "$X.XX" pattern

    # Split into lines for sequential parsing
    all_lines = text_clean.split('\n')

    i = 0
    current_item = {}
    while i < len(all_lines):
        line = all_lines[i].strip()

        # Detect price line
        if line.startswith('Price:') or line == 'Price:':
            # The title is typically a few lines before "Price:"
            # Look backwards for the title (non-trivial text that's not a label)
            title = None
            for back in range(1, 10):
                if i - back < 0:
                    break
                candidate = all_lines[i - back].strip()
                # Skip empty, navigation text, short labels
                if (candidate and
                    len(candidate) > 10 and
                    not candidate.startswith(('Price:', 'Item ID:', 'Order number:', 'Seller:',
                                             'View order', 'Browse deals', 'Your order',
                                             'We\'ll let', 'We\'ve got', 'Thanks for',
                                             'Estimated delivery', 'ETA:'))):
                    title = candidate
                    break

            # Get price value - might be on same line or next line
            price_str = line.replace('Price:', '').strip()
            if not price_str and i + 1 < len(all_lines):
                i += 1
                price_str = all_lines[i].strip()
            price = parse_price(price_str)

            current_item = {'title': title, 'price': price, 'item_id': None, 'seller': None}

        # Detect Item ID
        elif line.startswith('Item ID:') or line == 'Item ID:':
            item_id_str = line.replace('Item ID:', '').strip()
            if not item_id_str and i + 1 < len(all_lines):
                i += 1
                item_id_str = all_lines[i].strip()
            if current_item:
                current_item['item_id'] = item_id_str

        # Detect Order number
        elif line.startswith('Order number:') or line == 'Order number:':
            order_str = line.replace('Order number:', '').strip()
            if not order_str and i + 1 < len(all_lines):
                i += 1
                order_str = all_lines[i].strip()
            if not result['order_number']:
                result['order_number'] = order_str

        # Detect Seller
        elif line.startswith('Seller:') or line == 'Seller:':
            seller_str = line.replace('Seller:', '').strip()
            if not seller_str and i + 1 < len(all_lines):
                i += 1
                seller_str = all_lines[i].strip()
            if current_item:
                current_item['seller'] = seller_str
                # Seller is the last field per item - save it
                if current_item.get('title') or current_item.get('item_id'):
                    result['items'].append(current_item.copy())
                current_item = {}

        # Detect order totals - price may be on same line or next line
        # Also extract reported item count from "Subtotal (N items)" pattern
        elif line == 'Subtotal' or line.startswith('Subtotal'):
            count_match = re.search(r'\((\d+)\s+items?\)', line)
            if count_match:
                result['reported_item_count'] = int(count_match.group(1))
            prices = re.findall(r'\$[\d,]+\.\d{2}', line)
            if prices:
                result['subtotal'] = parse_price(prices[0])
            elif i + 1 < len(all_lines):
                next_line = all_lines[i + 1].strip()
                if not count_match:
                    count_match2 = re.search(r'\((\d+)\s+items?\)', next_line)
                    if count_match2:
                        result['reported_item_count'] = int(count_match2.group(1))
                next_prices = re.findall(r'\$[\d,]+\.\d{2}', next_line)
                if next_prices:
                    result['subtotal'] = parse_price(next_prices[0])

        elif line == 'Shipping' or (line.startswith('Shipping') and 'confirmation' not in line):
            prices = re.findall(r'\$[\d,]+\.\d{2}', line)
            if prices:
                result['shipping'] = parse_price(prices[0])
            elif i + 1 < len(all_lines):
                next_prices = re.findall(r'\$[\d,]+\.\d{2}', all_lines[i + 1].strip())
                if next_prices:
                    result['shipping'] = parse_price(next_prices[0])

        elif line == 'Sales tax' or line.startswith('Sales tax'):
            prices = re.findall(r'\$[\d,]+\.\d{2}', line)
            if prices:
                result['sales_tax'] = parse_price(prices[0])
            elif i + 1 < len(all_lines):
                next_prices = re.findall(r'\$[\d,]+\.\d{2}', all_lines[i + 1].strip())
                if next_prices:
                    result['sales_tax'] = parse_price(next_prices[0])

        elif 'Total charged' in line:
            prices = re.findall(r'\$[\d,]+\.\d{2}', line)
            if prices:
                result['total'] = parse_price(prices[0])
            elif i + 1 < len(all_lines):
                next_prices = re.findall(r'\$[\d,]+\.\d{2}', all_lines[i + 1].strip())
                if next_prices:
                    result['total'] = parse_price(next_prices[0])

        i += 1

    return result


def parse_price(s):
    """Parse a price string like '$105.00' or '$2,650.00' to float."""
    if not s:
        return 0.0
    cleaned = re.sub(r'[^\d.]', '', s.replace(',', ''))
    try:
        return float(cleaned)
    except (ValueError, TypeError):
        return 0.0


def parse_you_won_email(html_body, email_date):
    """
    Parse a 'You won!' eBay email.
    Less data than order confirmed - mainly title and winning bid.
    """
    text = html_to_text(html_body)
    lines = [l.strip() for l in text.split('\n') if l.strip()]

    result = {
        'order_number': None,
        'order_date': email_date,
        'items': [],
        'subtotal': 0.0,
        'shipping': 0.0,
        'sales_tax': 0.0,
        'total': 0.0,
        'email_type': 'you_won',
    }

    for i, line in enumerate(lines):
        if 'Your winning bid:' in line:
            price = parse_price(line)
            # Title is usually a few lines before
            title = None
            for back in range(1, 10):
                if i - back < 0:
                    break
                candidate = lines[i - back]
                if (len(candidate) > 15 and
                    not candidate.startswith(('Your winning', 'Congratulations', 'If you',
                                             'Seller is', 'You\'ve committed'))):
                    title = candidate
                    break

            if title or price:
                result['items'].append({
                    'title': title,
                    'price': price,
                    'item_id': None,
                    'seller': None,
                })
                result['subtotal'] = price
                result['total'] = price

    return result


def parse_paypal_ebay_email(html_body, email_date):
    """
    Parse a PayPal 'eBay Commerce Inc.' receipt email.
    These are PayPal-routed eBay purchases. Contains:
      - Total authorized amount
      - PayPal Transaction ID
      - PayPal Order ID (v2_UUID format, different from eBay order number)
      - Ship-to address
    """
    text = html_to_text(html_body)
    lines = [l.strip() for l in text.split('\n') if l.strip()]

    result = {
        'order_number': None,
        'order_date': email_date,
        'items': [],
        'subtotal': 0.0,
        'shipping': 0.0,
        'sales_tax': 0.0,
        'total': 0.0,
        'paypal_transaction_id': None,
        'paypal_order_id': None,
        'source': 'paypal_ebay',
    }

    for i, line in enumerate(lines):
        # Extract amount: "You authorized $574.30 USD to eBay Commerce Inc."
        m = re.search(r'You authorized \$([\d,]+\.\d{2})\s+USD', line)
        if m:
            result['total'] = parse_price(m.group(1))
            result['subtotal'] = result['total']

        # Transaction ID - on the line after "Transaction ID" or "Transaction ID:"
        if line == 'Transaction ID' or line.startswith('Transaction ID:'):
            tid = line.replace('Transaction ID:', '').replace('Transaction ID', '').strip()
            if not tid and i + 1 < len(lines):
                tid = lines[i + 1].strip()
            if tid and len(tid) > 10:
                result['paypal_transaction_id'] = tid

        # Order ID - on the line after "Order ID" or "Order ID:"
        if line == 'Order ID' or line.startswith('Order ID:'):
            oid = line.replace('Order ID:', '').replace('Order ID', '').strip()
            if not oid and i + 1 < len(lines):
                oid = lines[i + 1].strip()
            if oid:
                result['paypal_order_id'] = oid

        # Purchase amount / Qty line for item info
        if line.startswith('Qty:'):
            price = 0.0
            if i + 1 < len(lines):
                price = parse_price(lines[i + 1])
            result['items'].append({
                'title': 'eBay Commerce Inc. Purchase',
                'price': price if price else result['total'],
                'item_id': None,
                'seller': 'eBay Commerce Inc.',
            })

    # If no items from Qty line, create a single item from the total
    if not result['items'] and result['total'] > 0:
        result['items'].append({
            'title': 'eBay Commerce Inc. Purchase',
            'price': result['total'],
            'item_id': None,
            'seller': 'eBay Commerce Inc.',
        })

    # Use PayPal order ID as order_number if available
    if result['paypal_order_id']:
        result['order_number'] = result['paypal_order_id']

    return result


def parse_paypal_direct_email(html_body, email_date):
    """
    Parse a PayPal 'You sent' payment email (non-eBay direct payments).
    Contains: recipient name, amount, Transaction ID.
    """
    text = html_to_text(html_body)
    lines = [l.strip() for l in text.split('\n') if l.strip()]

    result = {
        'order_number': None,
        'order_date': email_date,
        'items': [],
        'subtotal': 0.0,
        'shipping': 0.0,
        'sales_tax': 0.0,
        'total': 0.0,
        'paypal_transaction_id': None,
        'recipient': None,
        'source': 'paypal_direct',
    }

    for i, line in enumerate(lines):
        # "You sent $665.00 USD to Nicholas Sass"
        m = re.search(r'You sent \$([\d,]+\.\d{2})\s+USD to (.+)', line)
        if m:
            result['total'] = parse_price(m.group(1))
            result['subtotal'] = result['total']
            result['recipient'] = m.group(2).strip()

        # Transaction ID
        if line == 'Transaction ID' or line.startswith('Transaction ID:'):
            tid = line.replace('Transaction ID:', '').replace('Transaction ID', '').strip()
            if not tid and i + 1 < len(lines):
                tid = lines[i + 1].strip()
            if tid and len(tid) > 10:
                result['paypal_transaction_id'] = tid

    # Build item entry
    if result['total'] > 0:
        result['items'].append({
            'title': 'PayPal Payment to ' + (result['recipient'] or 'Unknown'),
            'price': result['total'],
            'item_id': None,
            'seller': result['recipient'],
        })
        # Use transaction ID as order number for dedup
        if result['paypal_transaction_id']:
            result['order_number'] = 'PP-' + result['paypal_transaction_id']

    return result


def parse_order_delivered_email(html_body, email_date):
    """
    Parse an eBay 'ORDER DELIVERED' email.
    Contains: item title, Item ID, Order number, Seller, delivery timestamp.
    Used to update delivery_date on existing orders.
    """
    text = html_to_text(html_body)
    lines = [l.strip() for l in text.split('\n') if l.strip()]

    result = {
        'order_number': None,
        'item_id': None,
        'item_title': None,
        'seller': None,
        'delivery_timestamp': None,
    }

    for i, line in enumerate(lines):
        # Delivery timestamp: "Dropped off at Sat, Feb 14 15:15 Local time"
        m = re.search(r'(?:Dropped off at|Delivered)\s+(.+?)\s+Local time', line)
        if m:
            result['delivery_timestamp'] = m.group(1).strip()

        # Item ID - on the line after "Item ID:"
        if line == 'Item ID:' or line.startswith('Item ID:'):
            iid = line.replace('Item ID:', '').strip()
            if not iid and i + 1 < len(lines):
                iid = lines[i + 1].strip()
            if iid and iid.isdigit():
                result['item_id'] = iid

        # Order number - on the line after "Order number:"
        if line == 'Order number:' or line.startswith('Order number:'):
            onum = line.replace('Order number:', '').strip()
            if not onum and i + 1 < len(lines):
                onum = lines[i + 1].strip()
            if onum and re.match(r'\d{2}-\d{5}-\d{5}', onum):
                result['order_number'] = onum

        # Seller - on the line after "Seller:"
        if line == 'Seller:' or line.startswith('Seller:'):
            sel = line.replace('Seller:', '').strip()
            if not sel and i + 1 < len(lines):
                sel = lines[i + 1].strip()
            if sel and sel not in ('Give feedback', 'Popular'):
                result['seller'] = sel

        # Item title is typically the line after "Browse deals"
        if line == 'Browse deals' and i + 1 < len(lines):
            candidate = lines[i + 1].strip()
            if len(candidate) > 10 and not candidate.startswith(('Item ID', 'Order number', 'Seller')):
                result['item_title'] = candidate

    return result


def parse_payout_email(html_body, email_date):
    """
    Parse an eBay 'We sent your payout' email.
    Contains: payout amount, Payout ID, date, bank info.
    """
    text = html_to_text(html_body)
    lines = [l.strip() for l in text.split('\n') if l.strip()]

    result = {
        'payout_amount': 0.0,
        'payout_id': None,
        'payout_date': None,
        'bank_info': None,
        'payout_type': None,
    }

    for i, line in enumerate(lines):
        # Amount: "$51.13 was sent to your bank account"
        m = re.search(r'\$([\d,]+\.\d{2}) was sent to your bank account', line)
        if m:
            result['payout_amount'] = parse_price(m.group(1))

        # Total payout - value on next line
        if line == 'Total payout':
            if i + 1 < len(lines):
                result['payout_amount'] = parse_price(lines[i + 1])

        # Sent to - bank info on next line
        if line == 'Sent to':
            if i + 1 < len(lines):
                result['bank_info'] = lines[i + 1].strip()

        # Payout type on next line
        if line == 'Payout type':
            if i + 1 < len(lines):
                result['payout_type'] = lines[i + 1].strip()

        # Date on next line
        if line == 'Date':
            if i + 1 < len(lines):
                result['payout_date'] = lines[i + 1].strip()

        # Payout ID on next line
        if line == 'Payout ID':
            if i + 1 < len(lines):
                result['payout_id'] = lines[i + 1].strip()

    return result


def parse_delivery_timestamp(ts_str, email_date):
    """
    Convert delivery timestamp string like 'Sat, Feb 14 15:15' into
    a datetime string 'YYYY-MM-DD HH:MM:SS'.
    Uses the year from email_date since delivery timestamps don't include year.
    """
    if not ts_str:
        return None

    year = '2026'
    year_match = re.search(r'(\d{4})', str(email_date))
    if year_match:
        year = year_match.group(1)

    # Parse "Sat, Feb 14 15:15"
    m = re.match(r'\w+,\s+(\w+)\s+(\d+)\s+(\d+):(\d+)', ts_str)
    if m:
        month_name = m.group(1)
        day = m.group(2)
        hour = m.group(3)
        minute = m.group(4)

        months = {
            'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
            'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
            'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12',
        }
        month = months.get(month_name, '01')
        return '%s-%s-%s %s:%s:00' % (year, month, day.zfill(2), hour.zfill(2), minute.zfill(2))

    return None


# === Test all parsers against saved samples ===
if __name__ == '__main__':
    import os

    def safe(s):
        return (s or '').encode('ascii', errors='replace').decode('ascii')

    # Test text-based samples (not HTML - use the .txt files as proxy for structure)
    # In production, parsers receive HTML; here we test the text extraction patterns

    print("=" * 60)
    print("=== Testing PayPal eBay Parser ===")
    for fname in ['sample_paypal_all_9577.txt']:
        path = fname
        if not os.path.exists(path):
            print(f"  Skipping {fname} (not found)")
            continue
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        # Simulate: wrap in basic HTML so html_to_text works
        html = '<html><body>' + content.replace('\n', '<br>') + '</body></html>'
        result = parse_paypal_ebay_email(html, '2026-02-14 07:37:13')
        print(f"  File: {fname}")
        print(f"  Order ID: {result['order_number']}")
        print(f"  Transaction ID: {result['paypal_transaction_id']}")
        print(f"  Total: ${result['total']:.2f}")
        print(f"  Items: {len(result['items'])}")
        for item in result['items']:
            print(f"    - {safe(item['title'])} ${item['price']:.2f}")

    print("\n" + "=" * 60)
    print("=== Testing PayPal Direct Parser ===")
    for fname in ['sample_paypal_sent_9492.txt', 'sample_paypal_sent_9540.txt']:
        path = fname
        if not os.path.exists(path):
            print(f"  Skipping {fname} (not found)")
            continue
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        html = '<html><body>' + content.replace('\n', '<br>') + '</body></html>'
        result = parse_paypal_direct_email(html, '2026-02-13 10:32:26')
        print(f"  File: {fname}")
        print(f"  Recipient: {safe(result.get('recipient', ''))}")
        print(f"  Transaction ID: {result['paypal_transaction_id']}")
        print(f"  Total: ${result['total']:.2f}")
        print(f"  Order #: {result['order_number']}")

    print("\n" + "=" * 60)
    print("=== Testing ORDER DELIVERED Parser ===")
    for fname in ['sample_ebay_delivered_9984.txt', 'sample_ebay_delivered_9985.txt']:
        path = fname
        if not os.path.exists(path):
            print(f"  Skipping {fname} (not found)")
            continue
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        html = '<html><body>' + content.replace('\n', '<br>') + '</body></html>'
        result = parse_order_delivered_email(html, '2026-02-14 14:21:46')
        ts = parse_delivery_timestamp(result['delivery_timestamp'], '2026-02-14')
        print(f"  File: {fname}")
        print(f"  Order #: {result['order_number']}")
        print(f"  Item ID: {result['item_id']}")
        print(f"  Item Title: {safe(result.get('item_title', ''))}")
        print(f"  Seller: {safe(result.get('seller', ''))}")
        print(f"  Delivery: {result['delivery_timestamp']} -> {ts}")

    print("\n" + "=" * 60)
    print("=== Testing Payout Parser ===")
    for fname in ['sample_ebay_payout_3667.txt', 'sample_ebay_payout_9446.txt']:
        path = fname
        if not os.path.exists(path):
            print(f"  Skipping {fname} (not found)")
            continue
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
        html = '<html><body>' + content.replace('\n', '<br>') + '</body></html>'
        result = parse_payout_email(html, '2026-02-13')
        print(f"  File: {fname}")
        print(f"  Amount: ${result['payout_amount']:.2f}")
        print(f"  Payout ID: {result['payout_id']}")
        print(f"  Date: {result['payout_date']}")
        print(f"  Bank: {result['bank_info']}")
        print(f"  Type: {result['payout_type']}")

    print("\nDone!")
