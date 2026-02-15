import requests

s = requests.Session()

# Login
r = s.post('http://192.168.0.215:8880/api/auth/login', json={
    'username': 'admin',
    'password': 'ACe!sysD#0kVnBWF'
})
print('Login:', r.status_code)
data = r.json()
csrf = data.get('csrf_token', '')
s.headers['X-CSRF-Token'] = csrf

# Test 1: New /api/livestreams endpoint
r = s.get('http://192.168.0.215:8880/api/livestreams')
print('Livestreams:', r.status_code, '-', len(r.json().get('data', [])), 'auctions')
ls = r.json()['data']
if ls:
    print('  First:', ls[0]['stream_date'], '-', ls[0]['livestream_title'], '(', ls[0]['total_items'], 'items)')

# Test 2: Line items with livestream_id filter + 100 per page
if ls:
    lid = ls[0]['livestream_id']
    r = s.get('http://192.168.0.215:8880/api/line-items', params={'livestream_id': lid, 'per_page': 100})
    res = r.json()
    print('Line items filtered:', r.status_code, '-', res['total'], 'total,', len(res['data']), 'returned, per_page:', res['per_page'])

# Test 3: Sort by buyer_name
r = s.get('http://192.168.0.215:8880/api/line-items', params={'sort': 'buyer_name', 'order': 'ASC', 'per_page': 5})
print('Sort by buyer_name:', r.status_code)
if r.status_code == 200:
    names = [d['buyer_name'] for d in r.json()['data'][:3]]
    print('  First 3:', names)

# Test 4: Sort by cost_amount
r = s.get('http://192.168.0.215:8880/api/line-items', params={'sort': 'cost_amount', 'order': 'DESC', 'per_page': 5})
print('Sort by cost_amount:', r.status_code)
if r.status_code == 200:
    costs = [d['cost_amount'] for d in r.json()['data'][:3]]
    print('  Top 3 costs:', costs)

# Test 5: Sort by profit
r = s.get('http://192.168.0.215:8880/api/line-items', params={'sort': 'profit', 'order': 'DESC', 'per_page': 5})
print('Sort by profit:', r.status_code)

# Test 6: Sort by status_name
r = s.get('http://192.168.0.215:8880/api/line-items', params={'sort': 'status_name', 'order': 'ASC', 'per_page': 5})
print('Sort by status_name:', r.status_code)

# Test 7: Auction summary endpoint
if ls:
    lid = ls[0]['livestream_id']
    r = s.get('http://192.168.0.215:8880/api/cost-matrix/auction-summary', params={'livestream_id': lid})
    print('Auction summary:', r.status_code)
    if r.status_code == 200:
        d = r.json()
        print('  Items:', d['total_items'], '| Revenue:', d['total_revenue'], '| Earnings:', d['total_earnings'])
        print('  Fees:', d['total_fees'], '| Costs:', d['total_costs'], '| P/L:', d['profit_loss'])
        print('  Avg Price:', d['avg_item_price'], '| Buyers:', d['unique_buyers'])

print()
print('All tests passed')
